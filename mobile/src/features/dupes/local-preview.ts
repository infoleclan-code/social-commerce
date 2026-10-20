import type { DupeResult, VisionMatch } from '@/services/ai-vision';

type PreviewPiece = {
  id: string;
  title: string;
  brand: string;
  category: string;
  color: string;
  material: string;
  silhouette: string;
  brandVibe: string;
  image: string;
  checkoutUrl: string;
  amountMinor: number;
  variants: string[];
};

const PREVIEW_SCORES = [96, 91, 88] as const;

const coats: PreviewPiece[] = [
  {
    id: 'column-coat',
    title: 'Column coat',
    brand: 'Nord',
    category: 'apparel.outerwear.coats',
    color: 'camel',
    material: 'wool',
    silhouette: 'column',
    brandVibe: 'tailored',
    image: 'https://images.unsplash.com/photo-1539533018447-63fcce2678e3?auto=format&fit=crop&w=900&q=80',
    checkoutUrl: 'https://merchant.example/column-coat',
    amountMinor: 89_000,
    variants: ['XS', 'S', 'M', 'L'],
  },
  {
    id: 'camel-wool-coat',
    title: 'Camel wool coat',
    brand: 'Atelier',
    category: 'apparel.outerwear.coats',
    color: 'camel',
    material: 'wool',
    silhouette: 'relaxed',
    brandVibe: 'classic',
    image: 'https://images.unsplash.com/photo-1544022613-e87ca75a784a?auto=format&fit=crop&w=900&q=80',
    checkoutUrl: 'https://merchant.example/camel-wool-coat',
    amountMinor: 64_000,
    variants: ['S', 'M', 'L'],
  },
  {
    id: 'column-overcoat',
    title: 'Column overcoat',
    brand: 'Field',
    category: 'apparel.outerwear.coats',
    color: 'camel',
    material: 'wool',
    silhouette: 'column',
    brandVibe: 'utilitarian',
    image: 'https://images.unsplash.com/photo-1591047139829-d91aecb6caea?auto=format&fit=crop&w=900&q=80',
    checkoutUrl: 'https://merchant.example/column-overcoat',
    amountMinor: 51_000,
    variants: ['S', 'M', 'L', 'XL'],
  },
];

function money(amountMinor: number) {
  return { amount_minor: amountMinor, currency: 'EUR', minor_unit: 2 };
}

function toDupe(piece: PreviewPiece, score: number, originalMinor: number): DupeResult {
  return {
    id: piece.id,
    merchantId: `merchant-${piece.brand.toLowerCase()}`,
    merchantName: piece.brand,
    title: piece.title,
    similarityScore: score,
    visualMatchConfidence: 93,
    originalPrice: money(originalMinor),
    dupePrice: money(piece.amountMinor),
    merchantUrl: piece.checkoutUrl,
    image: piece.image,
    variants: piece.variants,
    attributes: {
      category: piece.category,
      color: piece.color,
      fabric: piece.material,
      fit: piece.silhouette,
      style: piece.brandVibe,
    },
  };
}

/** Instant catalog preview used when no vision endpoint is configured. */
export function localPreviewMatch(): VisionMatch {
  const lead = coats[0];
  if (!lead) {
    throw new Error('Preview catalog is empty');
  }
  return {
    features: {
      category: lead.category,
      color: lead.color,
      material: lead.material,
      silhouette: lead.silhouette,
      brandVibe: lead.brandVibe,
    },
    confidenceScore: 93,
    attributes: {
      category: lead.category,
      color: lead.color,
      fabric: lead.material,
      fit: lead.silhouette,
      style: lead.brandVibe,
    },
    exact: null,
    dupes: coats.map((piece, index) => toDupe(piece, PREVIEW_SCORES[index] ?? 88, lead.amountMinor)),
  };
}
