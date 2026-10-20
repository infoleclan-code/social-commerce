import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';

import { queryKeys } from '@/api/keys';
import { localPreviewMatch } from '@/features/dupes/local-preview';
import { analyzeFrame, type DupeResult, type VisionMatch } from '@/services/ai-vision';
import { visionEndpointConfigured } from '@/services/keys';

export type DupeSearchFilters = {
  colorMatch: boolean;
  fabric: string;
  budgetMinor: number | null;
  currency: string | null;
};

function compareResults(a: DupeResult, b: DupeResult): number {
  if (b.similarityScore !== a.similarityScore) {
    return b.similarityScore - a.similarityScore;
  }
  const aPrice = a.dupePrice;
  const bPrice = b.dupePrice;
  if (aPrice && bPrice && aPrice.currency === bPrice.currency && aPrice.amount_minor !== bPrice.amount_minor) {
    return aPrice.amount_minor - bPrice.amount_minor;
  }
  if (aPrice && !bPrice) {
    return -1;
  }
  if (!aPrice && bPrice) {
    return 1;
  }
  return b.visualMatchConfidence - a.visualMatchConfidence;
}

/** Top matches after the sheet filters. Order is score, then price, then confidence. */
export function rankDupes(match: VisionMatch, filters: DupeSearchFilters): DupeResult[] {
  const color = match.exact?.attributes.color ?? match.attributes.color;
  return match.dupes
    .filter((dupe) => {
      if (filters.colorMatch && dupe.attributes.color.toLowerCase() !== color.toLowerCase()) {
        return false;
      }
      if (filters.fabric !== 'all' && dupe.attributes.fabric.toLowerCase() !== filters.fabric.toLowerCase()) {
        return false;
      }
      if (filters.budgetMinor !== null) {
        const price = dupe.dupePrice;
        if (
          price === null ||
          filters.currency === null ||
          price.currency !== filters.currency ||
          price.amount_minor > filters.budgetMinor
        ) {
          return false;
        }
      }
      return true;
    })
    .sort(compareResults)
    .slice(0, 3);
}

export function budgetCurrency(match: VisionMatch): string | null {
  const prices = [match.exact?.originalPrice, match.exact?.dupePrice, ...match.dupes.map((dupe) => dupe.dupePrice)];
  return prices.find((price) => price !== null && price !== undefined)?.currency ?? null;
}

export function fabricsIn(match: VisionMatch): string[] {
  const seen = new Set<string>();
  for (const dupe of match.dupes) {
    seen.add(dupe.attributes.fabric);
  }
  if (match.exact) {
    seen.add(match.exact.attributes.fabric);
  }
  return [...seen];
}

export function useDupeSearch(imageUrl: string | null) {
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: queryKeys.vision.match(imageUrl ?? 'idle'),
    enabled: Boolean(imageUrl),
    retry: 2,
    queryFn: ({ signal }) => {
      if (!visionEndpointConfigured) {
        return Promise.resolve(localPreviewMatch());
      }
      return analyzeFrame({ imageUrl: imageUrl ?? '' }, signal);
    },
  });

  useEffect(() => {
    const queryKey = queryKeys.vision.match(imageUrl ?? 'idle');
    return () => {
      if (!imageUrl) {
        return;
      }
      void queryClient.cancelQueries({ queryKey, exact: true });
      const state = queryClient.getQueryState(queryKey);
      if (state?.status !== 'success') {
        queryClient.removeQueries({ queryKey, exact: true });
      }
    };
  }, [imageUrl, queryClient]);

  return query;
}
