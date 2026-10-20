export type DisplayMoney = {
  amount_minor: number;
  currency: string;
  minor_unit: number;
};

/** Formats a server amount for display. Do not send the result back or do further math with it. */
export function formatMoney(money: DisplayMoney, locale = 'en'): string {
  const major = money.amount_minor / 10 ** money.minor_unit;
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency: money.currency,
  }).format(major);
}
