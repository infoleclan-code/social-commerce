import { ApiError } from '@/api/client';
import type { NextAction, OrderSummary, PaymentStatus, PaymentStep } from '@/api/contracts';
import { cancelOrder, fetchOrder, syncOrder } from '@/api/social-commerce';
import { resolvePublishableKey } from '@/features/payments/publishable-key';
import { stripeBridge } from '@/features/payments/stripe-bridge';
import type { NextActionOutcome } from '@/features/payments/types';
import { newIdempotencyKey } from '@/lib/idempotency';

/**
 * The client half of every card purchase after the API answered (checkout, squad, gift, blind box;
 * 1-Tap runs the same rules inside its own state machine). The server owns the money; the app only:
 *
 *   requires_action ─► 3-D Secure in the Stripe SDK ─► POST /orders/{id}/sync ─┐
 *   pending/unknown ─► sync, then poll GET /orders/{id} ────────────────────────┤
 *   authorized / captured / failed / voided / refunded ◄────────────────────────┘ (decided)
 *
 * Rules: never re-buy while the answer is undecided (that is what sync and polling are for); a 3-D Secure
 * step the buyer cancels or fails releases the hold right away (POST /orders/{id}/cancel), and if that
 * request is lost the server's reconciler voids abandoned authentications anyway.
 */

export type PaymentPhase = 'held' | 'paid' | 'declined' | 'cancelled' | 'action' | 'undecided';

export function classifyPayment(status: PaymentStatus | null | undefined): PaymentPhase {
  switch (status) {
    case 'authorized':
      return 'held';
    case 'captured':
    case 'partially_refunded':
    case 'disputed':
      return 'paid';
    case 'failed':
      return 'declined';
    case 'voided':
    case 'refunded':
      return 'cancelled';
    case 'requires_action':
      return 'action';
    default:
      // pending, unknown, unpaid, or a status this build does not know yet: ask the server again.
      return 'undecided';
  }
}

export type PaymentResolution =
  | { kind: 'held' | 'paid' | 'cancelled'; order: OrderSummary | null }
  | { kind: 'declined'; code: string | null; order: OrderSummary | null }
  /** The buyer closed the bank's page, or the bank refused the authentication: nothing is charged. */
  | { kind: 'auth_canceled' }
  | { kind: 'auth_failed'; code: string | null }
  /** No Stripe SDK on this platform (web): finish in the mobile app. The hold was released. */
  | { kind: 'unsupported' }
  /** Still undecided after ~25 s. The order updates in Closet; buying again would be a second order. */
  | { kind: 'processing'; order: OrderSummary | null };

type Current = PaymentStep & { order: OrderSummary | null };

const POLL_DELAYS_MS = [800, 1_200, 1_800, 2_500, 3_500, 4_500, 5_500, 6_000] as const;
/** The first few polls ask Stripe through the API (sync); later ones read the order (webhooks land there). */
const SYNC_POLLS = 3;
const MAX_AUTHENTICATIONS = 2;

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

function fromOrder(order: OrderSummary): Current {
  return {
    payment_status: order.payment_status ?? 'unknown',
    next_action: order.next_action ?? null,
    failure_code: order.failure_code,
    order,
  };
}

/** Runs Stripe's next action (3-D Secure) for a PaymentIntent the server already confirmed. */
export async function authenticatePayment(nextAction: NextAction): Promise<NextActionOutcome> {
  const publishableKey = await resolvePublishableKey();
  if (!publishableKey) {
    return { status: 'failed', code: 'stripe_not_configured' };
  }
  return stripeBridge.handlePaymentNextAction(publishableKey, nextAction.client_secret);
}

/** Releases the hold of an abandoned purchase. Best effort: the server voids abandoned ones too. */
export async function releaseHold(orderId: string): Promise<void> {
  try {
    await cancelOrder(orderId, newIdempotencyKey('release'));
  } catch {
    // Already decided, or unreachable: the reconciler cancels abandoned authentications.
  }
}

export type CompleteOptions = {
  /** Called when the bank's page is about to open, and when the app starts waiting for the answer. */
  onStage?: (stage: 'authenticating' | 'confirming') => void;
};

/**
 * Follows a purchase response until its payment is decided. `step` is the response's payment fields;
 * `order` is the order summary when the endpoint returned one. Bounded: at most two 3-D Secure rounds,
 * two secret refreshes and eight polls (~25 s), then `processing`.
 */
export async function completePayment(
  orderId: string,
  step: PaymentStep,
  order: OrderSummary | null = null,
  options: CompleteOptions = {},
): Promise<PaymentResolution> {
  let current: Current = { ...step, order };
  let authentications = 0;
  let refreshes = 0;
  let polls = 0;

  /** The order from the server (sync asks Stripe first). Null on a transient failure. */
  const refresh = async (viaSync: boolean): Promise<Current | null> => {
    try {
      return fromOrder(viaSync ? await syncOrder(orderId) : await fetchOrder(orderId));
    } catch (error) {
      if (error instanceof ApiError && (error.status === 401 || error.status === 404)) {
        throw error;
      }
      return null;
    }
  };

  for (;;) {
    const phase = classifyPayment(current.payment_status);
    if (phase === 'held' || phase === 'paid' || phase === 'cancelled') {
      return { kind: phase, order: current.order };
    }
    if (phase === 'declined') {
      return { kind: 'declined', code: current.failure_code, order: current.order };
    }

    if (phase === 'action' && authentications < MAX_AUTHENTICATIONS) {
      const nextAction = current.next_action;
      if (nextAction) {
        authentications += 1;
        options.onStage?.('authenticating');
        const outcome = await authenticatePayment(nextAction);
        if (outcome.status !== 'completed') {
          await releaseHold(orderId);
          if (outcome.status === 'canceled') {
            return { kind: 'auth_canceled' };
          }
          return outcome.status === 'unsupported' ? { kind: 'unsupported' } : { kind: 'auth_failed', code: outcome.code };
        }
        options.onStage?.('confirming');
        current = (await refresh(true)) ?? { ...current, payment_status: 'pending', next_action: null };
        continue;
      }
      if (refreshes < MAX_AUTHENTICATIONS) {
        // A replayed response never carries the secret: sync fetches a fresh one from Stripe.
        refreshes += 1;
        const fresh = await refresh(true);
        if (fresh) {
          current = fresh;
          continue;
        }
      }
    }

    // Undecided (or 3-D Secure that could not be started): wait for the provider's answer.
    const delay = POLL_DELAYS_MS[polls];
    if (delay === undefined) {
      return { kind: 'processing', order: current.order };
    }
    options.onStage?.('confirming');
    await sleep(delay);
    current = (await refresh(polls < SYNC_POLLS)) ?? current;
    polls += 1;
  }
}
