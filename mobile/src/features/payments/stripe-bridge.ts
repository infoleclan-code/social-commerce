import type { CardSetupOutcome, NextActionOutcome, StripeBridge } from '@/features/payments/types';

/**
 * Web build: @stripe/stripe-react-native is native-only, so nothing here talks to Stripe. 1-Tap still
 * works on the web for payments that need no extra step; a 3-D Secure step or saving a card asks the
 * person to finish in the mobile app. Metro picks stripe-bridge.native.ts on iOS and Android.
 */
export const stripeBridge: StripeBridge = {
  supported: false,
  handlePaymentNextAction: async (): Promise<NextActionOutcome> => ({ status: 'unsupported' }),
  presentCardSetup: async (): Promise<CardSetupOutcome> => ({ status: 'unsupported' }),
  handleRedirect: async (): Promise<boolean> => false,
};
