import type { DisplayMoney } from '@/lib/money';

export type DetectionKind = 'coat' | 'shoes' | 'bag';

/** A rectangle in normalized image coordinates: 0..1 of the decoded image's width and height. */
export type NormalizedBox = {
  x: number;
  y: number;
  width: number;
  height: number;
};

/** Decoded pixel size of the scanned image, used to map normalized boxes onto the screen. */
export type ImageSize = {
  width: number;
  height: number;
};

/** Pipeline stages the scanner HUD narrates, in order. */
export type ScanStage = 'embed' | 'geometry' | 'match' | 'locked';

export type SocialSource = 'tiktok' | 'instagram' | 'youtube';

export type ScanFeatures = {
  category: DetectionKind;
  brand: string;
  color: string;
  silhouette: string;
  productKey: 'search.product.alphafly' | 'search.product.coat' | 'search.product.bag';
  labelKey: 'search.profile.alphafly' | 'search.profile.coat' | 'search.profile.bag';
};

export type ExtractedFrame = {
  source: SocialSource;
  imageUrl: string;
  kind: DetectionKind;
};

/** The three partner stores every result set covers. */
export type PartnerId = 'stockx' | 'goat' | 'farfetch';

/** `live` came from the matcher; `demo` is the local catalog used when no matcher is configured. */
export type OfferSource = 'live' | 'demo';

/** One store's offer for the scanned product. `price` is null when that store has no live listing. */
export type MerchantOffer = {
  id: string;
  partner: PartnerId;
  merchantName: string;
  title: string;
  image: string;
  merchantUrl: string | null;
  price: DisplayMoney | null;
  originalPrice: DisplayMoney | null;
  similarityScore: number;
  variants: string[];
};

/** Exactly three offers (one per partner, ranked) plus what the matcher said about the image. */
export type OfferSet = {
  source: OfferSource;
  kind: DetectionKind;
  offers: MerchantOffer[];
  box: NormalizedBox | null;
  confidence: number;
};
