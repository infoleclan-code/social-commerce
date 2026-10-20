import { DEMO_BOUNDS, DEMO_CONFIDENCE } from '@/features/search/catalog';
import { featuresForKind } from '@/features/search/match-offers';
import type { DetectionKind, MerchantOffer, OfferSet, PartnerId, ScanFeatures } from '@/features/search/types';
import { t } from '@/lib/i18n';
import type { DisplayMoney } from '@/lib/money';
import { analyzeFrame, VisionError, type DupeResult, type VisionMatch } from '@/services/ai-vision';
import { visionEndpointConfigured } from '@/services/keys';

/**
 * Best-price engine for visual search.
 *
 * Every result set is exactly three offers, one per partner store (StockX, GOAT, Farfetch), sorted
 * strictly ascending by price. Prices come from the matcher; nothing here invents a live price. A
 * partner without a live listing keeps its slot with `price: null` and sorts last. With no matcher
 * configured the engine serves the local demo catalog, flagged `source: 'demo'` so the UI can say so.
 */

export const PARTNERS: readonly { id: PartnerId; name: string }[] = [
  { id: 'stockx', name: 'StockX' },
  { id: 'goat', name: 'GOAT' },
  { id: 'farfetch', name: 'Farfetch' },
];

export type ScanFailure = 'timeout' | 'unavailable' | 'invalid' | 'upload' | 'empty';

export class ScanError extends Error {
  readonly reason: ScanFailure;

  constructor(reason: ScanFailure) {
    super(reason);
    this.name = 'ScanError';
    this.reason = reason;
  }
}

export function failureOf(error: unknown): ScanFailure {
  if (error instanceof ScanError || error instanceof VisionError) {
    return error.reason;
  }
  return 'unavailable';
}

/** Retry once on transport trouble. A malformed payload or a missing upload will not fix itself. */
export function shouldRetry(failureCount: number, error: unknown): boolean {
  const reason = failureOf(error);
  return failureCount < 1 && (reason === 'timeout' || reason === 'unavailable');
}

/** FNV-1a (32-bit) of the URI plus its length: a short, stable cache key. Not a security hash. */
export function imageKeyOf(uri: string): string {
  let hash = 0x811c9dc5;
  for (let index = 0; index < uri.length; index += 1) {
    hash ^= uri.charCodeAt(index);
    hash = Math.imul(hash, 0x01000193);
  }
  return `${(hash >>> 0).toString(36)}-${uri.length.toString(36)}`;
}

function partnerRank(partner: PartnerId): number {
  return PARTNERS.findIndex((item) => item.id === partner);
}

/** The currency most priced offers share. The API sends one display currency; this only guards a mixed payload. */
function dominantCurrency(offers: readonly MerchantOffer[]): string | null {
  const counts = new Map<string, number>();
  for (const offer of offers) {
    if (offer.price) {
      counts.set(offer.price.currency, (counts.get(offer.price.currency) ?? 0) + 1);
    }
  }
  let best: string | null = null;
  let bestCount = 0;
  for (const [currency, count] of counts) {
    if (count > bestCount) {
      best = currency;
      bestCount = count;
    }
  }
  return best;
}

/**
 * Strictly ascending by price. Amounts are compared only within one currency (no FX on the device):
 * offers in the dominant currency first, other currencies next, stores without a listing last.
 * Equal prices keep partner order, so the ranking is deterministic.
 */
export function rankOffers(offers: readonly MerchantOffer[]): MerchantOffer[] {
  const currency = dominantCurrency(offers);
  const bucket = (offer: MerchantOffer): number => {
    if (offer.price === null) {
      return 2;
    }
    return offer.price.currency === currency ? 0 : 1;
  };
  return [...offers].sort((left, right) => {
    const byBucket = bucket(left) - bucket(right);
    if (byBucket !== 0) {
      return byBucket;
    }
    if (left.price && right.price && left.price.currency === right.price.currency) {
      const byPrice = left.price.amount_minor - right.price.amount_minor;
      if (byPrice !== 0) {
        return byPrice;
      }
    }
    return partnerRank(left.partner) - partnerRank(right.partner);
  });
}

// Demo catalog -----------------------------------------------------------------------------------

const DEMO_ORIGINAL_MINOR: Record<DetectionKind, number> = {
  shoes: 50_000,
  coat: 89_000,
  bag: 28_000,
};

/** Demo quotes as a share of the original price, in basis points. */
const DEMO_QUOTE_BPS: Record<PartnerId, number> = {
  stockx: 3_200,
  goat: 3_800,
  farfetch: 4_400,
};

const DEMO_VARIANTS: Record<DetectionKind, readonly string[]> = {
  shoes: ['40', '41', '42', '43'],
  coat: ['S', 'M', 'L'],
  bag: ['One size'],
};

function eur(amountMinor: number): DisplayMoney {
  return { amount_minor: amountMinor, currency: 'EUR', minor_unit: 2 };
}

export function demoOfferSet(features: ScanFeatures, imageUrl: string, imageKey: string): OfferSet {
  const kind = features.category;
  const original = DEMO_ORIGINAL_MINOR[kind];
  const offers = PARTNERS.map((partner): MerchantOffer => ({
    id: `${imageKey}-${partner.id}`,
    partner: partner.id,
    merchantName: partner.name,
    title: t(features.productKey),
    image: imageUrl,
    merchantUrl: `https://merchant.example/${partner.id}/${kind}`,
    price: eur(Math.floor((original * DEMO_QUOTE_BPS[partner.id]) / 10_000)),
    originalPrice: eur(original),
    similarityScore: DEMO_CONFIDENCE[kind],
    variants: [...DEMO_VARIANTS[kind]],
  }));
  return {
    source: 'demo',
    kind,
    offers: rankOffers(offers),
    box: DEMO_BOUNDS[kind],
    confidence: DEMO_CONFIDENCE[kind],
  };
}

// Live results -----------------------------------------------------------------------------------

const KIND_WORDS: readonly (readonly [DetectionKind, RegExp])[] = [
  ['shoes', /shoe|sneaker|trainer|cleat|boot|footwear|loafer|heel/],
  ['coat', /coat|jacket|parka|trench|outerwear|blazer/],
  ['bag', /bag|tote|handbag|clutch|backpack|purse/],
];

function kindOfText(text: string): DetectionKind | null {
  const lower = text.toLowerCase();
  for (const [kind, words] of KIND_WORDS) {
    if (words.test(lower)) {
      return kind;
    }
  }
  return null;
}

function partnerOf(listing: DupeResult): PartnerId | null {
  const tokens = `${listing.merchantId} ${listing.merchantName}`.toLowerCase().split(/[^a-z0-9]+/);
  return PARTNERS.find((partner) => tokens.includes(partner.id))?.id ?? null;
}

type Listing = {
  result: DupeResult;
  price: DisplayMoney;
};

function cheaper(candidate: DisplayMoney, current: DisplayMoney): boolean {
  return candidate.currency === current.currency && candidate.amount_minor < current.amount_minor;
}

/** Maps a matcher response onto the three partner slots: the cheapest matching listing per store. */
export function liveOfferSet(match: VisionMatch, scanned: ScanFeatures, imageUrl: string, imageKey: string): OfferSet {
  const kind = kindOfText(match.features.category) ?? scanned.category;
  const best = new Map<PartnerId, Listing>();
  const consider = (result: DupeResult, price: DisplayMoney | null) => {
    const partner = partnerOf(result);
    if (!partner || !price || price.amount_minor <= 0) {
      return;
    }
    const listingKind = kindOfText(`${result.attributes.category} ${result.title}`);
    if (listingKind !== null && listingKind !== kind) {
      return;
    }
    const current = best.get(partner);
    if (!current || cheaper(price, current.price)) {
      best.set(partner, { result, price });
    }
  };
  // The exact match sells at its original price; dupes at their dupe price.
  if (match.exact) {
    consider(match.exact, match.exact.originalPrice);
  }
  for (const dupe of match.dupes) {
    consider(dupe, dupe.dupePrice);
  }

  const reference = match.exact?.originalPrice ?? null;
  const fallbackTitle = match.exact?.title ?? t(featuresForKind(kind).productKey);
  const offers = PARTNERS.map((partner): MerchantOffer => {
    const listing = best.get(partner.id);
    if (!listing) {
      return {
        id: `${imageKey}-${partner.id}`,
        partner: partner.id,
        merchantName: partner.name,
        title: fallbackTitle,
        image: imageUrl,
        merchantUrl: null,
        price: null,
        originalPrice: reference,
        similarityScore: 0,
        variants: [],
      };
    }
    const isExact = listing.result === match.exact;
    return {
      id: `${imageKey}-${partner.id}`,
      partner: partner.id,
      merchantName: partner.name,
      title: listing.result.title,
      image: listing.result.image || imageUrl,
      merchantUrl: listing.result.merchantUrl,
      price: listing.price,
      originalPrice: isExact ? null : (listing.result.originalPrice ?? reference),
      similarityScore: listing.result.similarityScore,
      variants: listing.result.variants.length > 0 ? listing.result.variants : ['One size'],
    };
  });

  if (offers.every((offer) => offer.price === null)) {
    throw new ScanError('empty');
  }
  return {
    source: 'live',
    kind,
    offers: rankOffers(offers),
    box: match.boundingBox ?? null,
    confidence: match.confidenceScore,
  };
}

type OfferRequest = {
  imageUrl: string;
  imageKey: string;
  features: ScanFeatures;
};

/** Demo catalog without a matcher; otherwise the matcher. Local photos need an upload step first. */
export async function fetchOfferSet(request: OfferRequest, signal: AbortSignal): Promise<OfferSet> {
  if (!visionEndpointConfigured) {
    return demoOfferSet(request.features, request.imageUrl, request.imageKey);
  }
  if (!/^https?:\/\//i.test(request.imageUrl)) {
    throw new ScanError('upload');
  }
  const match = await analyzeFrame({ imageUrl: request.imageUrl }, signal);
  return liveOfferSet(match, request.features, request.imageUrl, request.imageKey);
}
