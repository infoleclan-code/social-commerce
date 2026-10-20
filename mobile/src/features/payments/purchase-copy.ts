import { ApiConfigError, ApiError, NetworkError } from '@/api/client';
import type { PaymentResolution } from '@/features/payments/complete-payment';
import { t, type MessageKey } from '@/lib/i18n';
import { formatMoney, type DisplayMoney } from '@/lib/money';

/**
 * One line of copy for the end of a purchase. Chosen from the API's stable error codes and payment
 * outcomes, never from a server `message` (which is English, may change, and is not written for buyers).
 * "Not charged" appears only where the server's answer guarantees it.
 */

const COPY_BY_CODE: Readonly<Record<string, MessageKey>> = {
  OUT_OF_STOCK: 'purchase.soldOut',
  PAYMENT_METHOD_REQUIRED: 'purchase.cardRequired',
  SPEND_LIMIT: 'purchase.limit',
  ORDER_LIMIT: 'purchase.limit',
  ACCOUNT_PAUSED: 'purchase.paused',
  COUPON_INVALID: 'purchase.couponInvalid',
  SQUAD_CLOSED: 'purchase.squadClosed',
  SQUAD_NOT_FOUND: 'purchase.squadClosed',
  BLIND_BOX_PULLED: 'purchase.blindBoxPulled',
  AGE_ASSURANCE_REQUIRED: 'purchase.ageRequired',
  PAYMENTS_UNAVAILABLE: 'purchase.unavailable',
  REQUEST_IN_PROGRESS: 'purchase.inProgress',
};

export function purchaseErrorCopy(error: unknown): string {
  if (error instanceof NetworkError) {
    return t('purchase.network');
  }
  if (error instanceof ApiConfigError) {
    return t('purchase.unavailable');
  }
  if (error instanceof ApiError) {
    if (error.status === 401) {
      return t('purchase.signedOut');
    }
    if (error.status === 429) {
      return t('purchase.rateLimited');
    }
    const key = error.code ? COPY_BY_CODE[error.code] : undefined;
    if (key) {
      return t(key);
    }
    // An unexplained 5xx may have happened after the order was placed: never claim "not charged".
    return error.status >= 500 ? t('purchase.network') : t('shop.failed');
  }
  return t('shop.failed');
}

/** The new total from a 409 PRICE_CHANGED, or null for any other error. */
export function changedPrice(error: unknown): DisplayMoney | null {
  if (!(error instanceof ApiError) || error.code !== 'PRICE_CHANGED') {
    return null;
  }
  const total = error.meta.total;
  if (!total || typeof total !== 'object') {
    return null;
  }
  const { amount_minor: amount, currency, minor_unit: minor } = total as Record<string, unknown>;
  if (typeof amount !== 'number' || !Number.isSafeInteger(amount) || amount < 0 || typeof currency !== 'string' || currency.length !== 3) {
    return null;
  }
  return { amount_minor: amount, currency, minor_unit: typeof minor === 'number' && Number.isInteger(minor) && minor >= 0 ? minor : 2 };
}

export function resolutionCopy(resolution: PaymentResolution, total: DisplayMoney | null): string {
  const amount = total ? formatMoney(total) : '';
  switch (resolution.kind) {
    case 'held':
      return t('purchase.held', { total: amount });
    case 'paid':
      return t('purchase.paid', { total: amount });
    case 'declined':
      return t('purchase.declined');
    case 'cancelled':
      return t('purchase.cancelled');
    case 'auth_canceled':
      return t('purchase.authCanceled');
    case 'auth_failed':
      return t('purchase.authFailed');
    case 'unsupported':
      return t('purchase.unsupported');
    case 'processing':
      return t('purchase.processing');
  }
}

/** True when the purchase reached a definite end: the next tap is a new purchase with a new key. */
export function isDefinite(resolution: PaymentResolution): boolean {
  return resolution.kind !== 'processing';
}

/**
 * True when a failed request definitely did not go through, so the next tap may use a new key.
 * Unknown outcomes (connection lost, timeout, 5xx, still in progress) keep the key: the retry replays.
 */
export function isDefiniteFailure(error: unknown): boolean {
  if (error instanceof ApiConfigError) {
    return true;
  }
  if (error instanceof ApiError) {
    return error.status < 500 && error.status !== 408 && error.status !== 425 && error.status !== 429 && error.code !== 'REQUEST_IN_PROGRESS';
  }
  // NetworkError, or a success answer the app could not use (a malformed body): the server may have
  // acted, so the next tap replays with the same key instead of buying again.
  return false;
}
