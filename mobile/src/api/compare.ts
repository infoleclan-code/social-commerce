import { api, query } from '@/api/client';

export type CompareCoupon = { code: string; label: string };

export type CompareOffer = {
  id: string;
  store: string;
  title: string;
  price_minor: number;
  shipping_minor: number | null;
  shipping_known: boolean;
  tax_minor: number | null;
  tax_known: boolean;
  tax_included: boolean;
  total_minor: number;
  total_known: boolean;
  currency: string;
  availability: 'in_stock' | 'out_of_stock';
  checked_at: string;
  rating: number | null;
  review_count: number | null;
  trust: string;
  ships_to_user: boolean | null;
  coupons: CompareCoupon[];
};

export type CompareProduct = {
  brand: string;
  model: string;
  size: string | null;
  title: string;
  offers: CompareOffer[];
  unavailable?: CompareOffer[];
};

export type CompareResult = {
  query: string;
  currency: string;
  minor_unit: number;
  country: string;
  checked_at: string;
  products: CompareProduct[];
  detected_query?: string;
};

export type PricePoint = { date: string; low_minor: number };

export type PriceHistory = {
  product_key: string;
  currency: string;
  minor_unit: number;
  hint: string;
  low_30_minor: number | null;
  avg_90_minor: number | null;
  points: PricePoint[];
};

export type WishlistItem = {
  id: string;
  query: string;
  brand: string | null;
  size: string | null;
  currency: string;
  country: string | null;
  target_minor: number | null;
  best_total_minor: number | null;
  notify: boolean;
};

export type ShopChoice = { code: string; name: string; currency: string };

export type ShopSettings = {
  country: string;
  currency: string;
  detected_country: string;
  detected_currency: string;
  source: string;
  choices: ShopChoice[];
  currencies: string[];
};

function exponent(currency: string | undefined): number {
  return currency === 'JPY' || currency === 'KRW' || currency === 'VND' ? 0 : 2;
}

export function dollarsToMinor(value: string | undefined, currency?: string): string | undefined {
  const trimmed = value?.trim() ?? '';
  const scale = exponent(currency);
  if (scale === 0) {
    return /^\d+$/.test(trimmed) ? trimmed : undefined;
  }
  if (trimmed === '' || !/^\d+(\.\d{1,2})?$/.test(trimmed)) {
    return undefined;
  }
  const [whole, fraction = ''] = trimmed.split('.');
  return String(Number(whole) * 100 + Number((fraction + '00').slice(0, 2)));
}

export function searchCompare(input: {
  q: string;
  brand?: string;
  size?: string;
  min_minor?: string;
  max_minor?: string;
  in_stock?: boolean;
  sort?: string;
  currency?: string;
  country?: string;
}): Promise<{ data: CompareResult }> {
  return api(`/compare${query({
    q: input.q,
    brand: input.brand,
    size: input.size,
    min_minor: dollarsToMinor(input.min_minor, input.currency),
    max_minor: dollarsToMinor(input.max_minor, input.currency),
    in_stock: input.in_stock === false ? '0' : '1',
    sort: input.sort,
    currency: input.currency,
    country: input.country,
  })}`);
}

export function fetchHistory(input: {
  brand: string;
  model: string;
  size?: string | null;
  currency?: string;
  current_minor?: number;
}): Promise<{ data: PriceHistory }> {
  return api(`/compare/history${query({
    brand: input.brand,
    model: input.model,
    size: input.size,
    currency: input.currency,
    current_minor: input.current_minor,
  })}`);
}

export function searchByPhoto(body: FormData): Promise<{ data: CompareResult }> {
  return api('/compare/visual', { method: 'POST', body });
}

export function openStore(offerId: string): Promise<{ data: { url: string } }> {
  return api('/compare/clicks', { method: 'POST', body: JSON.stringify({ offer_id: offerId }), headers: { 'Content-Type': 'application/json' } });
}

export function fetchWishlist(): Promise<{ data: WishlistItem[] }> {
  return api('/wishlist');
}

export function saveWishlist(body: {
  query: string;
  brand?: string;
  size?: string;
  currency?: string;
  country?: string;
  target_minor?: number | null;
}): Promise<{ data: WishlistItem }> {
  return api('/wishlist', { method: 'POST', body: JSON.stringify(body), headers: { 'Content-Type': 'application/json' } });
}

export function removeWishlist(id: string): Promise<void> {
  return api(`/wishlist/${encodeURIComponent(id)}`, { method: 'DELETE' });
}

export function fetchShop(): Promise<{ data: ShopSettings }> {
  return api('/shop');
}

export function saveShop(body: { country: string; currency: string }): Promise<{ data: ShopSettings }> {
  return api('/shop', { method: 'PUT', body: JSON.stringify(body), headers: { 'Content-Type': 'application/json' } });
}
