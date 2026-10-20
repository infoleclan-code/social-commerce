import type { DisplayMoney } from '@/lib/money';

/** Layout fixtures until wallet and drop queries are wired. */
export const previewWallet = {
  available: { amount_minor: 6200, currency: 'EUR', minor_unit: 2 },
  pending: { amount_minor: 1840, currency: 'EUR', minor_unit: 2 },
  expectedOn: '2026-11-03',
} as const satisfies {
  available: DisplayMoney;
  pending: DisplayMoney;
  expectedOn: string;
};

/** Display history. Amounts are fixtures, not calculated from prices. */
export const previewActivity = [
  {
    id: 'earned-coat',
    titleKey: 'wallet.txCoat',
    amount: { amount_minor: 4200, currency: 'EUR', minor_unit: 2 },
    kind: 'available',
    on: '2026-09-12',
  },
  {
    id: 'earned-loafer',
    titleKey: 'wallet.txLoafer',
    amount: { amount_minor: 2000, currency: 'EUR', minor_unit: 2 },
    kind: 'available',
    on: '2026-09-28',
  },
  {
    id: 'pending-slip',
    titleKey: 'wallet.txSlip',
    amount: { amount_minor: 1840, currency: 'EUR', minor_unit: 2 },
    kind: 'pending',
    on: '2026-10-01',
  },
] as const satisfies ReadonlyArray<{
  id: string;
  titleKey: 'wallet.txCoat' | 'wallet.txLoafer' | 'wallet.txSlip';
  amount: DisplayMoney;
  kind: 'available' | 'pending';
  on: string;
}>;

export const previewDrops = [
  { id: 'coat', title: 'Wool coat', detail: 'Charcoal', purchased: 18, goal: 30 },
  { id: 'loafer', title: 'Leather loafer', detail: 'Black', purchased: 9, goal: 24 },
] as const;
