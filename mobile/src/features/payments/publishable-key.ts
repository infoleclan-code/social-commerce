import { fetchDefaultPaymentMethod } from '@/api/social-commerce';

/**
 * The Stripe publishable key. The API serves it with every /payment-methods response, so the key always
 * belongs to the same Stripe account that creates the PaymentIntents; EXPO_PUBLIC_STRIPE_PUBLISHABLE_KEY is
 * only a fallback for builds talking to an API that does not serve it. Publishable keys are public.
 */

const BUILD_KEY = process.env.EXPO_PUBLIC_STRIPE_PUBLISHABLE_KEY?.trim() || null;

let served: string | null = null;

export function rememberPublishableKey(key: string | null | undefined): void {
  if (typeof key === 'string' && key.startsWith('pk_')) {
    served = key;
  }
}

export async function resolvePublishableKey(): Promise<string | null> {
  if (served) {
    return served;
  }
  try {
    rememberPublishableKey((await fetchDefaultPaymentMethod()).publishable_key);
  } catch {
    // Fall through to the build-time key.
  }
  return served ?? BUILD_KEY;
}
