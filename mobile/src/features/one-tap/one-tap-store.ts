import { create } from 'zustand';

import type { OrderSummary } from '@/api/contracts';
import type { OneTapParams } from '@/api/social-commerce';

/**
 * Why a 1-Tap attempt stopped. Each maps to one line of copy and at most one action in the banner.
 * The server's error codes (backend ApiException) map onto these in one-tap-engine.ts.
 */
export type OneTapProblem =
  | 'payment_method_required'
  | 'shipping_address_required'
  | 'not_shippable'
  | 'out_of_stock'
  | 'variant_unavailable'
  | 'limit'
  | 'restricted'
  | 'unavailable'
  | 'declined'
  | 'provider_unavailable'
  | 'network'
  | 'rate_limited'
  | 'signed_out'
  | 'auth_required'
  | 'auth_failed'
  | 'auth_canceled'
  | 'unsupported'
  | 'still_processing'
  | 'not_cancellable'
  | 'undo_failed'
  | 'setup_failed'
  | 'key_reused'
  | 'unknown';

export type PurchasePhase =
  /** POST /checkout/one-tap in flight. */
  | 'submitting'
  /** Stripe PaymentSheet (setup mode) is saving a card before the purchase. */
  | 'saving_card'
  /** 3-D Secure inside the Stripe SDK. */
  | 'authenticating'
  /** Waiting for the provider's final answer (the order is polled). */
  | 'confirming'
  /** Card authorized, inside the undo window: nothing is captured yet. */
  | 'authorized'
  /** Order placed: captured, or the undo window has passed. */
  | 'completed'
  /** Undo in flight. */
  | 'cancelling'
  /** Undone or abandoned: nothing was charged. */
  | 'cancelled'
  /** Affiliate merchant opened in the browser. */
  | 'redirected'
  | 'failed';

export type Purchase = {
  productId: string;
  title: string;
  merchantName: string;
  params: OneTapParams;
  /** Idempotency-Key of the current attempt. Reused only when the previous outcome is unknown. */
  key: string;
  /** Increments per attempt: async steps of an older attempt never overwrite a newer one. */
  attempt: number;
  phase: PurchasePhase;
  order: OrderSummary | null;
  problem: OneTapProblem | null;
  declineCode: string | null;
  updatedAt: number;
};

const BUSY: ReadonlySet<PurchasePhase> = new Set<PurchasePhase>(['submitting', 'saving_card', 'authenticating', 'confirming', 'cancelling']);

/** True while a step is running: a second tap on the same product must not start another purchase. */
export function isBusy(purchase: Purchase | null | undefined): boolean {
  return purchase !== null && purchase !== undefined && BUSY.has(purchase.phase);
}

type OneTapState = {
  purchases: Readonly<Record<string, Purchase>>;
  /** The purchase the status banner shows (the most recent tap). */
  focusId: string | null;
  /** Starts or restarts a purchase and focuses the banner on it. */
  put: (purchase: Purchase) => void;
  /** Applies a patch only if `attempt` is still the current attempt. Returns false when superseded. */
  update: (productId: string, attempt: number, patch: Partial<Omit<Purchase, 'productId' | 'attempt'>>) => boolean;
  focus: (productId: string) => void;
  /** Hides the banner. A running purchase continues in the background. */
  dismiss: () => void;
};

/** Session state of 1-Tap purchases, keyed by product. Lives at module scope, so scrolling away never cancels a purchase. */
export const useOneTapStore = create<OneTapState>((set, get) => ({
  purchases: {},
  focusId: null,
  put: (purchase) => {
    set({ purchases: { ...get().purchases, [purchase.productId]: purchase }, focusId: purchase.productId });
  },
  update: (productId, attempt, patch) => {
    const current = get().purchases[productId];
    if (!current || current.attempt !== attempt) {
      return false;
    }
    set({ purchases: { ...get().purchases, [productId]: { ...current, ...patch, updatedAt: Date.now() } } });
    return true;
  },
  focus: (productId) => {
    if (get().purchases[productId]) {
      set({ focusId: productId });
    }
  },
  dismiss: () => {
    set({ focusId: null });
  },
}));
