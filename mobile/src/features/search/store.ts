import { create } from 'zustand';

import { imageKeyOf, type ScanFailure } from '@/features/search/cascade';
import { extractProductFrame } from '@/features/search/extract-frame';
import { featuresForKind } from '@/features/search/match-offers';
import type {
  DetectionKind,
  ImageSize,
  MerchantOffer,
  NormalizedBox,
  OfferSet,
  OfferSource,
  ScanFeatures,
  ScanStage,
  SocialSource,
} from '@/features/search/types';

export type SearchPhase = 'choose' | 'extracting' | 'scanning' | 'matched' | 'failed';
export type SearchInput = 'camera' | 'upload' | 'tiktok' | 'instagram';

const STAGE_ORDER: Record<ScanStage, number> = { embed: 0, geometry: 1, match: 2, locked: 3 };

type Session = {
  phase: SearchPhase;
  input: SearchInput | null;
  /** Increments on every scan and reset; late pipeline callbacks carry the id they started with. */
  scanId: number;
  /** Short hash of `mediaUri`, used for cache keys and offer ids. */
  imageKey: string | null;
  mediaUri: string | null;
  pendingUri: string | null;
  imageSize: ImageSize | null;
  detectedKind: DetectionKind;
  features: ScanFeatures;
  stage: ScanStage;
  stageAt: number;
  scanStartedAt: number;
  box: NormalizedBox | null;
  confidence: number | null;
  offers: readonly MerchantOffer[];
  offerSource: OfferSource | null;
  failure: ScanFailure | null;
  linkDraft: string;
  linkInvalid: boolean;
  /** The paste-link field is showing. */
  pasteOpen: boolean;
};

type Actions = {
  openSearch: () => void;
  openPaste: () => void;
  /** Hides the scanner. The session (image, stage, offers) stays for the next open. */
  dismiss: () => void;
  /** Returns to the chooser and drops the results sheet. Does not start a new scan. */
  closeSession: () => void;
  /** Clears the session. Only explicit "clear" actions call this. */
  reset: () => void;
  chooseInput: (input: SearchInput) => void;
  beginScan: (uri: string, kind?: DetectionKind, size?: ImageSize | null) => void;
  prepareExtract: (expected: SocialSource) => boolean;
  setImageSize: (uri: string, size: ImageSize) => void;
  advanceStage: (scanId: number, stage: ScanStage) => void;
  completeScan: (scanId: number, result: OfferSet) => void;
  failScan: (scanId: number, failure: ScanFailure) => void;
  retryScan: () => void;
  setLinkDraft: (value: string) => void;
  captureLink: (expected: SocialSource) => boolean;
};

export type VisualSearchState = Session & Actions & { open: boolean };

function emptySession(scanId: number): Session {
  return {
    phase: 'choose',
    input: null,
    scanId,
    imageKey: null,
    mediaUri: null,
    pendingUri: null,
    imageSize: null,
    detectedKind: 'shoes',
    features: featuresForKind('shoes'),
    stage: 'embed',
    stageAt: 0,
    scanStartedAt: 0,
    box: null,
    confidence: null,
    offers: [],
    offerSource: null,
    failure: null,
    linkDraft: '',
    linkInvalid: false,
    pasteOpen: false,
  };
}

function validSize(size: ImageSize | null | undefined): ImageSize | null {
  if (!size || !Number.isFinite(size.width) || !Number.isFinite(size.height) || size.width <= 0 || size.height <= 0) {
    return null;
  }
  return { width: size.width, height: size.height };
}

/** UI state for the visual search overlay. Lives at module scope, so tab switches never reset it. */
export const useVisualSearchStore = create<VisualSearchState>((set, get) => ({
  open: false,
  ...emptySession(0),
  openSearch: () => {
    set({ open: true });
  },
  openPaste: () => {
    set({ open: true, pasteOpen: true, input: 'instagram' });
  },
  dismiss: () => {
    set({ open: false });
  },
  closeSession: () => {
    set({
      open: true,
      phase: 'choose',
      stage: 'embed',
      offers: [],
      offerSource: null,
      box: null,
      confidence: null,
      failure: null,
      pasteOpen: false,
      linkInvalid: false,
    });
  },
  reset: () => {
    set({ open: false, ...emptySession(get().scanId + 1) });
  },
  chooseInput: (input) => {
    set({ input, pasteOpen: input === 'tiktok' || input === 'instagram', linkInvalid: false });
  },
  beginScan: (uri, kind = 'shoes', size = null) => {
    const now = Date.now();
    const sameImage = get().mediaUri === uri;
    set({
      open: true,
      phase: 'scanning',
      scanId: get().scanId + 1,
      imageKey: sameImage && get().imageKey ? get().imageKey : imageKeyOf(uri),
      mediaUri: uri,
      pendingUri: null,
      imageSize: validSize(size) ?? (sameImage ? get().imageSize : null),
      detectedKind: kind,
      features: featuresForKind(kind),
      stage: 'embed',
      stageAt: now,
      scanStartedAt: now,
      box: null,
      confidence: null,
      offers: [],
      offerSource: null,
      failure: null,
      linkInvalid: false,
      pasteOpen: false,
    });
  },
  prepareExtract: (expected) => {
    const frame = extractProductFrame(get().linkDraft);
    if (!frame || frame.source !== expected) {
      set({ linkInvalid: true });
      return false;
    }
    set({
      open: true,
      phase: 'extracting',
      scanId: get().scanId + 1,
      pendingUri: frame.imageUrl,
      detectedKind: frame.kind,
      features: featuresForKind(frame.kind),
      failure: null,
      linkInvalid: false,
      pasteOpen: false,
    });
    return true;
  },
  setImageSize: (uri, size) => {
    const next = validSize(size);
    const current = get().imageSize;
    if (!next || get().mediaUri !== uri || (current && current.width === next.width && current.height === next.height)) {
      return;
    }
    set({ imageSize: next });
  },
  advanceStage: (scanId, stage) => {
    const state = get();
    if (state.scanId !== scanId || state.phase !== 'scanning' || STAGE_ORDER[stage] <= STAGE_ORDER[state.stage]) {
      return;
    }
    set({ stage, stageAt: Date.now() });
  },
  completeScan: (scanId, result) => {
    const state = get();
    if (state.scanId !== scanId || state.phase !== 'scanning') {
      return;
    }
    set({
      phase: 'matched',
      stage: 'locked',
      stageAt: Date.now(),
      detectedKind: result.kind,
      features: result.kind === state.detectedKind ? state.features : featuresForKind(result.kind),
      box: result.box,
      confidence: result.confidence,
      offers: result.offers,
      offerSource: result.source,
      failure: null,
    });
  },
  failScan: (scanId, failure) => {
    const state = get();
    if (state.scanId !== scanId || state.phase !== 'scanning') {
      return;
    }
    set({ phase: 'failed', failure });
  },
  retryScan: () => {
    const state = get();
    if (state.mediaUri) {
      state.beginScan(state.mediaUri, state.detectedKind, state.imageSize);
    }
  },
  setLinkDraft: (linkDraft) => {
    set({ linkDraft, linkInvalid: false });
  },
  captureLink: (expected) => get().prepareExtract(expected),
}));
