/**
 * Platform-neutral results of the Stripe steps the app runs itself. The native bridge
 * (stripe-bridge.native.ts) uses @stripe/stripe-react-native; the web build has no Stripe SDK and
 * answers `unsupported` (stripe-bridge.ts). Card data never passes through our code on any platform.
 */

/** Result of authenticating a PaymentIntent the server already confirmed (3-D Secure). */
export type NextActionOutcome =
  | { status: 'completed' }
  | { status: 'canceled' }
  | { status: 'failed'; code: string | null }
  | { status: 'unsupported' };

/** Result of saving a card / Apple Pay / Google Pay with PaymentSheet in setup mode. */
export type CardSetupOutcome =
  | { status: 'saved'; setupIntentId: string }
  | { status: 'canceled' }
  | { status: 'failed'; code: string | null }
  | { status: 'unsupported' };

export type CardSetupRequest = {
  publishableKey: string;
  setupIntentClientSecret: string;
  /**
   * Optional customer context (saved cards inside PaymentSheet). Stripe requires both or neither; the
   * API does not send them today, so the sheet collects one new card for the SetupIntent's customer.
   */
  customerId?: string | null;
  ephemeralKeySecret?: string | null;
};

/** The surface both bridge files implement (each annotates its export with this type). */
export type StripeBridge = {
  /** False on platforms without the native SDK (web). */
  supported: boolean;
  handlePaymentNextAction: (publishableKey: string, clientSecret: string) => Promise<NextActionOutcome>;
  presentCardSetup: (request: CardSetupRequest) => Promise<CardSetupOutcome>;
  /** Hands a deep link back to the SDK (bank-app / browser return after 3-D Secure). True if it was Stripe's. */
  handleRedirect: (url: string) => Promise<boolean>;
};

/** `seti_123_secret_abc` -> `seti_123`. Only the id goes back to the server, which verifies it with Stripe. */
export function setupIntentIdFromSecret(clientSecret: string): string | null {
  const index = clientSecret.indexOf('_secret_');
  const candidate = index > 0 ? clientSecret.slice(0, index) : '';
  // Same alphabet the API accepts (setup_intent_id: ^seti_[A-Za-z0-9_]{1,100}$).
  return /^seti_[A-Za-z0-9_]{1,100}$/.test(candidate) ? candidate : null;
}
