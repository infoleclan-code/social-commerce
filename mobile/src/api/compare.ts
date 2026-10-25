import { api, query } from '@/api/client';

export type CompareOffer = {
  id: string;
  store: string;
  title: string;
  price_minor: number;
  shipping_minor: number | null;
  shipping_known: boolean;
  total_minor: number;
  total_known: boolean;
  currency: string;
  availability: 'in_stock' | 'out_of_stock';
  checked_at: string;
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
  checked_at: string;
  products: CompareProduct[];
};

export type WishlistItem = {
  id: string;
  query: string;
  brand: string | null;
  size: string | null;
  currency: string;
  best_total_minor: number | null;
  notify: boolean;
};

export function searchCompare(input: {
  q: string;
  brand?: string;
  size?: string;
  min_minor?: string;
  max_minor?: string;
  in_stock?: boolean;
  sort?: string;
  currency?: string;
}): Promise<{ data: CompareResult }> {
  return api(`/compare${query({
    q: input.q,
    brand: input.brand,
    size: input.size,
    min_minor: input.min_minor,
    max_minor: input.max_minor,
    in_stock: input.in_stock === false ? '0' : '1',
    sort: input.sort,
    currency: input.currency,
  })}`);
}

export function openStore(offerId: string): Promise<{ data: { url: string } }> {
  return api('/compare/clicks', { method: 'POST', body: JSON.stringify({ offer_id: offerId }), headers: { 'Content-Type': 'application/json' } });
}

export function fetchWishlist(): Promise<{ data: WishlistItem[] }> {
  return api('/wishlist');
}

export function saveWishlist(body: { query: string; brand?: string; size?: string; currency?: string }): Promise<{ data: WishlistItem }> {
  return api('/wishlist', { method: 'POST', body: JSON.stringify(body), headers: { 'Content-Type': 'application/json' } });
}

export function removeWishlist(id: string): Promise<void> {
  return api(`/wishlist/${encodeURIComponent(id)}`, { method: 'DELETE' });
}
