import { create } from 'zustand';

import { fundShortfall, settlePurchase } from '@/features/checkout/settle-purchase';
import type { SlotAction } from '@/features/feed/fixtures';
import { offeringForSlot, variantsFor } from '@/features/feed/fixtures';
import { demoMoneyEnabled } from '@/lib/demo-mode';
import { newIdempotencyKey } from '@/lib/idempotency';
import type { DisplayMoney } from '@/lib/money';
import { openExternalUrl } from '@/lib/safe-url';
import { useDropJoinStore } from '@/stores/drop-join';
import { playSuccess } from '@/lib/feedback';

export type PayMethod = 'wallet' | 'apple_pay' | 'card' | 'paypal' | 'klarna';

export type CheckoutDraft = {
  slotId: string;
  action: SlotAction;
  title: string;
  eyebrow: string;
  image: string;
  showPrice: boolean;
  price: DisplayMoney | null;
  cashbackBadge: string | null;
  expectedOn: string;
  endsAt: string;
  purchased: number;
  goal: number;
  variants: string[];
  variant: string;
  method: PayMethod;
  merchantId: string | null;
  merchantUrl: string | null;
  idempotencyKey: string;
};

export type CheckoutRecord = {
  id: string;
  slotId: string;
  title: string;
  variant: string;
  method: PayMethod;
  action: SlotAction;
  expectedOn: string;
  endsAt: string;
  purchased: number;
  goal: number;
  price: DisplayMoney | null;
  showPrice: boolean;
  merchantId: string | null;
  merchantUrl: string | null;
  paymentReference: string | null;
};

type CheckoutState = {
  draft: CheckoutDraft | null;
  records: CheckoutRecord[];
  processing: boolean;
  shortfall: DisplayMoney | null;
  settled: Readonly<Record<string, true>>;
  celebrating: boolean;
  open: (slotId: string, action: SlotAction, title?: string) => void;
  openPrepared: (draft: Omit<CheckoutDraft, 'method' | 'idempotencyKey'>) => void;
  close: () => void;
  setVariant: (variant: string) => void;
  setMethod: (method: PayMethod) => void;
  confirm: () => void;
  completeTopUp: (method: 'apple_pay' | 'card') => void;
  finishCelebration: () => void;
};

function checkoutKey(slotId: string): string {
  return newIdempotencyKey(slotId.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 20) || 'slot');
}

function finish(record: CheckoutRecord, key: string): void {
  const records = useCheckoutStore.getState().records;
  const already = records.some((item) => item.slotId === record.slotId);
  if (!already) {
    useDropJoinStore.getState().join(record.slotId);
  }
  // A drop slot is held once per user; every completed purchase is its own order.
  const keep = already && record.action === 'join';
  useCheckoutStore.setState({
    processing: false,
    celebrating: true,
    shortfall: null,
    settled: { ...useCheckoutStore.getState().settled, [key]: true },
    records: keep ? records : [record, ...records],
  });
  playSuccess();
}

/**
 * Session checkout. One idempotency key settles once. Card numbers never enter this store.
 *
 * This sheet settles on the device (demo money: src/lib/demo-mode.ts). A release build without demo
 * money never opens it: an offer with a merchant link opens the merchant (https only) instead, and
 * nothing is recorded as bought on the phone.
 */
export const useCheckoutStore = create<CheckoutState>((set, get) => ({
  draft: null,
  records: [],
  processing: false,
  shortfall: null,
  settled: {},
  celebrating: false,
  open: (slotId, action, title) => {
    if (!demoMoneyEnabled || get().records.some((record) => record.slotId === slotId)) {
      return;
    }
    const offering = offeringForSlot(slotId);
    if (!offering) {
      return;
    }
    const variants = variantsFor(slotId);
    set({
      shortfall: null,
      processing: false,
      draft: {
        ...offering,
        title: title ?? offering.title,
        slotId,
        action,
        variants,
        variant: variants[0] ?? 'M',
        method: 'wallet',
        merchantId: null,
        merchantUrl: null,
        idempotencyKey: checkoutKey(slotId),
      },
    });
  },
  openPrepared: (draft) => {
    if (!demoMoneyEnabled) {
      if (draft.merchantUrl) {
        void openExternalUrl(draft.merchantUrl);
      }
      return;
    }
    // Only a drop slot is one-per-user. Buying the same offer again is a new order with a new key.
    if (draft.action === 'join' && get().records.some((record) => record.slotId === draft.slotId)) {
      return;
    }
    set({
      shortfall: null,
      processing: false,
      draft: {
        ...draft,
        price: draft.showPrice ? draft.price : null,
        method: 'wallet',
        idempotencyKey: checkoutKey(draft.slotId),
      },
    });
  },
  close: () => {
    set({ draft: null, shortfall: null, processing: false, celebrating: false });
  },
  setVariant: (variant) => {
    const draft = get().draft;
    if (!draft || get().processing) {
      return;
    }
    set({ draft: { ...draft, variant } });
  },
  setMethod: (method) => {
    const draft = get().draft;
    if (!draft || get().processing) {
      return;
    }
    set({ draft: { ...draft, method }, shortfall: null });
  },
  confirm: () => {
    const draft = get().draft;
    if (!demoMoneyEnabled || !draft || get().processing) {
      return;
    }
    if (get().settled[draft.idempotencyKey]) {
      set({ draft: null, shortfall: null });
      return;
    }
    set({ processing: true });
    const result = settlePurchase(draft);
    if (result.kind === 'shortfall') {
      set({ processing: false, shortfall: result.shortfall });
      return;
    }
    if (result.kind === 'duplicate') {
      set({ processing: false, draft: null, shortfall: null, settled: { ...get().settled, [draft.idempotencyKey]: true } });
      return;
    }
    finish(result.record, draft.idempotencyKey);
  },
  completeTopUp: (method) => {
    const draft = get().draft;
    const shortfall = get().shortfall;
    if (!demoMoneyEnabled || !draft || !shortfall || get().processing) {
      return;
    }
    if (get().settled[draft.idempotencyKey]) {
      set({ draft: null, shortfall: null, processing: false });
      return;
    }
    set({ processing: true, draft: { ...draft, method: 'wallet' } });
    fundShortfall(draft, shortfall, method);
    const result = settlePurchase({ ...draft, method: 'wallet' });
    if (result.kind === 'completed') {
      finish(result.record, draft.idempotencyKey);
      return;
    }
    if (result.kind === 'shortfall') {
      set({ processing: false, shortfall: result.shortfall });
      return;
    }
    set({ processing: false, draft: null, shortfall: null, settled: { ...get().settled, [draft.idempotencyKey]: true } });
  },
  finishCelebration: () => {
    set({ celebrating: false, draft: null, shortfall: null, processing: false });
  },
}));
