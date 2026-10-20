import { demoMoneyEnabled } from '@/lib/demo-mode';
import { newIdempotencyKey } from '@/lib/idempotency';
import { t } from '@/lib/i18n';
import type { DisplayMoney } from '@/lib/money';
import { useWalletStore } from '@/stores/wallet';

/**
 * Wallet funding and withdrawal seam.
 *
 * Only the demo gateway ships today: it moves the session wallet so the flows can be exercised end
 * to end, and the UI labels it as demo. A live gateway has to:
 *   1. create the top-up on the server with this idempotency key and get a PaymentSheet config,
 *   2. present Stripe's PaymentSheet (@stripe/stripe-react-native) with that config,
 *   3. show the new balance only after the webhook has posted it to the ledger.
 * It must never credit the balance on the device. Holding customer funds is regulated (e-money or
 * money transmission, depending on the market), so live top-ups wait on that decision as well.
 */

export type FundingMethod = 'apple_pay' | 'card';

export type MoneyResult = { status: 'succeeded' } | { status: 'failed'; reason: 'insufficient' | 'duplicate' | 'invalid' | 'unavailable' };

export const fundingMode = 'demo' as const;

/** Demo-only processing time, so the pending state is visible. */
const DEMO_PROCESSING_MS = 900;

/** Top-up bounds in minor units of the wallet currency (demo limits; the server owns real ones). */
export const TOP_UP_LIMITS = { min: 500, max: 100_000 } as const;

function wait(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

export function newMoneyKey(kind: 'topup' | 'withdraw'): string {
  return newIdempotencyKey(`wallet-${kind}`);
}

export async function submitTopUp(amount: DisplayMoney, method: FundingMethod, idempotencyKey: string): Promise<MoneyResult> {
  if (!demoMoneyEnabled) {
    return { status: 'failed', reason: 'unavailable' };
  }
  if (amount.amount_minor < TOP_UP_LIMITS.min || amount.amount_minor > TOP_UP_LIMITS.max) {
    return { status: 'failed', reason: 'invalid' };
  }
  await wait(DEMO_PROCESSING_MS);
  const result = useWalletStore.getState().credit(idempotencyKey, amount, { title: t('wallet.topUp'), paymentMethod: method });
  return result === 'ok' ? { status: 'succeeded' } : { status: 'failed', reason: 'duplicate' };
}

export async function submitWithdrawal(amount: DisplayMoney, idempotencyKey: string): Promise<MoneyResult> {
  if (!demoMoneyEnabled) {
    return { status: 'failed', reason: 'unavailable' };
  }
  if (amount.amount_minor <= 0) {
    return { status: 'failed', reason: 'invalid' };
  }
  await wait(DEMO_PROCESSING_MS);
  const result = useWalletStore.getState().withdraw(idempotencyKey, amount, t('wallet.withdrawal'));
  if (result === 'ok') {
    return { status: 'succeeded' };
  }
  return { status: 'failed', reason: result === 'short' ? 'insufficient' : 'duplicate' };
}

/**
 * Parses a typed amount ("12", "12.5", "12,50") into minor units without floating point.
 * Returns null for anything that is not a plain positive amount with at most `minorUnit` decimals.
 */
export function parseAmountMinor(raw: string, minorUnit: number): number | null {
  const normalized = raw.trim().replace(',', '.');
  if (!/^\d{1,7}(\.\d*)?$/.test(normalized)) {
    return null;
  }
  const [whole = '0', fraction = ''] = normalized.split('.');
  if (fraction.length > minorUnit) {
    return null;
  }
  const minor = Number(whole) * 10 ** minorUnit + Number(fraction.padEnd(minorUnit, '0') || '0');
  return Number.isSafeInteger(minor) && minor > 0 ? minor : null;
}
