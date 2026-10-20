import { create } from 'zustand';

import { demoMoneyEnabled } from '@/lib/demo-mode';
import type { DisplayMoney } from '@/lib/money';
import type { PayMethod } from '@/stores/checkout';

export type LedgerItem = {
  id: string;
  type: 'purchase' | 'top_up' | 'referral' | 'withdrawal';
  title: string;
  amount: DisplayMoney;
  paymentMethod: PayMethod;
  timestamp: string;
  status: 'completed' | 'Referral Reward';
};

type LedgerDraft = {
  title: string;
  paymentMethod: PayMethod;
};

type WalletState = {
  available: DisplayMoney;
  ledger: readonly LedgerItem[];
  applied: Readonly<Record<string, true>>;
  debit: (key: string, amount: DisplayMoney, item: LedgerDraft) => 'ok' | 'short' | 'duplicate';
  withdraw: (key: string, amount: DisplayMoney, title: string) => 'ok' | 'short' | 'duplicate';
  credit: (key: string, amount: DisplayMoney, item: LedgerDraft, kind?: 'top_up' | 'referral') => 'ok' | 'duplicate';
};

/** Demo balance only. A release build without demo money starts (and stays) at zero on the device. */
const opening: DisplayMoney = { amount_minor: demoMoneyEnabled ? 50_000 : 0, currency: 'EUR', minor_unit: 2 };

function samePurse(balance: DisplayMoney, amount: DisplayMoney): boolean {
  return balance.currency === amount.currency && balance.minor_unit === amount.minor_unit;
}

/** Spendable balance only. Pending cashback is never stored here and never debited. */
export const useWalletStore = create<WalletState>((set, get) => ({
  available: opening,
  ledger: [],
  applied: {},
  debit: (key, amount, item) => {
    if (!demoMoneyEnabled) {
      return 'short';
    }
    if (get().applied[key]) {
      return 'duplicate';
    }
    const available = get().available;
    if (!samePurse(available, amount) || available.amount_minor < amount.amount_minor) {
      return 'short';
    }
    const entry: LedgerItem = {
      id: key,
      type: 'purchase',
      title: item.title,
      amount,
      paymentMethod: item.paymentMethod,
      timestamp: new Date().toISOString(),
      status: 'completed',
    };
    set({
      available: { ...available, amount_minor: available.amount_minor - amount.amount_minor },
      ledger: [entry, ...get().ledger],
      applied: { ...get().applied, [key]: true },
    });
    return 'ok';
  },
  withdraw: (key, amount, title) => {
    if (!demoMoneyEnabled) {
      return 'short';
    }
    if (get().applied[key]) {
      return 'duplicate';
    }
    const available = get().available;
    if (!samePurse(available, amount) || amount.amount_minor <= 0 || available.amount_minor < amount.amount_minor) {
      return 'short';
    }
    const entry: LedgerItem = {
      id: key,
      type: 'withdrawal',
      title,
      amount,
      paymentMethod: 'wallet',
      timestamp: new Date().toISOString(),
      status: 'completed',
    };
    set({
      available: { ...available, amount_minor: available.amount_minor - amount.amount_minor },
      ledger: [entry, ...get().ledger],
      applied: { ...get().applied, [key]: true },
    });
    return 'ok';
  },
  credit: (key, amount, item, kind = 'top_up') => {
    // A balance is never credited on the device outside demo builds: only the server's ledger can.
    if (!demoMoneyEnabled || get().applied[key]) {
      return 'duplicate';
    }
    const available = get().available;
    if (!samePurse(available, amount)) {
      return 'duplicate';
    }
    const entry: LedgerItem = {
      id: key,
      type: kind,
      title: item.title,
      amount,
      paymentMethod: item.paymentMethod,
      timestamp: new Date().toISOString(),
      status: kind === 'referral' ? 'Referral Reward' : 'completed',
    };
    set({
      available: { ...available, amount_minor: available.amount_minor + amount.amount_minor },
      ledger: [entry, ...get().ledger],
      applied: { ...get().applied, [key]: true },
    });
    return 'ok';
  },
}));
