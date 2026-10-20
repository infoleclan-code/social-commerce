import { api, json, keyed, query } from '@/api/client';
import type {
  BlindBoxPull,
  CheckoutOrder,
  CommentPage,
  DefaultPaymentMethod,
  Envelope,
  Engagement,
  FeedComment,
  FeedMode,
  FeedPage,
  GiftPurchase,
  HashtagTopic,
  LikeState,
  LiveFlashDrop,
  OneTapResponse,
  OrderSummary,
  PaymentSetup,
  PaymentSetupConfirmation,
  ProductOffers,
  RelatedProducts,
  ShareChannel,
  ShareResult,
  SquadHold,
  StoreProduct,
  UserProfile,
  VisualSearchResult,
} from '@/api/contracts';
import type { DisplayMoney } from '@/lib/money';

/**
 * Typed calls for the social-commerce API (/api/v1). One function per endpoint; screens use these through
 * TanStack Query hooks, never fetch directly. Route ids are always URI-encoded.
 *
 * Every call that moves money or reserves stock takes an `idempotencyKey` (src/lib/idempotency.ts): the
 * API refuses those requests without one, and the transport retries them with the same key.
 */

const FEED_PAGE_SIZE = 10;
const COMMENT_PAGE_SIZE = 20;

const id = (value: string): string => encodeURIComponent(value);

// ---------------------------------------------------------------------------------------------- feed

export function fetchFeedPage(mode: FeedMode, cursor: string | null, signal?: AbortSignal): Promise<FeedPage> {
  return api<FeedPage>(`/feed${query({ mode, cursor, limit: FEED_PAGE_SIZE })}`, { signal });
}

export async function likePost(postId: string): Promise<LikeState> {
  return (await api<Envelope<LikeState>>(`/feed/${id(postId)}/like`, json('POST'))).data;
}

export async function unlikePost(postId: string): Promise<LikeState> {
  return (await api<Envelope<LikeState>>(`/feed/${id(postId)}/like`, json('DELETE'))).data;
}

/** Counts the share and returns the link to send (a tracked /s/{token} link for shoppable posts). */
export async function sharePost(postId: string, channel: ShareChannel): Promise<ShareResult> {
  return (await api<Envelope<ShareResult>>(`/feed/${id(postId)}/share`, json('POST', { channel }))).data;
}

export function fetchHashtagPosts(tag: string, cursor: string | null, signal?: AbortSignal): Promise<FeedPage> {
  return api<FeedPage>(`/hashtags/posts${query({ tag, cursor, limit: FEED_PAGE_SIZE })}`, { signal });
}

export function fetchTrendingHashtags(signal?: AbortSignal): Promise<HashtagTopic[]> {
  return api<Envelope<HashtagTopic[]>>('/hashtags/trending', { signal }).then((body) => body.data);
}

export function searchHashtags(q: string, signal?: AbortSignal): Promise<HashtagTopic[]> {
  return api<Envelope<HashtagTopic[]>>(`/hashtags${query({ q })}`, { signal }).then((body) => body.data);
}

/**
 * POST /checkout. `expectedTotalMinor` is the total the buyer was shown: if the server prices the order
 * differently (a price change, an expired flash discount) it answers 409 PRICE_CHANGED with
 * `meta.total` and charges nothing, and the app asks again with the new total.
 */
export async function checkoutProduct(body: {
  productId: string;
  postId: string | null;
  squadId: string | null;
  couponId: string | null;
  quantity: number;
  expectedTotalMinor: number | null;
  idempotencyKey: string;
}): Promise<CheckoutOrder> {
  const payload = {
    product_id: body.productId,
    post_id: body.postId,
    squad_id: body.squadId,
    coupon_id: body.couponId,
    quantity: body.quantity,
    expected_total_minor: body.expectedTotalMinor ?? undefined,
  };
  return (await api<Envelope<CheckoutOrder>>('/checkout', keyed(body.idempotencyKey, payload))).data;
}

/** Holds the solo price on the buyer's card and opens a squad (captured at the squad price when it fills). */
export async function authorizeSquad(productId: string, postId: string | null, idempotencyKey: string): Promise<SquadHold> {
  return (await api<Envelope<SquadHold>>('/squads/authorize', keyed(idempotencyKey, { product_id: productId, post_id: postId }))).data;
}

/** Joins someone's squad with a card hold of its own. */
export async function authorizeSquadJoin(squadId: string, idempotencyKey: string): Promise<SquadHold> {
  return (await api<Envelope<SquadHold>>(`/squads/${id(squadId)}/authorize-join`, keyed(idempotencyKey))).data;
}

export function reserveDrop(dropId: string, idempotencyKey: string): Promise<{ reservation_id: string; expires_at?: string }> {
  return api<{ data: { reservation_id: string; expires_at?: string } }>(`/drop-hour/${id(dropId)}/reserve`, keyed(idempotencyKey)).then(
    (response) => response.data,
  );
}

export function fetchLedger(signal?: AbortSignal): Promise<{ earned: DisplayMoney; clicks: number }> {
  return api<{ data: { earned: DisplayMoney; clicks: number } }>('/me/ledger', { signal }).then((response) => response.data);
}

/** Records an open of a tracked /s/{token} link. */
export function openReferral(token: string): Promise<{ token: string; commission_bps: number }> {
  return api<{ data: { token: string; commission_bps: number } }>(`/s/${encodeURIComponent(token)}`).then((response) => response.data);
}

/** Buys a gift: the buyer's card is charged like any purchase; the link is claimable once it is paid. */
export async function createGift(productId: string, postId: string | null, idempotencyKey: string): Promise<GiftPurchase> {
  return (await api<Envelope<GiftPurchase>>('/gifts', keyed(idempotencyKey, { product_id: productId, post_id: postId }))).data;
}

export function fetchGift(token: string, signal?: AbortSignal): Promise<{ status: string; can_claim: boolean; product: { title: string; image_url: string } }> {
  return api<{ data: { status: string; can_claim: boolean; product: { title: string; image_url: string } } }>(`/gifts/${encodeURIComponent(token)}`, { signal }).then((response) => response.data);
}

export function claimGift(token: string, address: { line: string; locality: string; postal_code: string; country: string }): Promise<{ status: string }> {
  return api<{ data: { status: string } }>(`/gifts/${encodeURIComponent(token)}/claim`, json('POST', address)).then((response) => response.data);
}

export type Ritual = {
  streak: number;
  checked_in: boolean;
  missions: { kind: 'watch' | 'like' | 'share'; done: boolean }[];
  spin: { available: boolean; odds: { label: string; percent: number }[]; result: { label: string } | null };
};

export function fetchRitual(signal?: AbortSignal): Promise<Ritual> {
  return api<{ data: Ritual }>('/ritual', { signal }).then((response) => response.data);
}

export function checkIn(): Promise<Ritual> {
  return api<{ data: Ritual }>('/ritual/check-in', json('POST')).then((response) => response.data);
}

export function completeMission(kind: string): Promise<Ritual> {
  return api<{ data: Ritual }>(`/ritual/missions/${encodeURIComponent(kind)}`, json('POST')).then((response) => response.data);
}

export function spinPrize(): Promise<{ replayed: boolean; result: { label: string } }> {
  return api<{ data: { replayed: boolean; result: { label: string } } }>('/ritual/spin', json('POST')).then((response) => response.data);
}

export function renderTryOn(productId: string): Promise<{ id: string; image_url: string; renderer: string }> {
  return api<{ data: { id: string; image_url: string; renderer: string } }>('/try-on', json('POST', { product_id: productId })).then((response) => response.data);
}

export function openTryOnPoll(renderId: string): Promise<{ token: string; url: string; wear: number; skip: number }> {
  return api<{ data: { token: string; url: string; wear: number; skip: number } }>(`/try-on/${encodeURIComponent(renderId)}/poll`, json('POST')).then((response) => response.data);
}

export type ClosetRow = { id: string; title: string; image_url: string; status: 'owned' | 'listed'; estimate: DisplayMoney; listing_price: DisplayMoney | null };

export function fetchCloset(signal?: AbortSignal): Promise<ClosetRow[]> {
  return api<Envelope<ClosetRow[]>>('/closet', { signal }).then((response) => response.data);
}

export function listClosetItem(itemId: string): Promise<{ status: string; listing_price: DisplayMoney }> {
  return api<{ data: { status: string; listing_price: DisplayMoney } }>(`/closet/${encodeURIComponent(itemId)}/list`, json('POST')).then((response) => response.data);
}

export type LiveAuction = { id: string; title: string; current: DisplayMoney; next_minor: number; leader: string | null; ends_at: string };

export function fetchLiveAuction(signal?: AbortSignal): Promise<LiveAuction | null> {
  return api<{ data: { auction: LiveAuction | null } }>('/auctions/live', { signal }).then((response) => response.data.auction);
}

export function placeBid(auctionId: string, amountMinor: number): Promise<LiveAuction> {
  return api<{ data: LiveAuction }>(`/auctions/${encodeURIComponent(auctionId)}/bids`, json('POST', { amount_minor: amountMinor })).then((response) => response.data);
}

export type BlindBox = { id: string; title: string; price: DisplayMoney; odds: { label: string; percent: number }[] };

export function fetchBlindBox(signal?: AbortSignal): Promise<BlindBox | null> {
  return api<{ data: { box: BlindBox | null } }>('/blind-boxes', { signal }).then((response) => response.data.box);
}

/** Pays for one pull; the product is revealed only after the payment is captured. */
export async function pullBlindBox(boxId: string, idempotencyKey: string): Promise<BlindBoxPull> {
  return (await api<Envelope<BlindBoxPull>>(`/blind-boxes/${id(boxId)}/pull`, keyed(idempotencyKey))).data;
}

export function joinSquad(squadId: string): Promise<{ id: string; members: number }> {
  return api<{ data: { id: string; members: number } }>(`/squads/${id(squadId)}/join`, json('POST')).then((response) => response.data);
}

export function fetchRewards(signal?: AbortSignal): Promise<{ coins: number; xp: number; cash: DisplayMoney; coupons: { id: string; label: string; discount_bps: number }[] }> {
  return api<{ data: { coins: number; xp: number; cash: DisplayMoney; coupons: { id: string; label: string; discount_bps: number }[] } }>('/me/rewards', { signal }).then((response) => response.data);
}

export function openLootBox(): Promise<{ coupon: { id: string; label: string; discount_bps: number }; coins: number }> {
  return api<{ data: { coupon: { id: string; label: string; discount_bps: number }; coins: number } }>('/rewards/loot-box', json('POST')).then((response) => response.data);
}

export function recordWatch(postId: string, watchedMs: number, durationMs: number): Promise<{ rewarded: boolean }> {
  return api<{ data: { rewarded: boolean } }>(`/feed/${id(postId)}/watch`, json('POST', { watched_ms: watchedMs, duration_ms: durationMs })).then((response) => response.data);
}

export function fetchActivity(signal?: AbortSignal): Promise<{ id: string; username: string; product_title: string }[]> {
  return api<{ data: { id: string; username: string; product_title: string }[] }>('/activity', { signal }).then((response) => response.data);
}

export function fetchTryOn(productId: string): Promise<{ available: boolean; mode: string; asset_url: string | null }> {
  return api<{ data: { available: boolean; mode: string; asset_url: string | null } }>(`/products/${id(productId)}/try-on`).then((response) => response.data);
}

export function fetchStore(userId: string, signal?: AbortSignal): Promise<StoreProduct[]> {
  return api<Envelope<StoreProduct[]>>(`/users/${id(userId)}/store`, { signal }).then((response) => response.data);
}

/** The flash drop running now, if any. Read-only: drops are scheduled by the server, never by a client. */
export async function fetchLiveDrop(signal?: AbortSignal): Promise<LiveFlashDrop> {
  return (await api<Envelope<LiveFlashDrop>>('/drops/live', { signal })).data;
}

// --------------------------------------------------------------------------------------- product graph

/** Every verified seller's listing of the same product, cheapest landed total (to `country`) first. */
export async function fetchProductOffers(productId: string, country: string | null, signal?: AbortSignal): Promise<ProductOffers> {
  return (await api<Envelope<ProductOffers>>(`/products/${id(productId)}/offers${query({ country })}`, { signal })).data;
}

/** Same product from other sellers, tagged together in reels, and bought together. */
export async function fetchRelatedProducts(productId: string, signal?: AbortSignal): Promise<RelatedProducts> {
  return (await api<Envelope<RelatedProducts>>(`/products/${id(productId)}/related`, { signal })).data;
}

export function fetchEngagement(postId: string, signal?: AbortSignal): Promise<Engagement> {
  return api<Envelope<Engagement>>(`/feed/${id(postId)}/engagement`, { signal }).then((body) => body.data);
}

/** Oldest first. Top-level comments by default; `parentId` lists the replies of one comment. */
export function fetchComments(postId: string, cursor: string | null, parentId: string | null, signal?: AbortSignal): Promise<CommentPage> {
  return api<CommentPage>(`/feed/${id(postId)}/comments${query({ cursor, parent_id: parentId, limit: COMMENT_PAGE_SIZE })}`, { signal });
}

export async function createComment(postId: string, body: string, parentId: string | null = null): Promise<{ comment: FeedComment; commentsCount: number }> {
  const response = await api<{ data: FeedComment; meta?: { comments_count?: number } }>(
    `/feed/${id(postId)}/comments`,
    json('POST', { body, parent_id: parentId }),
  );
  return { comment: response.data, commentsCount: response.meta?.comments_count ?? 0 };
}

export async function deleteComment(commentId: string): Promise<number> {
  const response = await api<Envelope<{ comments_count: number }>>(`/comments/${id(commentId)}`, json('DELETE'));
  return response.data.comments_count;
}

// ------------------------------------------------------------------------------------------- profiles

export function fetchProfile(userId: string | null, signal?: AbortSignal): Promise<UserProfile> {
  const path = userId === null ? '/me' : `/users/${id(userId)}`;
  return api<Envelope<UserProfile>>(path, { signal }).then((body) => body.data);
}

export function fetchUserPosts(userId: string, cursor: string | null, signal?: AbortSignal): Promise<FeedPage> {
  return api<FeedPage>(`/users/${id(userId)}/posts${query({ cursor, limit: FEED_PAGE_SIZE })}`, { signal });
}

// ------------------------------------------------------------------------------------- visual search

/**
 * A prepared scan image. Native platforms stream the file from disk as multipart; the web build sends a
 * data URI because browsers cannot attach a file by path. `sha256` is the hex SHA-256 of the exact bytes
 * sent: the server refuses the scan (IMAGE_HASH_MISMATCH) if what arrived is not what the phone encoded.
 */
export type ScanUpload =
  | { kind: 'file'; uri: string; name: string; type: 'image/jpeg' | 'image/png' | 'image/webp'; sha256: string | null }
  | { kind: 'base64'; dataUri: string; sha256: string | null };

export async function searchByImage(upload: ScanUpload, category: string | null, signal?: AbortSignal): Promise<VisualSearchResult> {
  if (upload.kind === 'base64') {
    const init = json('POST', { image_base64: upload.dataUri, image_sha256: upload.sha256 ?? undefined, category: category ?? undefined });
    // A scan is a read: retrying an unknown outcome repeats a search, never a side effect that matters.
    return (await api<Envelope<VisualSearchResult>>('/visual-search', { ...init, signal }, { retries: 1 })).data;
  }

  const form = new FormData();
  // React Native's FormData accepts { uri, name, type } and streams the file; the DOM typings only know Blob.
  form.append('image', { uri: upload.uri, name: upload.name, type: upload.type } as unknown as Blob);
  if (upload.sha256) {
    form.append('image_sha256', upload.sha256);
  }
  if (category) {
    form.append('category', category);
  }
  return (await api<Envelope<VisualSearchResult>>('/visual-search', { method: 'POST', body: form, signal }, { retries: 1 })).data;
}

// ------------------------------------------------------------------------------------------ checkout

export type OneTapParams = {
  productId: string;
  variantSku: string | null;
  quantity: number;
};

/**
 * POST /checkout/one-tap. `idempotencyKey` is generated once per tap and reused for every retry of that
 * tap: the server answers a repeat with the order it already created instead of charging twice.
 */
export async function oneTapCheckout(params: OneTapParams, idempotencyKey: string): Promise<OneTapResponse> {
  const body = { product_id: params.productId, variant_sku: params.variantSku, quantity: params.quantity };
  return (await api<Envelope<OneTapResponse>>('/checkout/one-tap', json('POST', body, { 'Idempotency-Key': idempotencyKey }))).data;
}

export async function fetchOrder(orderId: string, signal?: AbortSignal): Promise<OrderSummary> {
  return (await api<Envelope<OrderSummary>>(`/orders/${id(orderId)}`, { signal })).data;
}

/**
 * Asks the server to fetch the payment's state from Stripe now (after 3-D Secure, or while pending) and
 * returns the order with it. While 3-D Secure is still open the answer carries a fresh next_action.
 */
export async function syncOrder(orderId: string): Promise<OrderSummary> {
  // Naturally idempotent (it only reads Stripe and applies forward-only transitions): safe to retry.
  return (await api<Envelope<OrderSummary>>(`/orders/${id(orderId)}/sync`, json('POST'), { retries: 2 })).data;
}

/** Undo inside the window: cancels the card authorization. Nothing was charged. */
export async function cancelOrder(orderId: string, idempotencyKey: string): Promise<OrderSummary> {
  return (await api<Envelope<OrderSummary>>(`/orders/${id(orderId)}/cancel`, keyed(idempotencyKey))).data;
}

// ----------------------------------------------------------------------------------- payment methods

export async function fetchDefaultPaymentMethod(signal?: AbortSignal): Promise<DefaultPaymentMethod> {
  return (await api<Envelope<DefaultPaymentMethod>>('/payment-methods/default', { signal })).data;
}

/** A SetupIntent for PaymentSheet. A replay returns `client_secret: null`: start again with a new key. */
export async function beginPaymentMethodSetup(idempotencyKey: string): Promise<PaymentSetup> {
  return (await api<Envelope<PaymentSetup>>('/payment-methods/setup', keyed(idempotencyKey))).data;
}

/** After PaymentSheet succeeds: the server verifies the SetupIntent with Stripe and makes it the default. */
export async function confirmPaymentMethodSetup(setupIntentId: string, idempotencyKey: string): Promise<PaymentSetupConfirmation> {
  return (await api<Envelope<PaymentSetupConfirmation>>('/payment-methods/confirm', keyed(idempotencyKey, { setup_intent_id: setupIntentId }))).data;
}
