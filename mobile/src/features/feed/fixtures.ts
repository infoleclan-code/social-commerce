import { Footprints, Gem, Glasses, Shirt, Sparkles, Watch, type LucideIcon } from 'lucide-react-native';

import type { DisplayMoney } from '@/lib/money';

const hourMs = 60 * 60 * 1000;

export type FeedDrop = {
  id: string;
  title: string;
  detail: string;
  purchased: number;
  goal: number;
  endsAt: string;
  image: string;
  category: PieceCategory;
  tone: string;
  accent: string;
};

export type SlotAction = 'join' | 'buy';

export const pieceCategories = ['apparel', 'shoes', 'watches', 'jewelry', 'eyewear'] as const;

export type PieceCategory = (typeof pieceCategories)[number];

export type FeedProduct = {
  id: string;
  brand: string;
  name: string;
  showPrice: boolean;
  price: DisplayMoney | null;
  cashbackBadge: string;
  expectedOn: string;
  image: string;
  gallery: string[];
  slotId: string;
  purchased: number;
  goal: number;
  endsAt: string;
  action: SlotAction;
  category: PieceCategory;
  tone: string;
  accent: string;
  icon: LucideIcon;
};

export function resolveSlot(slotId: string): { purchased: number; goal: number; endsAt: string } {
  const drop = feedDrops.find((item) => item.id === slotId);
  if (drop) {
    return { purchased: drop.purchased, goal: drop.goal, endsAt: drop.endsAt };
  }
  const product = feedProducts.find((item) => item.slotId === slotId);
  if (product) {
    return { purchased: product.purchased, goal: product.goal, endsAt: product.endsAt };
  }
  return { purchased: 0, goal: 1, endsAt: new Date(Date.now() + hourMs).toISOString() };
}

export type SlotOffering = {
  title: string;
  eyebrow: string;
  image: string;
  showPrice: boolean;
  price: DisplayMoney | null;
  cashbackBadge: string | null;
  expectedOn: string;
  endsAt: string;
  purchased: number;
  goal: number;
};

export function offeringForSlot(slotId: string): SlotOffering | null {
  const slot = resolveSlot(slotId);
  const drop = feedDrops.find((item) => item.id === slotId);
  if (drop) {
    const linked = feedProducts.find((item) => item.slotId === drop.id);
    return {
      title: drop.title,
      eyebrow: drop.detail,
      image: drop.image,
      showPrice: false,
      price: null,
      cashbackBadge: linked?.cashbackBadge ?? null,
      expectedOn: linked?.expectedOn ?? '2026-11-03',
      endsAt: drop.endsAt,
      purchased: drop.purchased,
      goal: drop.goal,
    };
  }
  const product = feedProducts.find((item) => item.slotId === slotId);
  if (!product) {
    return null;
  }
  return {
    title: product.name,
    eyebrow: product.brand,
    image: product.image,
    showPrice: product.showPrice,
    price: product.price,
    cashbackBadge: product.cashbackBadge,
    expectedOn: product.expectedOn,
    endsAt: slot.endsAt,
    purchased: slot.purchased,
    goal: slot.goal,
  };
}

export function variantsFor(slotId: string): string[] {
  if (slotId === 'hour-watch') {
    return ['36 mm', '40 mm'];
  }
  if (slotId === 'signet' || slotId === 'frame') {
    return ['One size'];
  }
  if (slotId === 'loafer') {
    return ['39', '40', '41', '42'];
  }
  return ['XS', 'S', 'M', 'L'];
}

/** Layout fixtures. Badge text and prices are display values, not computed on device. */
export const feedDrops: FeedDrop[] = [
  {
    id: 'coat',
    title: 'Wool coat',
    detail: 'Charcoal',
    purchased: 18,
    goal: 30,
    endsAt: new Date(Date.now() + 5 * hourMs + 24 * 60 * 1000).toISOString(),
    image: 'https://images.unsplash.com/photo-1539533018447-63fcce2678e3?auto=format&fit=crop&w=1400&q=80',
    category: 'apparel',
    tone: '#3A2A24',
    accent: '#C6A36A',
  },
  {
    id: 'loafer',
    title: 'Leather loafer',
    detail: 'Black',
    purchased: 9,
    goal: 24,
    endsAt: new Date(Date.now() + 27 * hourMs).toISOString(),
    image: 'https://images.unsplash.com/photo-1543163521-1bf539c55dd2?auto=format&fit=crop&w=1400&q=80',
    category: 'shoes',
    tone: '#241F1C',
    accent: '#E7D3AE',
  },
];

export const feedProducts: FeedProduct[] = [
  {
    id: 'column-coat',
    brand: 'Nord Atelier',
    name: 'Column coat',
    showPrice: true,
    price: { amount_minor: 89000, currency: 'EUR', minor_unit: 2 },
    cashbackBadge: '12% cashback',
    expectedOn: '2026-11-03',
    image: 'https://images.unsplash.com/photo-1539533018447-63fcce2678e3?auto=format&fit=crop&w=900&q=80',
    gallery: [
      'https://images.unsplash.com/photo-1539533018447-63fcce2678e3?auto=format&fit=crop&w=1400&q=80',
      'https://images.unsplash.com/photo-1544022613-e87ca75a784a?auto=format&fit=crop&w=1400&q=80',
    ],
    slotId: 'coat',
    purchased: 18,
    goal: 30,
    endsAt: new Date(Date.now() + 5 * hourMs).toISOString(),
    action: 'join',
    category: 'apparel',
    tone: '#3A2A24',
    accent: '#C6A36A',
    icon: Shirt,
  },
  {
    id: 'silk-slip',
    brand: 'Maison Rue',
    name: 'Silk slip',
    showPrice: false,
    price: null,
    cashbackBadge: '8% cashback',
    expectedOn: '2026-11-08',
    image: 'https://images.unsplash.com/photo-1515372039744-b8f02a3ae446?auto=format&fit=crop&w=900&q=80',
    gallery: [
      'https://images.unsplash.com/photo-1515372039744-b8f02a3ae446?auto=format&fit=crop&w=1400&q=80',
      'https://images.unsplash.com/photo-1496747611176-843222e1e57c?auto=format&fit=crop&w=1400&q=80',
    ],
    slotId: 'silk-slip',
    purchased: 6,
    goal: 20,
    endsAt: new Date(Date.now() + 14 * hourMs).toISOString(),
    action: 'buy',
    category: 'apparel',
    tone: '#2C2430',
    accent: '#E7D3AE',
    icon: Sparkles,
  },
  {
    id: 'loafer',
    brand: 'Atelier Sole',
    name: 'Leather loafer',
    showPrice: true,
    price: { amount_minor: 42000, currency: 'EUR', minor_unit: 2 },
    cashbackBadge: '10% cashback',
    expectedOn: '2026-11-03',
    image: 'https://images.unsplash.com/photo-1543163521-1bf539c55dd2?auto=format&fit=crop&w=900&q=80',
    gallery: [
      'https://images.unsplash.com/photo-1543163521-1bf539c55dd2?auto=format&fit=crop&w=1400&q=80',
      'https://images.unsplash.com/photo-1533867617858-e7b97e060509?auto=format&fit=crop&w=1400&q=80',
    ],
    slotId: 'loafer',
    purchased: 9,
    goal: 24,
    endsAt: new Date(Date.now() + 27 * hourMs).toISOString(),
    action: 'join',
    category: 'shoes',
    tone: '#2A2622',
    accent: '#C6A36A',
    icon: Footprints,
  },
  {
    id: 'hour-watch',
    brand: 'Field & Co',
    name: 'Hour watch',
    showPrice: true,
    price: { amount_minor: 126000, currency: 'EUR', minor_unit: 2 },
    cashbackBadge: '6% cashback',
    expectedOn: '2026-11-12',
    image: 'https://images.unsplash.com/photo-1524805444758-089113d48a6d?auto=format&fit=crop&w=900&q=80',
    gallery: [
      'https://images.unsplash.com/photo-1524805444758-089113d48a6d?auto=format&fit=crop&w=1400&q=80',
      'https://images.unsplash.com/photo-1523170335258-f5ed11844a49?auto=format&fit=crop&w=1400&q=80',
      'https://images.unsplash.com/photo-1522312346375-d1a52e2b99b3?auto=format&fit=crop&w=1400&q=80',
    ],
    slotId: 'hour-watch',
    purchased: 11,
    goal: 16,
    endsAt: new Date(Date.now() + 8 * hourMs).toISOString(),
    action: 'buy',
    category: 'watches',
    tone: '#1E2428',
    accent: '#E7D3AE',
    icon: Watch,
  },
  {
    id: 'signet',
    brand: 'Lumen',
    name: 'Signet ring',
    showPrice: false,
    price: null,
    cashbackBadge: '15% cashback',
    expectedOn: '2026-11-06',
    image: 'https://images.unsplash.com/photo-1605100804763-247f67b3557e?auto=format&fit=crop&w=900&q=80',
    gallery: [
      'https://images.unsplash.com/photo-1605100804763-247f67b3557e?auto=format&fit=crop&w=1400&q=80',
      'https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?auto=format&fit=crop&w=1400&q=80',
    ],
    slotId: 'signet',
    purchased: 4,
    goal: 12,
    endsAt: new Date(Date.now() + 18 * hourMs).toISOString(),
    action: 'buy',
    category: 'jewelry',
    tone: '#32281C',
    accent: '#C6A36A',
    icon: Gem,
  },
  {
    id: 'frame',
    brand: 'Optic Studio',
    name: 'Acetate frame',
    showPrice: true,
    price: { amount_minor: 24000, currency: 'EUR', minor_unit: 2 },
    cashbackBadge: '9% cashback',
    expectedOn: '2026-11-03',
    image: 'https://images.unsplash.com/photo-1511499767150-a48a237f0083?auto=format&fit=crop&w=900&q=80',
    gallery: [
      'https://images.unsplash.com/photo-1511499767150-a48a237f0083?auto=format&fit=crop&w=1400&q=80',
      'https://images.unsplash.com/photo-1572635196237-14b3f281503f?auto=format&fit=crop&w=1400&q=80',
    ],
    slotId: 'frame',
    purchased: 7,
    goal: 18,
    endsAt: new Date(Date.now() + 11 * hourMs).toISOString(),
    action: 'buy',
    category: 'eyewear',
    tone: '#242820',
    accent: '#E7D3AE',
    icon: Glasses,
  },
];

export type SearchCategory = 'all' | PieceCategory;

function textHit(value: string, query: string): boolean {
  const needle = query.trim().toLowerCase();
  if (!needle) {
    return true;
  }
  return value.toLowerCase().includes(needle);
}

export function productMatches(product: FeedProduct, query: string, category: SearchCategory): boolean {
  if (category !== 'all' && product.category !== category) {
    return false;
  }
  return textHit(`${product.name} ${product.brand}`, query);
}

export function dropMatches(drop: FeedDrop, query: string, category: SearchCategory): boolean {
  if (category !== 'all' && drop.category !== category) {
    return false;
  }
  return textHit(`${drop.title} ${drop.detail}`, query);
}
