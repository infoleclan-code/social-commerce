import type { QueryClient } from '@tanstack/react-query';

import { ApiConfigError, ApiError, NetworkError } from '@/api/client';
import type { DefaultPaymentMethod, OrderSummary, PaymentStatus, ProductCard } from '@/api/contracts';
import { queryKeys } from '@/api/keys';
import {
  beginPaymentMethodSetup,
  cancelOrder,
  confirmPaymentMethodSetup,
  fetchDefaultPaymentMethod,
  fetchOrder,
  oneTapCheckout,
  syncOrder,
} from '@/api/social-commerce';
import { isBusy, useOneTapStore, type OneTapProblem, type Purchase } from '@/features/one-tap/one-tap-store';
import { authenticatePayment, classifyPayment, releaseHold } from '@/features/payments/complete-payment';
import { rememberPublishableKey, resolvePublishableKey } from '@/features/payments/publishable-key';
import { stripeBridge } from '@/features/payments/stripe-bridge';
import { patchProduct } from '@/features/reels/feed-cache';
import { playError, playRelease, playSuccess } from '@/lib/feedback';
import { newIdempotencyKey } from '@/lib/idempotency';
import { openExternalUrl } from '@/lib/safe-url';

/**
 * The client half of 1-Tap Checkout. The server does the money (backend ScanAndTapService + OrderPayments):
 * this file sequences the steps the app owns and keeps the person informed.
 *
 *   tap ─► POST /checkout/one-tap (Idempotency-Key) ─┬─ affiliate_redirect ─► open the merchant (https only)
 *                                                     ├─ requires_action ─► 3-D Secure (Stripe SDK) ─► sync
 *                                                     ├─ pending ─► sync / poll the order
 *                                                     └─ authorized (undo window) │ captured │ failed │ voided
 *
 * Money rules on the client:
 * - payment_status decides what the app shows; `status` is only a fallback for older API versions.
 * - One Idempotency-Key per tap. The transport retries an unknown outcome (timeout, connection lost, 5xx,
 *   REQUEST_IN_PROGRESS) with that same key, and so does "Try again" after such a failure: the server
 *   answers a repeat with the order it already created, so nothing is charged twice.
 * - A new key after any definite answer. The API stores and replays definite refusals (a 422 such as
 *   PAYMENT_METHOD_REQUIRED would come back forever for the old key), so after adding a card, a decline
 *   or a cancelled authentication the next tap is a new request.
 * - A replayed response never carries a 3-D Secure secret: POST /orders/{id}/sync fetches a fresh one.
 * - The copy never says "not charged" unless the server said so.
 */

/** Order polling after 3-D Secure or an unknown provider outcome: ~25 s in total. */
const POLL_DELAYS_MS = [900, 1_300, 1_800, 2_500, 3_500, 4_500, 5_500, 6_000] as const;
/** The first polls ask Stripe through the API; later ones only read the order (webhooks land there). */
const SYNC_POLLS = 3;
const SETUP_WAIT_DELAYS_MS = [800, 1_500, 2_500] as const;
/** 3-D Secure rounds per attempt before the banner asks the person to continue. */
const MAX_AUTH_ROUNDS = 2;

/** Problems after which the outcome is unknown: "Try again" must resend the same key. */
const REUSE_KEY_AFTER: ReadonlySet<OneTapProblem> = new Set<OneTapProblem>(['network', 'rate_limited', 'provider_unavailable']);

const PROBLEM_BY_CODE: Readonly<Record<string, OneTapProblem>> = {
  PAYMENT_METHOD_REQUIRED: 'payment_method_required',
  SHIPPING_ADDRESS_REQUIRED: 'shipping_address_required',
  NOT_SHIPPABLE: 'not_shippable',
  OUT_OF_STOCK: 'out_of_stock',
  VARIANT_UNAVAILABLE: 'variant_unavailable',
  ONE_TAP_LIMIT: 'limit',
  ORDER_LIMIT: 'limit',
  SPEND_LIMIT: 'limit',
  ACCOUNT_RESTRICTED: 'restricted',
  ACCOUNT_PAUSED: 'restricted',
  ONE_TAP_UNAVAILABLE: 'unavailable',
  PRODUCT_NOT_FOUND: 'unavailable',
  PAYMENT_DECLINED: 'declined',
  PAYMENT_PROVIDER_UNAVAILABLE: 'provider_unavailable',
  PAYMENTS_UNAVAILABLE: 'provider_unavailable',
  RATE_LIMITED: 'rate_limited',
  ORDER_NOT_CANCELLABLE: 'not_cancellable',
  NOT_CANCELLABLE: 'not_cancellable',
  IDEMPOTENCY_KEY_REUSED: 'key_reused',
  // The twin request is still running on the server: same as a lost answer, retry with the same key.
  REQUEST_IN_PROGRESS: 'network',
  SETUP_NOT_FOUND: 'setup_failed',
  PAYMENT_METHOD_INVALID: 'setup_failed',
};

const windowTimers = new Map<string, ReturnType<typeof setTimeout>>();

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

export function problemOf(error: unknown): OneTapProblem {
  if (error instanceof NetworkError) {
    return 'network';
  }
  if (error instanceof ApiConfigError) {
    return 'unavailable';
  }
  if (error instanceof ApiError) {
    if (error.status === 401) {
      return 'signed_out';
    }
    if (error.status === 429) {
      return 'rate_limited';
    }
    const mapped = error.code ? PROBLEM_BY_CODE[error.code] : undefined;
    if (mapped) {
      return mapped;
    }
    // An unexplained 5xx may have happened after the order was placed: treat it as a lost answer.
    return error.status >= 500 ? 'network' : 'unknown';
  }
  // Not an HTTP refusal (e.g. a success body the app could not read): the outcome is unknown.
  return 'network';
}

/** payment_status, or the closest equivalent from `status` when talking to an older API. */
function paymentStatusOf(order: OrderSummary): PaymentStatus {
  if (order.payment_status) {
    return order.payment_status;
  }
  switch (order.status) {
    case 'completed':
      return 'captured';
    case 'failed':
      return 'failed';
    case 'cancelled':
    case 'refunded':
      return 'voided';
    case 'pending':
      return 'requires_action';
    default:
      return order.undo_until ? 'authorized' : 'pending';
  }
}

function current(productId: string, attempt: number): Purchase | null {
  const purchase = useOneTapStore.getState().purchases[productId];
  return purchase && purchase.attempt === attempt ? purchase : null;
}

function update(productId: string, attempt: number, patch: Parameters<ReturnType<typeof useOneTapStore.getState>['update']>[2]): boolean {
  // The 3-D Secure client secret is used once, right away, and never kept in app state.
  const safe = patch.order?.next_action ? { ...patch, order: { ...patch.order, next_action: null } } : patch;
  return useOneTapStore.getState().update(productId, attempt, safe);
}

function clearWindowTimer(productId: string): void {
  const timer = windowTimers.get(productId);
  if (timer) {
    clearTimeout(timer);
    windowTimers.delete(productId);
  }
}

// ------------------------------------------------------------------------------------------ entry points

/**
 * Starts a purchase from a buy button. A second tap while one is running only refocuses the banner.
 * Affiliate products go through the same endpoint, which answers with a tracked merchant link.
 */
export function startOneTap(queryClient: QueryClient, product: ProductCard, variantSku: string | null, quantity = 1, expectedTotalMinor: number | null = null): void {
  const store = useOneTapStore.getState();
  const existing = store.purchases[product.id];
  if (isBusy(existing)) {
    store.focus(product.id);
    return;
  }
  clearWindowTimer(product.id);

  const purchase: Purchase = {
    productId: product.id,
    title: product.title,
    merchantName: product.merchant.name,
    params: { productId: product.id, variantSku, quantity, expectedTotalMinor },
    key: newIdempotencyKey(),
    attempt: (existing?.attempt ?? 0) + 1,
    phase: 'submitting',
    order: null,
    problem: null,
    declineCode: null,
    updatedAt: Date.now(),
  };
  store.put(purchase);

  // Known to have no saved card: open the card sheet first instead of sending a tap that will be refused.
  const method = queryClient.getQueryData<DefaultPaymentMethod>(queryKeys.oneTap.paymentMethod);
  if (product.purchase.mode === 'one_tap' && method && !method.has_default && stripeBridge.supported) {
    void saveCardThenBuy(queryClient, product.id, purchase.attempt);
    return;
  }
  void submit(queryClient, product.id, purchase.attempt);
}

/** "Try again" / "Add card" / "Continue" in the banner, depending on why the last attempt stopped. */
export function retryOneTap(queryClient: QueryClient, productId: string): void {
  const store = useOneTapStore.getState();
  const previous = store.purchases[productId];
  if (!previous || isBusy(previous)) {
    return;
  }
  clearWindowTimer(productId);

  if ((previous.problem === 'still_processing' || previous.problem === 'auth_required') && previous.order) {
    // The order exists and is undecided: follow it (sync returns a fresh 3-D Secure step if one is open).
    const orderId = previous.order.id;
    store.update(productId, previous.attempt, { phase: 'confirming', problem: null });
    store.focus(productId);
    void resume(queryClient, productId, previous.attempt, orderId);
    return;
  }

  const reuse = previous.problem !== null && REUSE_KEY_AFTER.has(previous.problem) && previous.phase === 'failed';
  const next: Purchase = {
    ...previous,
    key: reuse ? previous.key : newIdempotencyKey(),
    attempt: previous.attempt + 1,
    phase: 'submitting',
    order: reuse ? previous.order : null,
    problem: null,
    declineCode: null,
    updatedAt: Date.now(),
  };
  store.put(next);

  if (previous.problem === 'payment_method_required' || previous.problem === 'setup_failed') {
    void saveCardThenBuy(queryClient, productId, next.attempt);
    return;
  }
  void submit(queryClient, productId, next.attempt);
}

/** Undo inside the window: the authorization is cancelled, nothing is charged, the stock goes back. */
export async function undoOneTap(queryClient: QueryClient, productId: string): Promise<void> {
  const purchase = useOneTapStore.getState().purchases[productId];
  if (!purchase || purchase.phase !== 'authorized' || !purchase.order) {
    return;
  }
  const { attempt } = purchase;
  const orderId = purchase.order.id;
  clearWindowTimer(productId);
  update(productId, attempt, { phase: 'cancelling', problem: null });

  try {
    // One undo intent per purchase: a retry after a lost answer replays the first undo's result.
    const order = await cancelOrder(orderId, `undo-${purchase.key}`.slice(0, 80));
    await settle(queryClient, productId, attempt, order);
  } catch (error) {
    if (problemOf(error) === 'not_cancellable') {
      // Either captured in the meantime (the window had just closed) or already cancelled by an earlier
      // undo whose answer was lost: show the real state.
      try {
        const order = await fetchOrder(orderId);
        if (classifyPayment(paymentStatusOf(order)) === 'cancelled') {
          await settle(queryClient, productId, attempt, order);
          return;
        }
        update(productId, attempt, { phase: 'completed', order, problem: 'not_cancellable' });
      } catch {
        update(productId, attempt, { phase: 'completed', problem: 'not_cancellable' });
      }
      playError();
      return;
    }
    // The undo may not have reached the server: stay in the window and let the person try again.
    if (update(productId, attempt, { phase: 'authorized', problem: 'undo_failed' })) {
      scheduleWindowEnd(productId, attempt, purchase.order);
    }
    playError();
  } finally {
    void queryClient.invalidateQueries({ queryKey: queryKeys.orders.all });
  }
}

// ------------------------------------------------------------------------------------------------ steps

async function submit(queryClient: QueryClient, productId: string, attempt: number): Promise<void> {
  const purchase = current(productId, attempt);
  if (!purchase) {
    return;
  }
  try {
    const response = await oneTapCheckout(purchase.params, purchase.key);
    if (!current(productId, attempt)) {
      return;
    }
    if (response.mode === 'affiliate_redirect') {
      update(productId, attempt, { phase: 'redirected' });
      if (useOneTapStore.getState().focusId === productId) {
        useOneTapStore.getState().dismiss();
      }
      if (!(await openExternalUrl(response.redirect_url))) {
        failWith(productId, attempt, 'unavailable', null);
      }
      return;
    }
    const order = response.next_action ? { ...response.order, next_action: response.next_action } : response.order;
    await settle(queryClient, productId, attempt, order);
  } catch (error) {
    fail(queryClient, productId, attempt, error);
  }
}

/** Runs 3-D Secure, then asks the server for the result instead of waiting for the webhook. */
async function authenticate(queryClient: QueryClient, productId: string, attempt: number, order: OrderSummary, clientSecret: string, round: number): Promise<void> {
  update(productId, attempt, { phase: 'authenticating', order });

  const outcome = await authenticatePayment({ type: 'use_stripe_sdk', client_secret: clientSecret });
  if (!current(productId, attempt)) {
    return;
  }

  switch (outcome.status) {
    case 'completed': {
      update(productId, attempt, { phase: 'confirming' });
      try {
        await settle(queryClient, productId, attempt, await syncOrder(order.id), round + 1);
      } catch {
        await poll(queryClient, productId, attempt, order.id, round + 1);
      }
      return;
    }
    case 'canceled':
      await releaseHold(order.id);
      failWith(productId, attempt, 'auth_canceled', null);
      return;
    case 'failed':
      await releaseHold(order.id);
      failWith(productId, attempt, 'auth_failed', outcome.code);
      return;
    case 'unsupported':
      await releaseHold(order.id);
      failWith(productId, attempt, 'unsupported', null);
      return;
  }
}

/** "Continue" / "Check again": fetch the order's state from Stripe through the API and carry on. */
async function resume(queryClient: QueryClient, productId: string, attempt: number, orderId: string): Promise<void> {
  try {
    await settle(queryClient, productId, attempt, await syncOrder(orderId));
  } catch (error) {
    if (problemOf(error) === 'signed_out') {
      fail(queryClient, productId, attempt, error);
      return;
    }
    await poll(queryClient, productId, attempt, orderId, 0);
  }
}

/** Follows the order until the provider's answer is in, then settles it. */
async function poll(queryClient: QueryClient, productId: string, attempt: number, orderId: string, round: number): Promise<void> {
  for (let index = 0; index < POLL_DELAYS_MS.length; index += 1) {
    await sleep(POLL_DELAYS_MS[index]);
    if (!current(productId, attempt)) {
      return;
    }
    try {
      const order = index < SYNC_POLLS ? await syncOrder(orderId) : await fetchOrder(orderId);
      if (classifyPayment(paymentStatusOf(order)) !== 'undecided') {
        await settle(queryClient, productId, attempt, order, round);
        return;
      }
      update(productId, attempt, { order });
    } catch (error) {
      if (problemOf(error) === 'signed_out') {
        fail(queryClient, productId, attempt, error);
        return;
      }
      // Transient: keep polling.
    }
  }
  failWith(productId, attempt, 'still_processing', null);
}

async function settle(queryClient: QueryClient, productId: string, attempt: number, order: OrderSummary, round = 0): Promise<void> {
  void queryClient.invalidateQueries({ queryKey: queryKeys.orders.all });

  switch (classifyPayment(paymentStatusOf(order))) {
    case 'held': {
      const undoUntil = order.undo_until ? Date.parse(order.undo_until) : Number.NaN;
      if (Number.isFinite(undoUntil) && undoUntil > Date.now()) {
        if (update(productId, attempt, { phase: 'authorized', order, problem: null })) {
          playSuccess();
          scheduleWindowEnd(productId, attempt, order);
        }
        return;
      }
      // Window already over; the server captures it shortly.
      if (update(productId, attempt, { phase: 'completed', order, problem: null })) {
        playSuccess();
      }
      return;
    }
    case 'paid':
      if (update(productId, attempt, { phase: 'completed', order, problem: null })) {
        playSuccess();
      }
      return;
    case 'declined':
      failWith(productId, attempt, 'declined', order.failure_code, order);
      return;
    case 'cancelled':
      if (update(productId, attempt, { phase: 'cancelled', order, problem: null })) {
        playRelease();
      }
      return;
    case 'action': {
      if (round >= MAX_AUTH_ROUNDS) {
        failWith(productId, attempt, 'auth_required', null, order);
        return;
      }
      let secret = order.next_action?.client_secret ?? null;
      if (!secret) {
        // A replay (or GET) never carries the secret: sync asks Stripe for a fresh one.
        try {
          const fresh = await syncOrder(order.id);
          if (classifyPayment(paymentStatusOf(fresh)) !== 'action') {
            await settle(queryClient, productId, attempt, fresh, round);
            return;
          }
          secret = fresh.next_action?.client_secret ?? null;
        } catch {
          secret = null;
        }
      }
      if (!secret) {
        failWith(productId, attempt, 'auth_required', null, order);
        return;
      }
      await authenticate(queryClient, productId, attempt, order, secret, round);
      return;
    }
    case 'undecided':
      update(productId, attempt, { phase: 'confirming', order });
      await poll(queryClient, productId, attempt, order.id, round);
      return;
  }
}

/** When the undo window closes the order stands; the server captures it. No request needed. */
function scheduleWindowEnd(productId: string, attempt: number, order: OrderSummary): void {
  clearWindowTimer(productId);
  const until = order.undo_until ? Date.parse(order.undo_until) : Number.NaN;
  if (!Number.isFinite(until)) {
    return;
  }
  const timer = setTimeout(() => {
    windowTimers.delete(productId);
    const purchase = current(productId, attempt);
    if (purchase?.phase === 'authorized') {
      update(productId, attempt, { phase: 'completed', problem: null });
    }
  }, Math.max(0, until - Date.now()) + 250);
  windowTimers.set(productId, timer);
}

async function saveCardThenBuy(queryClient: QueryClient, productId: string, attempt: number): Promise<void> {
  if (!stripeBridge.supported) {
    // No PaymentSheet on this platform (web): do not create a SetupIntent that can never be completed.
    failWith(productId, attempt, 'unsupported', null);
    return;
  }
  update(productId, attempt, { phase: 'saving_card', problem: null });
  try {
    // One key per "Add card": the transport's retries reuse it; a new sheet is a new SetupIntent.
    const setupKey = newIdempotencyKey('setup');
    const setup = await beginPaymentMethodSetup(setupKey);
    rememberPublishableKey(setup.publishable_key);
    const publishableKey = setup.publishable_key ?? (await resolvePublishableKey());
    if (!publishableKey) {
      failWith(productId, attempt, 'setup_failed', 'stripe_not_configured');
      return;
    }
    if (!setup.client_secret) {
      // Only a replayed answer lacks the secret; a fresh key never gets one.
      failWith(productId, attempt, 'setup_failed', null);
      return;
    }

    const outcome = await stripeBridge.presentCardSetup({
      publishableKey,
      setupIntentClientSecret: setup.client_secret,
    });
    if (!current(productId, attempt)) {
      return;
    }
    if (outcome.status === 'canceled') {
      failWith(productId, attempt, 'payment_method_required', null, null, false);
      return;
    }
    if (outcome.status === 'unsupported') {
      failWith(productId, attempt, 'unsupported', null);
      return;
    }
    if (outcome.status === 'failed') {
      failWith(productId, attempt, 'setup_failed', outcome.code);
      return;
    }

    const confirmed = await confirmPaymentMethodSetup(outcome.setupIntentId, `confirm-${setupKey}`.slice(0, 80));
    queryClient.setQueryData<DefaultPaymentMethod>(queryKeys.oneTap.paymentMethod, {
      has_default: confirmed.has_default,
      summary: confirmed.summary,
      publishable_key: publishableKey,
    });
    if (!confirmed.has_default && !(await waitForSavedMethod(queryClient))) {
      failWith(productId, attempt, 'setup_failed', confirmed.setup_status ?? null);
      return;
    }

    // The tap that found no card was refused, and the API replays refusals for that key: buy with a new one.
    if (update(productId, attempt, { phase: 'submitting', key: newIdempotencyKey() })) {
      await submit(queryClient, productId, attempt);
    }
  } catch (error) {
    fail(queryClient, productId, attempt, error);
  }
}

/** A setup Stripe is still processing: ask again a few times before giving up. */
async function waitForSavedMethod(queryClient: QueryClient): Promise<boolean> {
  for (const delay of SETUP_WAIT_DELAYS_MS) {
    await sleep(delay);
    try {
      const method = await fetchDefaultPaymentMethod();
      queryClient.setQueryData(queryKeys.oneTap.paymentMethod, method);
      if (method.has_default) {
        return true;
      }
    } catch {
      // keep waiting
    }
  }
  return false;
}

// ------------------------------------------------------------------------------------------- failure

function fail(queryClient: QueryClient, productId: string, attempt: number, error: unknown): void {
  const problem = problemOf(error);
  const declineCode = error instanceof ApiError && typeof error.meta.decline_code === 'string' ? error.meta.decline_code : null;

  if (problem === 'out_of_stock') {
    patchProduct(queryClient, productId, (product) => ({ ...product, purchase: { mode: 'unavailable', in_stock: false } }));
  }
  if (problem === 'payment_method_required') {
    queryClient.setQueryData<DefaultPaymentMethod>(queryKeys.oneTap.paymentMethod, (previous) => ({
      has_default: false,
      summary: null,
      publishable_key: previous?.publishable_key ?? null,
    }));
  }
  failWith(productId, attempt, problem, declineCode);
}

function failWith(
  productId: string,
  attempt: number,
  problem: OneTapProblem,
  declineCode: string | null,
  order: OrderSummary | null = null,
  buzz = true,
): void {
  const patch = order ? { phase: 'failed' as const, problem, declineCode, order } : { phase: 'failed' as const, problem, declineCode };
  if (update(productId, attempt, patch) && buzz) {
    playError();
  }
}
