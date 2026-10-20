import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { useEffect } from 'react';

import { queryKeys } from '@/api/keys';
import { failureOf, fetchOfferSet, shouldRetry } from '@/features/search/cascade';
import { useVisualSearchStore } from '@/features/search/store';

/**
 * HUD pacing in ms from scan start. Stages advance on the real request, but each stays on screen
 * long enough to read and the lock never lands before the beam has completed a sweep.
 */
export const SCAN_PACING = {
  extractMs: 1_100,
  geometryAt: 760,
  matchAt: 1_520,
  lockAt: 2_280,
} as const;

/**
 * Drives a scan from frame to offers. Mounted once at the root (not inside the overlay), so a scan
 * keeps running when the overlay is closed and the result is waiting when it reopens. Offers are
 * cached per image for 30 minutes: rescanning the same photo or reopening never refetches.
 */
export function useScanPipeline(): { retry: () => void } {
  const queryClient = useQueryClient();
  const phase = useVisualSearchStore((state) => state.phase);
  const scanId = useVisualSearchStore((state) => state.scanId);
  const imageKey = useVisualSearchStore((state) => state.imageKey);
  const mediaUri = useVisualSearchStore((state) => state.mediaUri);
  const pendingUri = useVisualSearchStore((state) => state.pendingUri);
  const detectedKind = useVisualSearchStore((state) => state.detectedKind);
  const features = useVisualSearchStore((state) => state.features);
  const scanStartedAt = useVisualSearchStore((state) => state.scanStartedAt);

  const offers = useQuery({
    queryKey: queryKeys.vision.offers(imageKey ?? 'idle', detectedKind),
    enabled: phase === 'scanning' && mediaUri !== null && imageKey !== null,
    queryFn: ({ signal }) => fetchOfferSet({ imageUrl: mediaUri ?? '', imageKey: imageKey ?? '', features }, signal),
    staleTime: Infinity,
    gcTime: 30 * 60_000,
    retry: shouldRetry,
  });
  const { data, error, fetchStatus, status } = offers;

  // Social link: show the extraction veil, warm the image cache, then scan the captured frame.
  useEffect(() => {
    if (phase !== 'extracting' || !pendingUri) {
      return;
    }
    void Image.prefetch(pendingUri, 'memory-disk').catch(() => false);
    const id = setTimeout(() => {
      useVisualSearchStore.getState().beginScan(pendingUri, detectedKind);
    }, SCAN_PACING.extractMs);
    return () => clearTimeout(id);
  }, [detectedKind, pendingUri, phase]);

  // Narrate the pipeline. Measured from scan start, so a remount mid-scan resumes in step.
  useEffect(() => {
    if (phase !== 'scanning') {
      return;
    }
    const { advanceStage } = useVisualSearchStore.getState();
    const elapsed = Date.now() - scanStartedAt;
    const geometry = setTimeout(() => advanceStage(scanId, 'geometry'), Math.max(0, SCAN_PACING.geometryAt - elapsed));
    const match = setTimeout(() => advanceStage(scanId, 'match'), Math.max(0, SCAN_PACING.matchAt - elapsed));
    return () => {
      clearTimeout(geometry);
      clearTimeout(match);
    };
  }, [phase, scanId, scanStartedAt]);

  // Lock when the offers are in and the minimum sweep has played; fail only once fetching has stopped.
  useEffect(() => {
    if (phase !== 'scanning') {
      return;
    }
    const store = useVisualSearchStore.getState();
    if (status === 'success') {
      const wait = Math.max(0, scanStartedAt + SCAN_PACING.lockAt - Date.now());
      const id = setTimeout(() => store.completeScan(scanId, data), wait);
      return () => clearTimeout(id);
    }
    if (status === 'error' && fetchStatus === 'idle') {
      store.failScan(scanId, failureOf(error));
    }
    return undefined;
  }, [data, error, fetchStatus, phase, scanId, scanStartedAt, status]);

  function retry() {
    const state = useVisualSearchStore.getState();
    if (!state.imageKey || !state.mediaUri) {
      return;
    }
    queryClient.removeQueries({ queryKey: queryKeys.vision.offers(state.imageKey, state.detectedKind), exact: true });
    state.retryScan();
  }

  return { retry };
}
