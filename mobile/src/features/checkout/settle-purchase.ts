import type { CheckoutDraft, CheckoutRecord } from '@/stores/checkout';
import { useClosetStore } from '@/stores/closet';
import type { DisplayMoney } from '@/lib/money';
import { useWalletStore } from '@/stores/wallet';

export type SettleResult =
  | { kind: 'duplicate' }
  | { kind: 'shortfall'; shortfall: DisplayMoney }
  | { kind: 'completed'; record: CheckoutRecord };

function shortfallFor(price: DisplayMoney, available: DisplayMoney): DisplayMoney | null {
  if (price.currency !== available.currency || price.minor_unit !== available.minor_unit) {
    return price;
  }
  if (available.amount_minor >= price.amount_minor) {
    return null;
  }
  return { ...price, amount_minor: price.amount_minor - available.amount_minor };
}

function rememberCloset(draft: CheckoutDraft): void {
  useClosetStore.getState().addPurchased({
    id: draft.idempotencyKey,
    title: draft.title,
    image: draft.image,
    variant: draft.variant,
    status: 'purchased',
    fulfillment: 'in_transit',
    orderedAt: new Date().toISOString(),
    price: draft.showPrice ? draft.price : null,
  });
}

function recordOf(draft: CheckoutDraft): CheckoutRecord {
  // Demo settlement: no payment reference is invented (a fake `pm_` id would look like a Stripe one).
  const token = null;
  return {
    id: draft.idempotencyKey,
    slotId: draft.slotId,
    title: draft.title,
    variant: draft.variant,
    method: draft.method,
    action: draft.action,
    expectedOn: draft.expectedOn,
    endsAt: draft.endsAt,
    purchased: draft.purchased,
    goal: draft.goal,
    price: draft.showPrice ? draft.price : null,
    showPrice: draft.showPrice,
    merchantId: draft.merchantId,
    merchantUrl: draft.merchantUrl,
    paymentReference: token,
  };
}

/** One checkout key settles once. Wallet spends available balance only, at full price. */
export function settlePurchase(draft: CheckoutDraft): SettleResult {
  const price = draft.showPrice ? draft.price : null;
  if (draft.method === 'wallet' && price && price.amount_minor > 0) {
    const gap = shortfallFor(price, useWalletStore.getState().available);
    if (gap) {
      return { kind: 'shortfall', shortfall: gap };
    }
    const debited = useWalletStore.getState().debit(draft.idempotencyKey, price, {
      title: draft.title,
      paymentMethod: 'wallet',
    });
    if (debited === 'short') {
      const again = shortfallFor(price, useWalletStore.getState().available);
      return { kind: 'shortfall', shortfall: again ?? price };
    }
    if (debited === 'duplicate') {
      rememberCloset(draft);
      return { kind: 'duplicate' };
    }
  }
  rememberCloset(draft);
  return { kind: 'completed', record: recordOf(draft) };
}

export function fundShortfall(draft: CheckoutDraft, shortfall: DisplayMoney, method: 'apple_pay' | 'card'): void {
  useWalletStore.getState().credit(`${draft.idempotencyKey}:topup`, shortfall, {
    title: draft.title,
    paymentMethod: method,
  });
}
