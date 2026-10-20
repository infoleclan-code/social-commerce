/**
 * Demo money: the session wallet (opening balance, top-ups, withdrawals, referral credits) and the
 * drop-slot checkout that settles on the device. None of it moves real money, so a store build must
 * never show it as if it did.
 *
 * On in development builds. In a release build only with EXPO_PUBLIC_DEMO_MONEY=1 (a sales or review
 * build), where the UI keeps its "Demo" labels. Real purchases go through the API (1-Tap, checkout,
 * squads, gifts), whose ledger is the only source of truth for balances.
 */
export const demoMoneyEnabled: boolean = __DEV__ || process.env.EXPO_PUBLIC_DEMO_MONEY === '1';
