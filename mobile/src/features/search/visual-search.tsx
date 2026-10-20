import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { Camera, Image as ImageIcon, Link, RotateCcw, ScanSearch, X } from 'lucide-react-native';
import { useEffect, useRef, useState } from 'react';
import { Alert, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import Animated, {
  cancelAnimation,
  Easing,
  FadeIn,
  FadeInDown,
  FadeOut,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withRepeat,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AmbientGlow } from '@/components/ui/ambient-glow';
import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { SearchActionPill } from '@/features/search/action-pill';
import type { ScanFailure } from '@/features/search/cascade';
import { extractProductFrame } from '@/features/search/extract-frame';
import { DIAGNOSTICS_SPACE, HolographicScanner, ScanDiagnostics } from '@/features/search/laser-scanner';
import { MatchDrawer } from '@/features/search/match-drawer';
import { useVisualSearchStore, type SearchInput, type SearchPhase } from '@/features/search/store';
import type { DetectionKind, ImageSize, SocialSource } from '@/features/search/types';
import { useScanPipeline } from '@/features/search/use-scan-pipeline';
import { playSelection } from '@/lib/feedback';
import { t, type MessageKey } from '@/lib/i18n';
import { useCheckoutStore } from '@/stores/checkout';
import { palette, radius, elevation } from '@/theme/tokens';

const FRAME_INSET_X = 16;
const FRAME_INSET_Y = 8;
const DRAWER_GAP = 12;
/** Smallest the frame shrinks to make room for the drawer. */
const MIN_FIT = 0.38;
/** Below this frame scale the lock tag's text would be too small to read, so it is hidden. */
const COMPACT_BELOW = 0.6;
const FRAME_SPRING = { stiffness: 190, damping: 24, mass: 0.9 } as const;
const PICKER_OPTIONS: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.8 };

const KIND_LABEL: Record<DetectionKind, MessageKey> = {
  coat: 'search.coat',
  shoes: 'search.sneakers',
  bag: 'search.bag',
};

const FAILURE_COPY: Record<ScanFailure, MessageKey> = {
  timeout: 'dupe.timeout',
  unavailable: 'dupe.unavailable',
  invalid: 'dupe.invalid',
  upload: 'search.uploadUnavailable',
  empty: 'search.noStores',
};

type StageBox = { y: number; width: number; height: number };

/** Last measured drawer height, so reopening a finished scan lays the frame out in its final place at once. */
let rememberedDrawerHeight = 0;
type FrameFit = { scale: number; shift: number };

function containIn(size: ImageSize, maxWidth: number, maxHeight: number): ImageSize | null {
  if (maxWidth <= 0 || maxHeight <= 0) {
    return null;
  }
  const scale = Math.min(maxWidth / size.width, maxHeight / size.height);
  return { width: Math.round(size.width * scale), height: Math.round(size.height * scale) };
}

/**
 * Scale (about its center) and lift the frame so it sits fully above the drawer, never above the
 * stage. `frameTop` is in overlay coordinates.
 */
function fitAboveDrawer(stage: StageBox, frameTop: number, frameHeight: number, rootHeight: number, drawerHeight: number): FrameFit {
  if (drawerHeight <= 0 || rootHeight <= 0 || frameHeight <= 0) {
    return { scale: 1, shift: 0 };
  }
  const regionTop = stage.y + FRAME_INSET_Y;
  const regionBottom = rootHeight - drawerHeight - DRAWER_GAP;
  const scale = Math.min(1, Math.max(MIN_FIT, (regionBottom - regionTop) / frameHeight));
  const scaledHeight = frameHeight * scale;
  const scaledTop = frameTop + (frameHeight - scaledHeight) / 2;
  let shift = 0;
  if (scaledTop + scaledHeight > regionBottom) {
    shift = regionBottom - (scaledTop + scaledHeight);
  }
  if (scaledTop + shift < regionTop) {
    shift = regionTop - scaledTop;
  }
  return { scale, shift };
}

/**
 * Visual search. Rendered at the app root as an in-tree layer above the navigator instead of a
 * native <Modal>: iOS can present only one modal view controller per presenter, so a modal search
 * screen silently swallowed the checkout sheet that "Instant Buy" opens. As a layer, checkout
 * presents on top of it on every platform and closing checkout lands back on the results.
 */
export function SearchScreen() {
  const { retry } = useScanPipeline();
  return <SearchOverlay onRetry={retry} />;
}

export function VisualSearch() {
  const open = useVisualSearchStore((state) => state.open);
  return open ? <SearchScreen /> : null;
}

function SearchOverlay({ onRetry }: { onRetry: () => void }) {
  const insets = useSafeAreaInsets();
  const phase = useVisualSearchStore((state) => state.phase);
  const input = useVisualSearchStore((state) => state.input);
  const scanId = useVisualSearchStore((state) => state.scanId);
  const mediaUri = useVisualSearchStore((state) => state.mediaUri);
  const imageSize = useVisualSearchStore((state) => state.imageSize);
  const detectedKind = useVisualSearchStore((state) => state.detectedKind);
  const features = useVisualSearchStore((state) => state.features);
  const stage = useVisualSearchStore((state) => state.stage);
  const stageAt = useVisualSearchStore((state) => state.stageAt);
  const scanStartedAt = useVisualSearchStore((state) => state.scanStartedAt);
  const box = useVisualSearchStore((state) => state.box);
  const confidence = useVisualSearchStore((state) => state.confidence);
  const offers = useVisualSearchStore((state) => state.offers);
  const offerSource = useVisualSearchStore((state) => state.offerSource);
  const failure = useVisualSearchStore((state) => state.failure);
  const linkDraft = useVisualSearchStore((state) => state.linkDraft);
  const linkInvalid = useVisualSearchStore((state) => state.linkInvalid);
  const pasteOpen = useVisualSearchStore((state) => state.pasteOpen);
  const dismiss = useVisualSearchStore((state) => state.dismiss);
  const closeSession = useVisualSearchStore((state) => state.closeSession);
  const chooseInput = useVisualSearchStore((state) => state.chooseInput);
  const beginScan = useVisualSearchStore((state) => state.beginScan);
  const prepareExtract = useVisualSearchStore((state) => state.prepareExtract);
  const setImageSize = useVisualSearchStore((state) => state.setImageSize);
  const setLinkDraft = useVisualSearchStore((state) => state.setLinkDraft);
  const captureLink = useVisualSearchStore((state) => state.captureLink);

  const [rootHeight, setRootHeight] = useState(0);
  const [stageBox, setStageBox] = useState<StageBox | null>(null);
  const [drawerHeight, setDrawerHeight] = useState(rememberedDrawerHeight);
  const [brokenUri, setBrokenUri] = useState<string | null>(null);

  const matched = phase === 'matched';
  const showFrame = mediaUri !== null && (phase === 'scanning' || phase === 'matched' || phase === 'failed');
  // The frame and the diagnostics panel under it are laid out as one group, centered in the stage.
  const media =
    imageSize && stageBox
      ? containIn(imageSize, stageBox.width - FRAME_INSET_X * 2, stageBox.height - FRAME_INSET_Y * 2 - DIAGNOSTICS_SPACE)
      : null;
  const frameWidth = media?.width ?? Math.max(0, (stageBox?.width ?? 0) - FRAME_INSET_X * 2);
  const frameHeight = media?.height ?? Math.max(0, (stageBox?.height ?? 0) - FRAME_INSET_Y * 2 - DIAGNOSTICS_SPACE);
  const frameLeft = stageBox ? (stageBox.width - frameWidth) / 2 : 0;
  const frameTop = stageBox ? Math.max(FRAME_INSET_Y, (stageBox.height - frameHeight - DIAGNOSTICS_SPACE) / 2) : 0;
  const fit =
    matched && stageBox && media
      ? fitAboveDrawer(stageBox, stageBox.y + frameTop, frameHeight, rootHeight, drawerHeight)
      : { scale: 1, shift: 0 };

  const frameScale = useSharedValue(fit.scale);
  const frameShift = useSharedValue(fit.shift);
  const framePlaced = useRef(false);
  const frameReady = media !== null && (!matched || drawerHeight > 0);

  useEffect(() => {
    if (!framePlaced.current) {
      // First placement after (re)opening: jump straight to the layout instead of animating into it.
      frameScale.set(fit.scale);
      frameShift.set(fit.shift);
      framePlaced.current = frameReady;
      return;
    }
    frameScale.set(withSpring(fit.scale, FRAME_SPRING));
    frameShift.set(withSpring(fit.shift, FRAME_SPRING));
  }, [fit.scale, fit.shift, frameReady, frameScale, frameShift]);

  useEffect(() => {
    if (Platform.OS !== 'web' || typeof window === 'undefined') {
      return;
    }
    const onKey = (event: KeyboardEvent) => {
      // Checkout handles its own Escape while it sits on top.
      if (event.key === 'Escape' && !useCheckoutStore.getState().draft) {
        dismiss();
        closeSession();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [closeSession, dismiss]);

  const frameStyle = useAnimatedStyle(() => ({
    transform: [{ translateY: frameShift.get() }, { scale: frameScale.get() }],
  }));

  async function pickFrom(source: 'camera' | 'library') {
    const permission =
      source === 'camera' ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) {
      Alert.alert(t('feed.photoDeniedTitle'), t('feed.photoDenied'));
      return;
    }
    const result =
      source === 'camera' ? await ImagePicker.launchCameraAsync(PICKER_OPTIONS) : await ImagePicker.launchImageLibraryAsync(PICKER_OPTIONS);
    const asset = result.canceled ? null : result.assets[0];
    if (asset) {
      beginScan(asset.uri, 'shoes', { width: asset.width, height: asset.height });
    }
  }

  function linkSource(next: SearchInput | null): SocialSource | null {
    if (next === 'tiktok') {
      return 'tiktok';
    }
    if (next === 'instagram') {
      return 'instagram';
    }
    return null;
  }

  function choose(next: SearchInput) {
    chooseInput(next);
    if (next === 'camera') {
      void pickFrom('camera');
      return;
    }
    if (next === 'upload') {
      void pickFrom('library');
      return;
    }
    const expected = linkSource(next);
    if (expected && extractProductFrame(linkDraft)?.source === expected) {
      prepareExtract(expected);
    }
  }

  function onDraft(value: string) {
    setLinkDraft(value);
    const expected = linkSource(input);
    if (expected && extractProductFrame(value)?.source === expected) {
      prepareExtract(expected);
    }
  }

  const subtitle = subtitleFor(phase);
  const linkMode = input === 'tiktok' ? 'tiktok' : 'instagram';
  const resultTitle =
    offerSource === 'live' ? (offers.find((offer) => offer.price !== null)?.title ?? t(features.productKey)) : t(features.productKey);
  const locked = matched && stage === 'locked';

  return (
    <Animated.View
      accessibilityViewIsModal
      entering={FadeIn.duration(220)}
      exiting={FadeOut.duration(180)}
      onAccessibilityEscape={closeSession}
      onLayout={(event) => setRootHeight(Math.round(event.nativeEvent.layout.height))}
      style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, zIndex: 1000, backgroundColor: palette.ink }}
    >
      <AmbientGlow />
      <View style={{ zIndex: 80, paddingTop: insets.top + 8, paddingHorizontal: 16, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text
            accessibilityRole="header"
            style={{ color: palette.ivory, fontSize: 17, lineHeight: 22, fontWeight: '600', letterSpacing: -0.3 }}
          >
            {t('search.title')}
          </Text>
          <Text numberOfLines={1} style={{ color: phase === 'failed' ? '#E8A0A0' : palette.faint, fontSize: 12, lineHeight: 17 }}>
            {subtitle}
          </Text>
        </View>
      </View>

      <ScrollView
        horizontal
        contentContainerStyle={{ paddingHorizontal: 16, gap: 8, paddingTop: 12, paddingBottom: 4 }}
        keyboardShouldPersistTaps="handled"
        showsHorizontalScrollIndicator={false}
        style={{ flexGrow: 0 }}
      >
        <SearchActionPill
          active={input === 'camera'}
          icon={<Camera color={input === 'camera' ? palette.goldSoft : palette.ivory} size={18} strokeWidth={1.75} />}
          label={t('search.camera')}
          onPress={() => choose('camera')}
        />
        <SearchActionPill
          active={input === 'upload'}
          icon={<ImageIcon color={input === 'upload' ? palette.goldSoft : palette.ivory} size={18} strokeWidth={1.75} />}
          label={t('search.upload')}
          onPress={() => choose('upload')}
        />
        <SearchActionPill
          active={input === 'tiktok'}
          icon={<Link color={input === 'tiktok' ? palette.goldSoft : palette.ivory} size={18} strokeWidth={1.75} />}
          label={t('search.tiktok')}
          onPress={() => choose('tiktok')}
        />
        <SearchActionPill
          active={input === 'instagram'}
          icon={<Link color={input === 'instagram' ? palette.goldSoft : palette.ivory} size={18} strokeWidth={1.75} />}
          label={t('search.social')}
          onPress={() => choose('instagram')}
        />
      </ScrollView>

      {pasteOpen && phase !== 'extracting' && phase !== 'scanning' ? (
        <Animated.View entering={FadeInDown.duration(220)} style={{ marginHorizontal: 16, marginTop: 12, gap: 8 }}>
          <TextInput
            accessibilityLabel={t('search.linkPlaceholder')}
            autoCapitalize="none"
            autoCorrect={false}
            inputMode="url"
            onChangeText={onDraft}
            onSubmitEditing={() => captureLink(linkMode)}
            placeholder={t('search.linkPlaceholder')}
            placeholderTextColor={palette.faint}
            returnKeyType="go"
            style={{
              minHeight: 48,
              borderRadius: radius.pill,
              borderWidth: 1,
              borderColor: linkInvalid ? '#E25B5B' : palette.line,
              backgroundColor: 'rgba(255, 255, 255, 0.04)',
              color: palette.ivory,
              paddingHorizontal: 16,
              fontSize: 15,
            }}
            value={linkDraft}
          />
          {linkInvalid ? <Text style={{ color: '#E25B5B', fontSize: 13 }}>{t('search.linkInvalid')}</Text> : null}
          <PressableScale accessibilityLabel={t('search.linkApply')} onPress={() => captureLink(linkMode)}>
            <Text style={{ color: palette.goldSoft, fontSize: 15, fontWeight: '700', paddingVertical: 4 }}>{t('search.linkApply')}</Text>
          </PressableScale>
        </Animated.View>
      ) : null}

      <View
        onLayout={(event) => {
          const { y, width, height } = event.nativeEvent.layout;
          setStageBox({ y: Math.round(y), width: Math.round(width), height: Math.round(height) });
        }}
        style={{ flex: 1, marginTop: 12, marginBottom: insets.bottom }}
      >
        {stageBox && showFrame && mediaUri ? (
          <Animated.View
            style={[
              {
                position: 'absolute',
                left: frameLeft,
                top: frameTop,
                width: frameWidth,
                height: frameHeight,
                borderRadius: radius.panel,
                overflow: 'hidden',
                borderWidth: 1,
                borderColor: locked ? 'rgba(229, 193, 88, 0.55)' : palette.line,
                backgroundColor: '#0B0B0B',
                boxShadow: locked ? '0 0 32px rgba(212, 175, 55, 0.28)' : '0 18px 40px rgba(0, 0, 0, 0.5)',
                opacity: media ? 1 : 0,
              },
              frameStyle,
            ]}
          >
            <Image
              accessibilityIgnoresInvertColors
              cachePolicy="memory-disk"
              contentFit={media ? 'cover' : 'contain'}
              onError={() => setBrokenUri(mediaUri)}
              onLoad={(event) => setImageSize(mediaUri, { width: event.source.width, height: event.source.height })}
              recyclingKey={mediaUri}
              source={{ uri: mediaUri }}
              style={{ width: '100%', height: '100%' }}
              transition={160}
            />
            {media && (phase === 'scanning' || phase === 'matched') ? (
              <HolographicScanner
                box={box}
                compact={fit.scale < COMPACT_BELOW}
                confidence={confidence}
                height={media.height}
                key={scanId}
                label={t(KIND_LABEL[detectedKind])}
                stage={stage}
                width={media.width}
              />
            ) : null}
          </Animated.View>
        ) : null}
        {stageBox && media && (phase === 'scanning' || phase === 'matched') ? (
          <ScanDiagnostics
            box={box}
            key={`diagnostics-${scanId}`}
            stage={stage}
            stageAt={stageAt}
            startedAt={scanStartedAt}
            style={{ position: 'absolute', left: frameLeft, top: frameTop + frameHeight + 12, width: frameWidth }}
          />
        ) : null}
        {showFrame && !media && brokenUri !== mediaUri ? <FrameLoading /> : null}
        {showFrame && brokenUri === mediaUri ? <StageMessage text={t('search.imageError')} /> : null}
        {phase === 'choose' ? <ScanPrompt /> : null}
        {phase === 'extracting' ? <ExtractingCard /> : null}
      </View>

      {matched ? (
        <Pressable
          accessibilityLabel={t('search.closeA11y')}
          onPress={() => {
            playSelection();
            closeSession();
          }}
          style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, zIndex: 40, elevation: 8, backgroundColor: 'rgba(0,0,0,0.28)' }}
        />
      ) : null}
      {matched ? (
        <MatchDrawer
          offers={offers}
          onHeight={(next) => {
            rememberedDrawerHeight = next;
            setDrawerHeight(next);
          }}
          source={offerSource}
          title={resultTitle}
        />
      ) : null}
      {phase === 'failed' && failure ? <FailureCard bottom={insets.bottom + 16} failure={failure} onRetry={onRetry} /> : null}
      <View
        pointerEvents="box-none"
        style={{ position: 'absolute', top: insets.top + 8, right: 16, zIndex: 200, elevation: 24 }}
      >
        <PressableScale
          accessibilityLabel={t('search.closeA11y')}
          onPress={() => {
            playSelection();
            closeSession();
          }}
        >
          <GlassSurface radius={22} style={{ borderColor: palette.goldRing, boxShadow: elevation.gold }}>
            <View style={{ width: 44, height: 44, alignItems: 'center', justifyContent: 'center' }}>
              <X color={palette.ivory} size={18} strokeWidth={1.75} />
            </View>
          </GlassSurface>
        </PressableScale>
      </View>
    </Animated.View>
  );
}

function subtitleFor(phase: SearchPhase): string {
  if (phase === 'extracting') {
    return t('search.extracting');
  }
  if (phase === 'scanning') {
    return t('search.subtitleScanning');
  }
  if (phase === 'matched') {
    return t('search.subtitleMatched');
  }
  if (phase === 'failed') {
    return t('search.subtitleFailed');
  }
  return t('search.subtitleChoose');
}

function usePulse() {
  const reduceMotion = useReducedMotion();
  const pulse = useSharedValue(0);

  useEffect(() => {
    if (reduceMotion) {
      return;
    }
    pulse.set(withRepeat(withTiming(1, { duration: 1_100, easing: Easing.inOut(Easing.sin) }), -1, true));
    return () => cancelAnimation(pulse);
  }, [pulse, reduceMotion]);

  return useAnimatedStyle(() => ({ opacity: 0.45 + pulse.get() * 0.55 }));
}

function ScanPrompt() {
  const pulseStyle = usePulse();
  const corner = (top: boolean, left: boolean) => ({
    position: 'absolute' as const,
    width: 28,
    height: 28,
    top: top ? 0 : undefined,
    bottom: top ? undefined : 0,
    left: left ? 0 : undefined,
    right: left ? undefined : 0,
    borderColor: palette.goldSoft,
    borderTopWidth: top ? 3 : 0,
    borderBottomWidth: top ? 0 : 3,
    borderLeftWidth: left ? 3 : 0,
    borderRightWidth: left ? 0 : 3,
    borderTopLeftRadius: top && left ? 10 : 0,
    borderTopRightRadius: top && !left ? 10 : 0,
    borderBottomLeftRadius: !top && left ? 10 : 0,
    borderBottomRightRadius: !top && !left ? 10 : 0,
  });

  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 32, gap: 18 }}>
      <Animated.View style={[{ width: 148, height: 148, alignItems: 'center', justifyContent: 'center' }, pulseStyle]}>
        <View style={corner(true, true)} />
        <View style={corner(true, false)} />
        <View style={corner(false, true)} />
        <View style={corner(false, false)} />
        <ScanSearch color={palette.goldSoft} size={34} strokeWidth={1.4} />
      </Animated.View>
      <View style={{ alignItems: 'center', gap: 6 }}>
        <Text style={{ color: palette.ivory, fontSize: 20, fontWeight: '600', letterSpacing: -0.3 }}>{t('search.promptTitle')}</Text>
        <Text style={{ color: palette.muted, fontSize: 14, lineHeight: 20, textAlign: 'center' }}>{t('search.promptBody')}</Text>
      </View>
    </View>
  );
}

function FrameLoading() {
  const pulseStyle = usePulse();
  return (
    <View style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center' }}>
      <Animated.View
        style={[
          {
            width: '78%',
            aspectRatio: 4 / 3,
            borderRadius: radius.panel,
            backgroundColor: 'rgba(255, 255, 255, 0.05)',
            borderWidth: 1,
            borderColor: palette.line,
          },
          pulseStyle,
        ]}
      />
    </View>
  );
}

function StageMessage({ text }: { text: string }) {
  return (
    <View
      style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center', padding: 32 }}
    >
      <Text style={{ color: palette.muted, fontSize: 14, textAlign: 'center' }}>{text}</Text>
    </View>
  );
}

function ExtractingCard() {
  const pulseStyle = usePulse();
  return (
    <View style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center' }}>
      <GlassSurface radius={radius.pill} style={{ borderColor: 'rgba(229, 193, 88, 0.4)' }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 20, paddingVertical: 14 }}>
          <Animated.View style={[{ width: 8, height: 8, borderRadius: 4, backgroundColor: palette.goldSoft }, pulseStyle]} />
          <Text style={{ color: palette.ivory, fontSize: 15, fontWeight: '600' }}>{t('search.extracting')}</Text>
        </View>
      </GlassSurface>
    </View>
  );
}

function FailureCard({ failure, bottom, onRetry }: { failure: ScanFailure; bottom: number; onRetry: () => void }) {
  return (
    <Animated.View entering={FadeInDown.duration(260)} style={{ position: 'absolute', left: 16, right: 16, bottom, zIndex: 50 }}>
      <GlassSurface radius={radius.panel} style={{ backgroundColor: 'rgba(12, 12, 12, 0.92)' }}>
        <View style={{ padding: 18, gap: 12 }}>
          <View style={{ gap: 4 }}>
            <Text style={{ color: palette.ivory, fontSize: 17, fontWeight: '600' }}>{t('dupe.errorTitle')}</Text>
            <Text style={{ color: palette.muted, fontSize: 14, lineHeight: 20 }}>{t(FAILURE_COPY[failure])}</Text>
          </View>
          {failure === 'upload' ? null : (
            <PressableScale accessibilityLabel={t('dupe.retry')} block onPress={onRetry}>
              <View
                style={{
                  minHeight: 48,
                  borderRadius: radius.pill,
                  backgroundColor: palette.ivory,
                  flexDirection: 'row',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: 8,
                }}
              >
                <RotateCcw color={palette.ink} size={16} strokeWidth={2.25} />
                <Text style={{ color: palette.ink, fontSize: 15, fontWeight: '700' }}>{t('dupe.retry')}</Text>
              </View>
            </PressableScale>
          )}
        </View>
      </GlassSurface>
    </Animated.View>
  );
}
