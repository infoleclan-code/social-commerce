import {
  CardActionError,
  handleNextAction,
  handleURLCallback,
  initPaymentSheet,
  initStripe,
  PaymentIntent,
  PaymentSheetError,
  presentPaymentSheet,
} from '@stripe/stripe-react-native';
import Constants, { ExecutionEnvironment } from 'expo-constants';
import * as Linking from 'expo-linking';

import {
  setupIntentIdFromSecret,
  type CardSetupOutcome,
  type CardSetupRequest,
  type NextActionOutcome,
  type StripeBridge,
} from '@/features/payments/types';
import { palette } from '@/theme/tokens';

/**
 * iOS / Android: Stripe's native SDK runs 3-D Secure and PaymentSheet. Card numbers, CVCs and wallet
 * tokens stay inside the SDK and go straight to Stripe; this file only sees outcomes.
 *
 * Stripe is initialized lazily with the publishable key the API serves (`/payment-methods/*`), so a key
 * rotation needs no app release. EXPO_PUBLIC_STRIPE_MERCHANT_IDENTIFIER enables Apple Pay (it must match
 * the merchantIdentifier given to the @stripe/stripe-react-native config plugin).
 */

function filled(value: string | undefined): string | undefined {
  const trimmed = value?.trim();
  return trimmed ? trimmed : undefined;
}

const MERCHANT_IDENTIFIER = filled(process.env.EXPO_PUBLIC_STRIPE_MERCHANT_IDENTIFIER);
const MERCHANT_COUNTRY = (filled(process.env.EXPO_PUBLIC_STRIPE_MERCHANT_COUNTRY) ?? 'US').toUpperCase();
const MERCHANT_DISPLAY_NAME = filled(process.env.EXPO_PUBLIC_MERCHANT_DISPLAY_NAME) ?? Constants.expoConfig?.name ?? 'Social Commerce';

/** Expo Go and dev clients route deep links through `/--/`; release builds use the app scheme. */
const URL_SCHEME =
  Constants.executionEnvironment === ExecutionEnvironment.StoreClient ? Linking.createURL('/--/') : Linking.createURL('');

/** Where a bank page sends the buyer back to. +native-intent.tsx keeps the router from navigating to it. */
const RETURN_URL = Linking.createURL('stripe-redirect');

let readyKey: string | null = null;
let pending: { key: string; promise: Promise<void> } | null = null;

async function ensureStripe(publishableKey: string): Promise<void> {
  if (readyKey === publishableKey) {
    return;
  }
  if (pending?.key === publishableKey) {
    return pending.promise;
  }
  const promise = initStripe({
    publishableKey,
    merchantIdentifier: MERCHANT_IDENTIFIER,
    urlScheme: URL_SCHEME,
    setReturnUrlSchemeOnAndroid: true,
  }).then(() => {
    readyKey = publishableKey;
  });
  pending = { key: publishableKey, promise };
  try {
    await promise;
  } finally {
    if (pending?.promise === promise) {
      pending = null;
    }
  }
}

async function handlePaymentNextAction(publishableKey: string, clientSecret: string): Promise<NextActionOutcome> {
  try {
    await ensureStripe(publishableKey);
  } catch {
    return { status: 'failed', code: 'stripe_unavailable' };
  }

  const { paymentIntent, error } = await handleNextAction(clientSecret, RETURN_URL);
  if (error) {
    if (error.code === CardActionError.Canceled) {
      return { status: 'canceled' };
    }
    return { status: 'failed', code: error.declineCode ?? error.stripeErrorCode ?? null };
  }
  if (paymentIntent.status === PaymentIntent.Status.RequiresPaymentMethod) {
    // Authentication failed: the card cannot be charged this time.
    return { status: 'failed', code: paymentIntent.lastPaymentError?.declineCode ?? paymentIntent.lastPaymentError?.stripeErrorCode ?? null };
  }
  // RequiresCapture / Succeeded / Processing: the server learns the final state from the webhook.
  return { status: 'completed' };
}

async function presentCardSetup(request: CardSetupRequest): Promise<CardSetupOutcome> {
  const setupIntentId = setupIntentIdFromSecret(request.setupIntentClientSecret);
  if (!setupIntentId) {
    return { status: 'failed', code: 'invalid_setup' };
  }
  try {
    await ensureStripe(request.publishableKey);
  } catch {
    return { status: 'failed', code: 'stripe_unavailable' };
  }

  const customer =
    request.customerId && request.ephemeralKeySecret
      ? { customerId: request.customerId, customerEphemeralKeySecret: request.ephemeralKeySecret }
      : {};
  const init = await initPaymentSheet({
    merchantDisplayName: MERCHANT_DISPLAY_NAME,
    ...customer,
    setupIntentClientSecret: request.setupIntentClientSecret,
    returnURL: RETURN_URL,
    style: 'alwaysDark',
    applePay: MERCHANT_IDENTIFIER ? { merchantCountryCode: MERCHANT_COUNTRY } : undefined,
    // Google Pay's test environment follows the key: test keys can only use test wallets.
    googlePay: { merchantCountryCode: MERCHANT_COUNTRY, testEnv: request.publishableKey.startsWith('pk_test_') },
    appearance: {
      colors: {
        primary: palette.gold,
        background: '#0B0B0B',
        componentBackground: '#161616',
        componentBorder: '#2A2A2A',
        componentDivider: '#2A2A2A',
        primaryText: palette.ivory,
        secondaryText: '#A3A3A3',
        placeholderText: '#6B6B6B',
        icon: palette.goldSoft,
      },
      shapes: { borderRadius: 14 },
      primaryButton: {
        colors: { background: palette.gold, text: palette.ink },
        shapes: { borderRadius: 999 },
      },
    },
  });
  if (init.error) {
    return { status: 'failed', code: init.error.stripeErrorCode ?? null };
  }

  const presented = await presentPaymentSheet();
  if (presented.error) {
    if (presented.error.code === PaymentSheetError.Canceled) {
      return { status: 'canceled' };
    }
    return { status: 'failed', code: presented.error.declineCode ?? presented.error.stripeErrorCode ?? null };
  }
  return { status: 'saved', setupIntentId };
}

async function handleRedirect(url: string): Promise<boolean> {
  if (!url.includes('stripe-redirect') || readyKey === null) {
    return false;
  }
  try {
    return await handleURLCallback(url);
  } catch {
    return false;
  }
}

export const stripeBridge: StripeBridge = {
  supported: true,
  handlePaymentNextAction,
  presentCardSetup,
  handleRedirect,
};
