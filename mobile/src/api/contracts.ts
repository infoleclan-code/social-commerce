import type { DisplayMoney } from '@/lib/money';

/**
 * Response shapes of the Laravel API (backend/app/Http/Resources). Hand-written until the OpenAPI
 * document exists; then they are generated into types.gen.ts and this file re-exports them.
 * Every resource is wrapped in `{ data }`; cursor collections add `meta.next_cursor`.
 */

export type Envelope<T> = { data: T };

export type CursorPage<T, TMeta extends object = object> = {
  data: T[];
  meta: { next_cursor: string | null } & TMeta;
};

// --------------------------------------------------------------------------------------------- users

export type UserSummary = {
  id: string;
  username: string;
  display_name: string | null;
  avatar_url: string | null;
};

export type UserProfile = {
  id: string;
  username: string;
  display_name: string | null;
  avatar_url: string | null;
  bio: string | null;
  counts: { posts: number; followers: number; following: number };
  viewer: { is_self: boolean; is_friend: boolean; connection?: 'self' | 'none' | 'pending_out' | 'pending_in' | 'friends' | 'blocked' };
};

// ------------------------------------------------------------------------------------------ products

/**
 * What the buy button does for this product:
 * - one_tap: pay in-app with the saved card (marketplace vendor, in stock)
 * - redirect: open the merchant's site with tracking (affiliate merchant)
 * - unavailable: sold out or not purchasable
 */
export type PurchaseMode = 'one_tap' | 'redirect' | 'unavailable';

export type ProductVariant = {
  sku: string;
  label: string;
  in_stock: boolean;
};

export type ProductCard = {
  id: string;
  slug: string;
  title: string;
  category: string;
  image_url: string | null;
  images: string[];
  price: DisplayMoney | null;
  compare_at_price: DisplayMoney | null;
  /** Affiliate prices are a feed snapshot: when it was taken. */
  price_as_of: string | null;
  variants: ProductVariant[];
  merchant: { id: string; name: string };
  purchase: { mode: PurchaseMode; in_stock: boolean };
};

// ---------------------------------------------------------------------------------------------- feed

export type ShopOffer = {
  commission_bps: number;
  product: {
    id: string;
    title: string;
    brand: string;
    image_url: string;
    try_on: boolean;
    stock: number;
    price: DisplayMoney;
    squad_price: DisplayMoney;
  };
  flash: {
    id: string;
    discount_bps: number;
    stock_remaining: number;
    ends_at: string;
  } | null;
  drop_hour: {
    id: string;
    ends_at: string;
    remaining: number;
    price: DisplayMoney;
  } | null;
  landed: {
    total: DisplayMoney;
    shipping: DisplayMoney;
    vat: DisplayMoney;
    duty: DisplayMoney;
    /** Already zero when VAT or duty does not apply, so the client can match the server total. */
    vat_bps?: number;
    duty_bps?: number;
    delivers_on: string;
  } | null;
  source: 'manual' | 'auto';
  price_protection_days: number;
};

export type StoreProduct = {
  id: string;
  title: string;
  brand: string;
  image_url: string;
  try_on: boolean;
  price: DisplayMoney;
};

export type FeedMode = 'for_you' | 'following';

export type MediaType = 'image' | 'video';

export type FeedMedia = {
  type: MediaType;
  url: string;
  thumbnail_url: string | null;
  width: number | null;
  height: number | null;
  duration_ms: number | null;
};

export type FeedPost = {
  id: string;
  kind: 'purchase' | 'resale' | 'drop' | 'media' | (string & {});
  author: UserSummary;
  media: FeedMedia | null;
  caption: string | null;
  tags: string[];
  audience: 'friends' | 'public';
  product: ProductCard | null;
  stats: { likes: number; comments: number; shares: number };
  viewer: { liked: boolean; is_author: boolean };
  created_at: string | null;
  shop: ShopOffer | null;
  synthetic: {
    label: string;
    disclosed: boolean;
    tracks: { language: string; kind: 'dub' | 'subtitle'; label: string; cues: string; audio_url: null }[];
  } | null;
};

export type FeedPage = CursorPage<FeedPost, { mode?: FeedMode }>;

export type LikeState = { liked: boolean; likes_count: number };

export type HashtagTopic = {
  tag: string;
  posts: number;
};

export type Engagement = {
  liked: boolean;
  likes_count: number;
  comments_count: number;
  shares_count: number;
};

export type ShareChannel = 'copy_link' | 'whatsapp' | 'instagram' | 'tiktok' | 'messenger' | 'sms' | 'other';

export type ShareResult = { shares_count: number; url: string; commission_bps?: number; token?: string | null };

export type FeedComment = {
  id: string;
  post_id: string;
  parent_id: string | null;
  body: string;
  author: UserSummary;
  replies_count: number;
  is_mine: boolean;
  created_at: string | null;
};

export type CommentPage = CursorPage<FeedComment, { comments_count?: number }>;

// ------------------------------------------------------------------------------------- visual search

/** Normalized 0..1 of the analyzed image's width and height. */
export type BoundingBox = { x: number; y: number; width: number; height: number };

export type DetectedLabel = { label: string; score: number };

/** Price to the buyer's door: merchandise + shipping + VAT + duty, in the listing's currency. */
export type LandedQuote = {
  destination: string | null;
  merchandise: DisplayMoney;
  shipping: DisplayMoney;
  vat: DisplayMoney;
  duty: DisplayMoney;
  total: DisplayMoney;
  vat_bps?: number;
  duty_bps?: number;
  delivers_on: string;
};

export type VisualMatch = {
  product: ProductCard;
  /** Rank score: the weaker of the two visual signals (both must pass), 0..1. */
  score: number;
  /** Cosine similarity of the luminance signatures, 0..1. */
  visual_similarity: number;
  /** Cosine similarity of the edge-orientation signatures, 0..1 (visual_v2 and later). */
  structure_similarity?: number;
  /** False unless the match is exact (cross-checked visually, or a recognized category whose colors and brand agree). */
  verified?: boolean;
  /** cross_checked: exact; semantic: closest listings of the recognized category and colors; canonical: the product tagged on the post. */
  match?: 'cross_checked' | 'semantic' | 'canonical';
  category_match: boolean;
  landed?: LandedQuote | null;
};

export type VisualSearchResult = {
  scan_id: string;
  analyzer: string;
  detected_category: string | null;
  /** What the vision describer saw (category, colors, brand). Model output: display it as plain text only. */
  detected?: {
    category: string | null;
    colors: string[];
    brand: string | null;
    features: string[];
    confidence: number;
    source: string;
  };
  exact_match?: boolean;
  labels: DetectedLabel[];
  bounding_box: BoundingBox | null;
  latency_ms: number | null;
  /** Null for an exact match; otherwise how the results were found. */
  fallback?: 'semantic' | 'canonical' | null;
  results: VisualMatch[];
};

// ------------------------------------------------------------------------------------ product graph

/** One seller's listing of the same product (same canonical key), cheapest landed total first. */
export type ProductOffer = {
  product_id: string;
  seller_id: string | null;
  title: string;
  in_stock: boolean;
  price: DisplayMoney;
  landed: LandedQuote;
};

export type ProductOffers = {
  product_id: string;
  canonical_key: string | null;
  destination: string | null;
  offers: ProductOffer[];
  computed_at: string;
};

export type RelatedEdge = {
  product_id: string;
  kind: 'same_product' | 'also_tagged' | 'also_bought';
  weight: number;
  title: string;
  image_url: string;
  price: DisplayMoney;
  in_stock: boolean;
};

export type RelatedProducts = { product_id: string; edges: RelatedEdge[] };

export type LiveFlashDrop = {
  ok: boolean;
  drop: { id: string; product_id: string; discount_bps: number; stock_remaining: number; ends_at: string } | null;
};

// ------------------------------------------------------------------------------------------ checkout

/** 'pending', 'failed' and 'refunded' are only sent by older API versions; payment_status is authoritative. */
export type OrderStatus = 'pending' | 'processing' | 'completed' | 'cancelled' | 'failed' | 'refunded';

/**
 * Where the money stands (backend PaymentStep). Only moves forward on the server.
 * - pending / unknown: the provider's answer is not in yet: sync or poll, never re-buy.
 * - requires_action: run next_action (3-D Secure) in the Stripe SDK, then POST /orders/{id}/sync.
 * - authorized: the card is held; captured after the undo window.
 * - voided: the hold was released (undo, abandoned authentication): nothing was charged.
 */
export type PaymentStatus =
  | 'unpaid'
  | 'pending'
  | 'unknown'
  | 'requires_action'
  | 'authorized'
  | 'captured'
  | 'partially_refunded'
  | 'refunded'
  | 'voided'
  | 'failed'
  | 'disputed';

/** The secret is short-lived and only in a live response: never store or log it. */
export type NextAction = { type: 'use_stripe_sdk'; client_secret: string };

/** Payment fields every purchase response carries (checkout, 1-Tap, squad, gift, blind box). */
export type PaymentStep = {
  payment_status: PaymentStatus;
  /** Present only while 3-D Secure is pending, and null in a replayed response. */
  next_action: NextAction | null;
  failure_code: string | null;
};

export type OrderSummary = {
  id: string;
  status: OrderStatus;
  payment_status: PaymentStatus | null;
  next_action?: NextAction | null;
  failure_code: string | null;
  /** Set while the payment is only authorized: until then the buyer can undo for free. */
  undo_until: string | null;
  total: DisplayMoney | null;
  refunded?: DisplayMoney | null;
  withdrawal_deadline?: string | null;
  item: {
    product_id: string | null;
    title: string;
    variant_sku: string | null;
    size_label: string | null;
    quantity: number;
  } | null;
  placed_at: string | null;
  fulfillment?: 'preparing' | 'shipped' | 'delivered';
  carrier?: string | null;
  tracking_number?: string | null;
  shipped_at?: string | null;
};

export type OneTapResponse =
  | {
      mode: 'native';
      order: OrderSummary;
      next_action: NextAction | null;
      replayed: boolean;
    }
  | { mode: 'affiliate_redirect'; redirect_url: string };

/** POST /checkout: the order as placed. A 409 PRICE_CHANGED carries `meta.total` instead. */
export type CheckoutOrder = PaymentStep & {
  id: string;
  status: string;
  replayed: boolean;
  quantity: number;
  discount_bps: number;
  undo_until: string | null;
  total: DisplayMoney;
};

export type SquadHold = PaymentStep & {
  authorization_id: string | null;
  squad_id: string | null;
  order_id: string;
  replayed: boolean;
  members: number;
  expires_at: string | null;
  authorized: DisplayMoney;
  solo: DisplayMoney;
  squad: DisplayMoney;
};

export type GiftPurchase = PaymentStep & {
  token: string;
  url: string;
  order_id: string;
  status: string;
  expires_at: string;
  replayed: boolean;
  product: { id: string; title: string };
};

export type BlindBoxPull = PaymentStep & {
  replayed: boolean;
  status: string;
  order_id: string;
  /** Revealed only once the payment is captured. */
  product: { id: string; title: string; image_url: string } | null;
};

// ----------------------------------------------------------------------------------- payment methods

export type PaymentMethodSummary = {
  brand: string;
  last4: string;
  exp_month: number | null;
  exp_year: number | null;
  wallet: string | null;
};

export type DefaultPaymentMethod = {
  has_default: boolean;
  summary: PaymentMethodSummary | null;
  publishable_key: string | null;
};

/** POST /payment-methods/setup. `client_secret` is null in a replayed response: start a new setup. */
export type PaymentSetup = {
  id: string;
  client_secret: string | null;
  publishable_key: string | null;
};

export type PaymentSetupConfirmation = {
  has_default: boolean;
  summary: PaymentMethodSummary | null;
  publishable_key?: string | null;
  /** Older API versions only. */
  setup_status?: string;
};
