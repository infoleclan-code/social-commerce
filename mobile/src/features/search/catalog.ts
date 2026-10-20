import type { DetectionKind, NormalizedBox, SocialSource } from '@/features/search/types';

/**
 * Demo-mode product bounds per category, in normalized image coordinates. Used only when no matcher
 * is configured or a live response carries no bounding box; a live box always wins.
 */
export const DEMO_BOUNDS: Record<DetectionKind, NormalizedBox> = {
  coat: { x: 0.24, y: 0.08, width: 0.52, height: 0.84 },
  shoes: { x: 0.1, y: 0.2, width: 0.8, height: 0.6 },
  bag: { x: 0.2, y: 0.16, width: 0.6, height: 0.68 },
};

/** Demo-mode visual-match confidence per category (0..100). */
export const DEMO_CONFIDENCE: Record<DetectionKind, number> = {
  coat: 99,
  shoes: 99,
  bag: 91,
};

const COAT = 'https://images.unsplash.com/photo-1539533018447-63fcce2678e3?auto=format&fit=crop&w=1200&q=80';
const BAG = 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=1200&q=80';
const ALPHAFLY = 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=1200&q=80';

export function savingsPercent(originalMinor: number, dupeMinor: number): number {
  if (originalMinor <= 0 || dupeMinor < 0 || dupeMinor >= originalMinor) {
    return 0;
  }
  return Math.floor(((originalMinor - dupeMinor) * 100) / originalMinor);
}

export const FRAME_BY_SOURCE: Record<SocialSource, { image: string; kind: DetectionKind }> = {
  tiktok: { image: COAT, kind: 'coat' },
  instagram: { image: ALPHAFLY, kind: 'shoes' },
  youtube: { image: BAG, kind: 'bag' },
};
