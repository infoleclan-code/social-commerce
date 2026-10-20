import { CameraView, useCameraPermissions } from 'expo-camera';
import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { useIsFocused } from 'expo-router';
import { Camera, Flashlight, FlashlightOff, Images, RotateCcw, X } from 'lucide-react-native';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Linking, Platform, Pressable, ScrollView, Text, View } from 'react-native';
import Animated, { FadeIn, FadeInDown, FadeOut, useAnimatedStyle, useSharedValue, withSpring, withTiming, ZoomIn } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, NetworkError } from '@/api/client';
import type { BoundingBox, VisualSearchResult } from '@/api/contracts';
import { searchByImage } from '@/api/social-commerce';
import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { prepareScanImage, type PreparedScan } from '@/features/scanner/prepare-scan-image';
import { ScanResultsSheet } from '@/features/scanner/scan-results-sheet';
import { ScanReticle, type ReticleMode } from '@/features/scanner/scan-reticle';
import { playError, playLock, playRelease, playSelection, playSuccess } from '@/lib/feedback';
import { t, type MessageKey } from '@/lib/i18n';
import { useAppActive } from '@/lib/use-app-active';
import { palette, radius } from '@/theme/tokens';

type Props = {
  onClose: () => void;
};

type Frame = { uri: string; width: number; height: number };
type Stage = 'upload' | 'analyze' | 'match';
type ScanProblem = 'image' | 'mismatch' | 'unavailable' | 'rateLimited' | 'network' | 'signedOut' | 'camera';

type ScanState =
  | { phase: 'live' }
  | { phase: 'analyzing'; frame: Frame; stage: Stage; startedAt: number }
  | { phase: 'results'; frame: Frame; analyzed: { width: number; height: number }; result: VisualSearchResult }
  | { phase: 'failed'; frame: Frame | null; problem: ScanProblem; canRetry: boolean };

type Category = { key: MessageKey; value: string | null };

/** Taxonomy roots and branches of the catalog (products.category is a dotted path). */
const CATEGORIES: readonly Category[] = [
  { key: 'scanner.category.all', value: null },
  { key: 'scanner.category.fashion', value: 'apparel' },
  { key: 'scanner.category.sneakers', value: 'apparel.footwear' },
  { key: 'scanner.category.tech', value: 'electronics' },
  { key: 'scanner.category.home', value: 'home' },
  { key: 'scanner.category.beauty', value: 'beauty' },
];

const STAGE_COPY: Record<Stage, MessageKey> = {
  upload: 'scanner.stage.upload',
  analyze: 'scanner.stage.analyze',
  match: 'scanner.stage.match',
};

const PROBLEM_COPY: Record<ScanProblem, MessageKey> = {
  image: 'scanner.error.image',
  mismatch: 'scanner.error.mismatch',
  unavailable: 'scanner.error.unavailable',
  rateLimited: 'scanner.error.rateLimited',
  network: 'scanner.error.network',
  signedOut: 'scanner.error.signedOut',
  camera: 'scanner.error.camera',
};

/** The sweep stays on screen at least this long, so a fast answer still reads as a scan. */
const MIN_ANALYZE_MS = 900;
const CONTROLS_SPACE = 216;
const TOP_BAR_SPACE = 64;

const FILL = { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 } as const;

function scanProblemOf(error: unknown): ScanProblem {
  if (error instanceof NetworkError) {
    return 'network';
  }
  if (error instanceof ApiError) {
    if (error.status === 401) {
      return 'signedOut';
    }
    if (error.status === 429) {
      return 'rateLimited';
    }
    if (error.code === 'IMAGE_HASH_MISMATCH') {
      // The bytes were damaged on the way: the same prepared frame can simply be sent again.
      return 'mismatch';
    }
    if (error.code === 'IMAGE_REJECTED' || error.code === 'PAYLOAD_TOO_LARGE' || error.fieldErrors.image || error.fieldErrors.image_base64) {
      return 'image';
    }
  }
  return 'unavailable';
}

/** Where a normalized box of the analyzed image lands on screen when the frame is drawn with contentFit "cover". */
function coverRect(box: BoundingBox, image: { width: number; height: number }, view: { width: number; height: number }) {
  const scale = Math.max(view.width / image.width, view.height / image.height);
  const drawnWidth = image.width * scale;
  const drawnHeight = image.height * scale;
  const offsetX = (view.width - drawnWidth) / 2;
  const offsetY = (view.height - drawnHeight) / 2;
  const left = Math.max(8, offsetX + box.x * drawnWidth);
  const top = Math.max(8, offsetY + box.y * drawnHeight);
  const right = Math.min(view.width - 8, offsetX + (box.x + box.width) * drawnWidth);
  const bottom = Math.min(view.height - 8, offsetY + (box.y + box.height) * drawnHeight);
  return { left, top, width: Math.max(24, right - left), height: Math.max(24, bottom - top) };
}

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/**
 * Point the camera at anything (or pick a photo / screenshot) and get ranked products with 1-Tap Buy.
 *
 * Flow: live preview -> shutter freezes the frame -> resize + re-encode on device (EXIF/GPS dropped) ->
 * POST /visual-search (multipart; base64 on web) -> results sheet with the detected box drawn on the frame.
 * The camera session pauses whenever the preview is not needed (frozen frame, blurred screen, background).
 */
export function VisualScannerScreen({ onClose }: Props) {
  const insets = useSafeAreaInsets();
  const focused = useIsFocused();
  const appActive = useAppActive();
  const [permission, requestPermission] = useCameraPermissions();
  const cameraRef = useRef<CameraView>(null);
  const requestRef = useRef<AbortController | null>(null);
  const preparedRef = useRef<{ frame: Frame; prepared: PreparedScan } | null>(null);
  const scanIdRef = useRef(0);
  const capturingRef = useRef(false);
  const [state, setState] = useState<ScanState>({ phase: 'live' });
  const [cameraReady, setCameraReady] = useState(false);
  const [torch, setTorch] = useState(false);
  const [category, setCategory] = useState<string | null>(null);
  const [stageSize, setStageSize] = useState({ width: 0, height: 0 });
  const frameShift = useSharedValue(0);

  const granted = permission?.granted === true;
  const cameraLive = focused && appActive && granted && state.phase === 'live';

  // Abort a scan in flight when the screen goes away.
  useEffect(() => {
    return () => {
      requestRef.current?.abort();
    };
  }, []);

  // Android has no `active` prop: pause and resume the preview instead (iOS uses `active`).
  useEffect(() => {
    if (Platform.OS !== 'android' || !cameraReady) {
      return;
    }
    const camera = cameraRef.current;
    if (!camera) {
      return;
    }
    (cameraLive ? camera.resumePreview() : camera.pausePreview()).catch(() => undefined);
  }, [cameraLive, cameraReady]);

  // Narrate the request while it runs. Copy only; the real work is one request.
  const analyzingSince = state.phase === 'analyzing' ? state.startedAt : null;
  useEffect(() => {
    if (analyzingSince === null) {
      return;
    }
    const advance = (stage: Stage) => {
      setState((current) => (current.phase === 'analyzing' && current.startedAt === analyzingSince ? { ...current, stage } : current));
    };
    const toAnalyze = setTimeout(() => advance('analyze'), 450);
    const toMatch = setTimeout(() => advance('match'), 1_050);
    return () => {
      clearTimeout(toAnalyze);
      clearTimeout(toMatch);
    };
  }, [analyzingSince]);

  // Lift the frozen frame so the detected item sits in the visible area above the results sheet.
  const resultBox =
    state.phase === 'results' && state.result.bounding_box && stageSize.width > 0
      ? coverRect(state.result.bounding_box, state.analyzed, stageSize)
      : null;
  const visibleCenter = insets.top + TOP_BAR_SPACE + (stageSize.height * 0.4 - insets.top - TOP_BAR_SPACE) / 2;
  const targetShift = resultBox
    ? Math.min(0, Math.max(-stageSize.height * 0.5, visibleCenter - (resultBox.top + resultBox.height / 2)))
    : 0;
  useEffect(() => {
    frameShift.set(targetShift === 0 ? withTiming(0, { duration: 220 }) : withSpring(targetShift, { damping: 20, stiffness: 160 }));
  }, [frameShift, targetShift]);

  const frameStyle = useAnimatedStyle(() => ({ transform: [{ translateY: frameShift.get() }] }));

  async function analyze(frame: Frame, filter: string | null, reuse: PreparedScan | null) {
    const scanId = ++scanIdRef.current;
    requestRef.current?.abort();
    const controller = new AbortController();
    requestRef.current = controller;
    const startedAt = Date.now();
    setState({ phase: 'analyzing', frame, stage: 'upload', startedAt });

    let prepared: PreparedScan | null = reuse;
    try {
      prepared ??= await prepareScanImage(frame);
      preparedRef.current = { frame, prepared };
      const result = await searchByImage(prepared.upload, filter, controller.signal);
      const remaining = MIN_ANALYZE_MS - (Date.now() - startedAt);
      if (remaining > 0) {
        await sleep(remaining);
      }
      if (scanId !== scanIdRef.current) {
        return;
      }
      setState({ phase: 'results', frame, analyzed: { width: prepared.width, height: prepared.height }, result });
      if (result.results.length > 0) {
        playSuccess();
      } else {
        playRelease();
      }
    } catch (error) {
      if (controller.signal.aborted || scanId !== scanIdRef.current) {
        return;
      }
      // A retry can resend the prepared frame only if preparing it worked.
      setState({ phase: 'failed', frame, problem: scanProblemOf(error), canRetry: prepared !== null });
      playError();
    }
  }

  async function capture() {
    const camera = cameraRef.current;
    if (!camera || !cameraReady || state.phase !== 'live' || capturingRef.current) {
      return;
    }
    capturingRef.current = true;
    playLock();
    try {
      const photo = await camera.takePictureAsync({ quality: 0.9, exif: false });
      void analyze({ uri: photo.uri, width: photo.width, height: photo.height }, category, null);
    } catch {
      setState({ phase: 'failed', frame: null, problem: 'camera', canRetry: false });
      playError();
    } finally {
      capturingRef.current = false;
    }
  }

  async function pickFromLibrary() {
    playSelection();
    try {
      const picked = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 1, exif: false, allowsMultipleSelection: false });
      const asset = picked.canceled ? null : picked.assets[0];
      if (asset) {
        void analyze({ uri: asset.uri, width: asset.width, height: asset.height }, category, null);
      }
    } catch {
      setState({ phase: 'failed', frame: null, problem: 'image', canRetry: false });
      playError();
    }
  }

  function chooseCategory(value: string | null) {
    playSelection();
    setCategory(value);
    // With a scan on screen, the new filter re-runs the search on the same prepared frame (no re-encode).
    const last = preparedRef.current;
    if (last && (state.phase === 'results' || state.phase === 'failed')) {
      void analyze(last.frame, value, last.prepared);
    }
  }

  function scanAgain() {
    playSelection();
    scanIdRef.current += 1;
    requestRef.current?.abort();
    preparedRef.current = null;
    setState({ phase: 'live' });
  }

  function retry() {
    const last = preparedRef.current;
    if (last) {
      void analyze(last.frame, category, last.prepared);
    } else {
      scanAgain();
    }
  }

  const reticleWidth = Math.min(Math.max(0, stageSize.width - 64), 360);
  const reticleHeight = Math.max(0, Math.min(reticleWidth * 1.2, stageSize.height - insets.top - TOP_BAR_SPACE - CONTROLS_SPACE - insets.bottom));
  const reticleTop = insets.top + TOP_BAR_SPACE + Math.max(0, (stageSize.height - insets.top - TOP_BAR_SPACE - CONTROLS_SPACE - insets.bottom - reticleHeight) / 2);
  const reticleMode: ReticleMode = state.phase === 'analyzing' ? 'analyzing' : 'live';
  const frame = state.phase === 'live' ? null : state.frame;
  const showControls = state.phase === 'live' && granted;

  return (
    <View
      onLayout={(event) => {
        const { width, height } = event.nativeEvent.layout;
        setStageSize((current) => (current.width === width && current.height === height ? current : { width, height }));
      }}
      style={{ flex: 1, backgroundColor: palette.ink }}
    >
      {granted && focused ? (
        <CameraView
          active={cameraLive}
          animateShutter={false}
          enableTorch={torch && cameraLive}
          facing="back"
          mode="picture"
          onCameraReady={() => setCameraReady(true)}
          onMountError={() => setState({ phase: 'failed', frame: null, problem: 'camera', canRetry: false })}
          ref={cameraRef}
          style={FILL}
        />
      ) : null}

      {frame ? (
        <Animated.View entering={FadeIn.duration(120)} exiting={FadeOut.duration(160)} style={[FILL, frameStyle]}>
          <Image accessibilityIgnoresInvertColors contentFit="cover" source={{ uri: frame.uri }} style={FILL} />
          <View style={[FILL, { backgroundColor: 'rgba(0, 0, 0, 0.25)' }]} />
          {resultBox ? <DetectionBox box={resultBox} label={state.phase === 'results' ? state.result.detected_category : null} /> : null}
        </Animated.View>
      ) : null}

      {(state.phase === 'live' && granted) || state.phase === 'analyzing' ? (
        <View style={{ position: 'absolute', top: reticleTop, left: (stageSize.width - reticleWidth) / 2 }}>
          {reticleWidth > 0 && reticleHeight > 0 ? <ScanReticle height={reticleHeight} mode={reticleMode} width={reticleWidth} /> : null}
          {state.phase === 'analyzing' ? (
            <Animated.View entering={FadeInDown.duration(200)} style={{ marginTop: 14, alignItems: 'center' }}>
              <GlassSurface radius={radius.pill} style={{ borderColor: palette.goldRing }}>
                <View accessibilityLiveRegion="polite" style={{ flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 16, paddingVertical: 10 }}>
                  <ActivityIndicator color={palette.goldSoft} size="small" />
                  <Text style={{ color: palette.ivory, fontSize: 14, fontWeight: '600' }}>{t(STAGE_COPY[state.stage])}</Text>
                </View>
              </GlassSurface>
            </Animated.View>
          ) : null}
        </View>
      ) : null}

      <TopBar
        insetTop={insets.top}
        onClose={onClose}
        onToggleTorch={() => {
          playSelection();
          setTorch((value) => !value);
        }}
        showTorch={state.phase === 'live' && granted && Platform.OS !== 'web'}
        torch={torch}
      />

      {permission && !granted ? (
        <PermissionCard
          canAsk={permission.canAskAgain}
          onAllow={() => {
            void requestPermission();
          }}
          onPhotos={() => void pickFromLibrary()}
        />
      ) : null}

      {showControls || state.phase === 'results' ? (
        <CategoryChips
          bottom={state.phase === 'results' ? undefined : insets.bottom + 132}
          onSelect={chooseCategory}
          selected={category}
          top={state.phase === 'results' ? insets.top + TOP_BAR_SPACE : undefined}
        />
      ) : null}

      {showControls ? (
        <View style={{ position: 'absolute', left: 0, right: 0, bottom: insets.bottom + 28, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-evenly' }}>
          <RoundButton icon={Images} label={t('scanner.gallery')} onPress={() => void pickFromLibrary()} />
          <Shutter disabled={!cameraReady} onPress={() => void capture()} />
          <View style={{ width: 64 }} />
        </View>
      ) : null}

      {state.phase === 'results' ? <ScanResultsSheet onScanAgain={scanAgain} result={state.result} /> : null}

      {state.phase === 'failed' ? (
        <FailureCard
          bottom={insets.bottom + 20}
          onRetry={retry}
          onScanAgain={scanAgain}
          problem={state.problem}
          retryable={state.canRetry && (state.problem === 'network' || state.problem === 'mismatch' || state.problem === 'unavailable' || state.problem === 'rateLimited')}
        />
      ) : null}
    </View>
  );
}

function TopBar({
  insetTop,
  torch,
  showTorch,
  onClose,
  onToggleTorch,
}: {
  insetTop: number;
  torch: boolean;
  showTorch: boolean;
  onClose: () => void;
  onToggleTorch: () => void;
}) {
  return (
    <View style={{ position: 'absolute', top: insetTop + 8, left: 12, right: 12, flexDirection: 'row', alignItems: 'center', gap: 12, zIndex: 30 }}>
      <IconButton icon={X} label={t('scanner.closeA11y')} onPress={onClose} />
      <View style={{ flex: 1, alignItems: 'center' }}>
        <Text accessibilityRole="header" style={{ color: palette.ivory, fontSize: 16, fontWeight: '700', textShadowColor: 'rgba(0,0,0,0.6)', textShadowRadius: 6 }}>
          {t('scanner.title')}
        </Text>
        <Text style={{ color: palette.muted, fontSize: 12, textShadowColor: 'rgba(0,0,0,0.6)', textShadowRadius: 6 }}>{t('scanner.subtitle')}</Text>
      </View>
      {showTorch ? (
        <IconButton
          active={torch}
          icon={torch ? FlashlightOff : Flashlight}
          label={torch ? t('scanner.torchOffA11y') : t('scanner.torchOnA11y')}
          onPress={onToggleTorch}
        />
      ) : (
        <View style={{ width: 44 }} />
      )}
    </View>
  );
}

function IconButton({ icon: Icon, label, onPress, active = false }: { icon: typeof X; label: string; onPress: () => void; active?: boolean }) {
  return (
    <Pressable accessibilityLabel={label} accessibilityRole="button" accessibilityState={{ selected: active }} hitSlop={6} onPress={onPress}>
      <View
        style={{
          width: 44,
          height: 44,
          borderRadius: 22,
          alignItems: 'center',
          justifyContent: 'center',
          backgroundColor: active ? palette.goldWash : 'rgba(0, 0, 0, 0.42)',
          borderWidth: 1,
          borderColor: active ? palette.goldRing : palette.line,
        }}
      >
        <Icon color={active ? palette.goldSoft : palette.ivory} size={19} strokeWidth={1.8} />
      </View>
    </Pressable>
  );
}

function RoundButton({ icon: Icon, label, onPress }: { icon: typeof X; label: string; onPress: () => void }) {
  return (
    <PressableScale accessibilityLabel={t('scanner.galleryA11y')} onPress={onPress}>
      <View style={{ width: 64, alignItems: 'center', gap: 6 }}>
        <View
          style={{
            width: 52,
            height: 52,
            borderRadius: 16,
            alignItems: 'center',
            justifyContent: 'center',
            backgroundColor: 'rgba(0, 0, 0, 0.45)',
            borderWidth: 1,
            borderColor: palette.lineStrong,
          }}
        >
          <Icon color={palette.ivory} size={22} strokeWidth={1.7} />
        </View>
        <Text style={{ color: palette.ivory, fontSize: 11, fontWeight: '600' }}>{label}</Text>
      </View>
    </PressableScale>
  );
}

function Shutter({ disabled, onPress }: { disabled: boolean; onPress: () => void }) {
  const press = useSharedValue(1);
  const innerStyle = useAnimatedStyle(() => ({ transform: [{ scale: press.get() }] }));

  return (
    <Pressable
      accessibilityLabel={t('scanner.shutterA11y')}
      accessibilityRole="button"
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      onPressIn={() => press.set(withSpring(0.88, { damping: 14, stiffness: 400 }))}
      onPressOut={() => press.set(withSpring(1, { damping: 12, stiffness: 300 }))}
    >
      <View
        style={{
          width: 84,
          height: 84,
          borderRadius: 42,
          borderWidth: 4,
          borderColor: palette.ivory,
          alignItems: 'center',
          justifyContent: 'center',
          opacity: disabled ? 0.5 : 1,
          boxShadow: '0 0 24px rgba(212, 175, 55, 0.45)',
        }}
      >
        <Animated.View style={[{ width: 66, height: 66, borderRadius: 33, backgroundColor: palette.gold, alignItems: 'center', justifyContent: 'center' }, innerStyle]}>
          <Camera color={palette.ink} size={26} strokeWidth={1.8} />
        </Animated.View>
      </View>
    </Pressable>
  );
}

function CategoryChips({ selected, onSelect, top, bottom }: { selected: string | null; onSelect: (value: string | null) => void; top?: number; bottom?: number }) {
  return (
    <ScrollView
      contentContainerStyle={{ paddingHorizontal: 16, gap: 8 }}
      horizontal
      showsHorizontalScrollIndicator={false}
      style={{ position: 'absolute', left: 0, right: 0, top, bottom, flexGrow: 0, zIndex: 25 }}
    >
      {CATEGORIES.map((category) => {
        const active = category.value === selected;
        return (
          <Pressable
            accessibilityRole="button"
            accessibilityState={{ selected: active }}
            key={category.key}
            onPress={() => onSelect(category.value)}
            style={{
              minHeight: 36,
              paddingHorizontal: 14,
              borderRadius: radius.pill,
              justifyContent: 'center',
              borderWidth: 1,
              borderColor: active ? palette.gold : palette.line,
              backgroundColor: active ? palette.goldWash : 'rgba(0, 0, 0, 0.45)',
            }}
          >
            <Text style={{ color: active ? palette.goldSoft : palette.ivory, fontSize: 13, fontWeight: '600' }}>{t(category.key)}</Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

function DetectionBox({ box, label }: { box: { left: number; top: number; width: number; height: number }; label: string | null }) {
  const corner = 22;
  const edge = (vertical: 'top' | 'bottom', horizontal: 'left' | 'right') => ({
    position: 'absolute' as const,
    width: corner,
    height: corner,
    top: vertical === 'top' ? -2 : undefined,
    bottom: vertical === 'bottom' ? -2 : undefined,
    left: horizontal === 'left' ? -2 : undefined,
    right: horizontal === 'right' ? -2 : undefined,
    borderColor: palette.goldSoft,
    borderTopWidth: vertical === 'top' ? 3 : 0,
    borderBottomWidth: vertical === 'bottom' ? 3 : 0,
    borderLeftWidth: horizontal === 'left' ? 3 : 0,
    borderRightWidth: horizontal === 'right' ? 3 : 0,
  });

  return (
    <Animated.View
      entering={ZoomIn.springify().damping(16)}
      style={{ position: 'absolute', left: box.left, top: box.top, width: box.width, height: box.height, pointerEvents: 'none' }}
    >
      <View style={{ flex: 1, borderRadius: 10, borderWidth: 1, borderColor: 'rgba(229, 193, 88, 0.45)', backgroundColor: 'rgba(212, 175, 55, 0.06)' }} />
      <View style={edge('top', 'left')} />
      <View style={edge('top', 'right')} />
      <View style={edge('bottom', 'left')} />
      <View style={edge('bottom', 'right')} />
      {label ? (
        <View style={{ position: 'absolute', left: 0, top: -30, paddingHorizontal: 10, paddingVertical: 4, borderRadius: radius.pill, backgroundColor: palette.gold }}>
          <Text style={{ color: palette.ink, fontSize: 12, fontWeight: '700', textTransform: 'capitalize' }}>{label.split('.').pop()}</Text>
        </View>
      ) : null}
    </Animated.View>
  );
}

function PermissionCard({ canAsk, onAllow, onPhotos }: { canAsk: boolean; onAllow: () => void; onPhotos: () => void }) {
  return (
    <View style={[FILL, { alignItems: 'center', justifyContent: 'center', paddingHorizontal: 24 }]}>
      <GlassSurface radius={radius.panel} style={{ width: '100%', maxWidth: 420 }}>
        <View style={{ padding: 22, gap: 12, alignItems: 'center' }}>
          <View
            style={{
              width: 64,
              height: 64,
              borderRadius: 32,
              alignItems: 'center',
              justifyContent: 'center',
              backgroundColor: palette.goldWashSoft,
              borderWidth: 1,
              borderColor: palette.goldRing,
            }}
          >
            <Camera color={palette.goldSoft} size={28} strokeWidth={1.4} />
          </View>
          <Text style={{ color: palette.ivory, fontSize: 20, fontWeight: '700' }}>{t('scanner.permissionTitle')}</Text>
          <Text style={{ color: palette.muted, fontSize: 14, lineHeight: 20, textAlign: 'center' }}>
            {canAsk ? t('scanner.permissionBody') : t('scanner.permissionDenied')}
          </Text>
          <PressableScale
            accessibilityLabel={canAsk ? t('scanner.permissionAllow') : t('scanner.permissionSettings')}
            block
            onPress={() => {
              if (canAsk) {
                onAllow();
              } else {
                void Linking.openSettings().catch(() => undefined);
              }
            }}
          >
            <View style={{ minHeight: 48, borderRadius: radius.pill, backgroundColor: palette.gold, alignItems: 'center', justifyContent: 'center' }}>
              <Text style={{ color: palette.ink, fontSize: 15, fontWeight: '700' }}>{canAsk ? t('scanner.permissionAllow') : t('scanner.permissionSettings')}</Text>
            </View>
          </PressableScale>
          <PressableScale accessibilityLabel={t('scanner.galleryA11y')} block onPress={onPhotos}>
            <View
              style={{
                minHeight: 48,
                borderRadius: radius.pill,
                borderWidth: 1,
                borderColor: palette.lineStrong,
                flexDirection: 'row',
                gap: 8,
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Images color={palette.ivory} size={18} strokeWidth={1.8} />
              <Text style={{ color: palette.ivory, fontSize: 15, fontWeight: '600' }}>{t('scanner.gallery')}</Text>
            </View>
          </PressableScale>
        </View>
      </GlassSurface>
    </View>
  );
}

function FailureCard({
  problem,
  bottom,
  retryable,
  onRetry,
  onScanAgain,
}: {
  problem: ScanProblem;
  bottom: number;
  retryable: boolean;
  onRetry: () => void;
  onScanAgain: () => void;
}) {
  return (
    <Animated.View entering={FadeInDown.duration(240)} style={{ position: 'absolute', left: 16, right: 16, bottom, zIndex: 40 }}>
      <GlassSurface radius={radius.panel} style={{ backgroundColor: 'rgba(12, 12, 12, 0.92)' }}>
        <View style={{ padding: 18, gap: 12 }}>
          <View style={{ gap: 4 }}>
            <Text style={{ color: palette.ivory, fontSize: 17, fontWeight: '700' }}>{t('scanner.errorTitle')}</Text>
            <Text style={{ color: palette.muted, fontSize: 14, lineHeight: 20 }}>{t(PROBLEM_COPY[problem])}</Text>
          </View>
          <View style={{ flexDirection: 'row', gap: 10 }}>
            {retryable ? (
              <PressableScale accessibilityLabel={t('reels.retry')} fill onPress={onRetry}>
                <View style={{ minHeight: 46, borderRadius: radius.pill, backgroundColor: palette.ivory, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8 }}>
                  <RotateCcw color={palette.ink} size={16} strokeWidth={2.2} />
                  <Text style={{ color: palette.ink, fontSize: 15, fontWeight: '700' }}>{t('reels.retry')}</Text>
                </View>
              </PressableScale>
            ) : null}
            <PressableScale accessibilityLabel={t('scanner.scanAgain')} fill onPress={onScanAgain}>
              <View style={{ minHeight: 46, borderRadius: radius.pill, borderWidth: 1, borderColor: palette.lineStrong, alignItems: 'center', justifyContent: 'center' }}>
                <Text style={{ color: palette.ivory, fontSize: 15, fontWeight: '700' }}>{t('scanner.scanAgain')}</Text>
              </View>
            </PressableScale>
          </View>
        </View>
      </GlassSurface>
    </Animated.View>
  );
}
