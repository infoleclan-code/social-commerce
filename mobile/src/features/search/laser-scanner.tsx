import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Platform, Text, View, type ViewStyle } from 'react-native';
import Animated, {
  cancelAnimation,
  Easing,
  type SharedValue,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withDelay,
  withRepeat,
  withSequence,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import Svg, { Path } from 'react-native-svg';

import type { NormalizedBox, ScanStage } from '@/features/search/types';
import { playLock } from '@/lib/feedback';
import { t, type MessageKey } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

/**
 * Holographic scanner HUD, drawn over the exact on-screen rect of the image (the parent sizes this
 * view to the image's aspect ratio, so normalized boxes map 1:1 to pixels).
 *
 * Layers, bottom to top: dual-density micro-grid, spotlight that dims everything outside the lock,
 * neon pulse beam (core line, glow, light trail, and a brighter grid revealed in decaying bands
 * behind it), four corner reticles that breathe while acquiring and spring onto the product bounds,
 * and a tag riding the reticle frame. `ScanDiagnostics` is the floating panel that narrates the real
 * pipeline stage with elapsed time; the screen places it beside the frame so it never covers the
 * product. Every per-frame value runs on the UI thread; React re-renders only on stage changes and
 * for the 10 Hz elapsed counter.
 */

const FINE = 16;
const COARSE = FINE * 4;
const ARM = 26;
const STROKE = 3;
const SWEEP_MS = 1_350;
const LOCK_SPRING = { stiffness: 230, damping: 15, mass: 0.9 } as const;
const ABSOLUTE_FILL: ViewStyle = { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, pointerEvents: 'none' };
const MONO = Platform.select({ ios: 'Menlo', android: 'monospace', default: 'ui-monospace, SFMono-Regular, Menlo, monospace' });
/** Lock onto the whole image when the matcher returned no bounds. */
const WHOLE_FRAME: NormalizedBox = { x: 0.05, y: 0.05, width: 0.9, height: 0.9 };

const STAGE_COPY: Record<ScanStage, MessageKey> = {
  embed: 'search.scanEmbed',
  geometry: 'search.scanGeometry',
  match: 'search.scanMatch',
  locked: 'search.hudLockedLine',
};
const STAGE_STEPS: readonly ScanStage[] = ['embed', 'geometry', 'match'];

type Rect = { l: number; t: number; r: number; b: number };

type Props = {
  width: number;
  height: number;
  stage: ScanStage;
  /** Product bounds once locked; null keeps the reticles hunting (or frames the whole image when locked). */
  box: NormalizedBox | null;
  label: string;
  confidence: number | null;
  /** The frame is shown shrunk (to clear the results drawer): drop the text tag, keep the lock. */
  compact?: boolean;
};

export function HolographicScanner({ width, height, stage, box, label, confidence, compact = false }: Props) {
  const reduceMotion = useReducedMotion();
  const locked = stage === 'locked';
  const target = locked ? (box ?? WHOLE_FRAME) : null;

  if (width <= 0 || height <= 0) {
    return null;
  }

  return (
    <View style={ABSOLUTE_FILL}>
      <LinearGradient
        colors={['rgba(0,0,0,0.42)', 'rgba(0,0,0,0)', 'rgba(0,0,0,0)', 'rgba(0,0,0,0.48)'] as const}
        locations={[0, 0.28, 0.7, 1]}
        style={ABSOLUTE_FILL}
      />
      <MicroGrid height={height} locked={locked} reduceMotion={reduceMotion} width={width} />
      {target ? <Spotlight box={target} height={height} width={width} /> : null}
      {locked || reduceMotion ? null : <PulseBeam height={height} width={width} />}
      <Reticles box={target} height={height} reduceMotion={reduceMotion} width={width} />
      {target ? (
        compact ? null : (
          <LockTag box={target} confidence={confidence} height={height} label={label} width={width} />
        )
      ) : (
        <AcquireTag height={height} reduceMotion={reduceMotion} width={width} />
      )}
    </View>
  );
}

// Geometry ---------------------------------------------------------------------------------------

function rectOf(box: NormalizedBox, width: number, height: number): Rect {
  return {
    l: box.x * width,
    t: box.y * height,
    r: (box.x + box.width) * width,
    b: (box.y + box.height) * height,
  };
}

/** Where the reticles hunt before the matcher answers: a centered frame inset from the edges. */
function acquireRect(width: number, height: number): Rect {
  return { l: width * 0.16, t: height * 0.18, r: width * 0.84, b: height * 0.82 };
}

/** Grid lines centered on the frame, so the coarse grid always lands on fine lines. */
function gridPath(width: number, height: number, cell: number): string {
  const startX = width / 2 - Math.floor(width / 2 / cell) * cell;
  const startY = height / 2 - Math.floor(height / 2 / cell) * cell;
  let path = '';
  for (let x = startX; x <= width; x += cell) {
    path += `M${x.toFixed(1)} 0V${height.toFixed(1)}`;
  }
  for (let y = startY; y <= height; y += cell) {
    path += `M0 ${y.toFixed(1)}H${width.toFixed(1)}`;
  }
  return path;
}

// Micro-grid -------------------------------------------------------------------------------------

function GridLines({ width, height, fine, coarse }: { width: number; height: number; fine: string; coarse: string }) {
  const finePath = useMemo(() => gridPath(width, height, FINE), [width, height]);
  const coarsePath = useMemo(() => gridPath(width, height, COARSE), [width, height]);
  return (
    <Svg height={height} style={{ position: 'absolute', top: 0, left: 0 }} width={width}>
      <Path d={finePath} fill="none" stroke={fine} strokeWidth={0.5} />
      <Path d={coarsePath} fill="none" stroke={coarse} strokeWidth={1} />
    </Svg>
  );
}

function MicroGrid({ width, height, locked, reduceMotion }: { width: number; height: number; locked: boolean; reduceMotion: boolean }) {
  const breath = useSharedValue(0);
  const settle = useSharedValue(locked ? 1 : 0);

  useEffect(() => {
    if (locked || reduceMotion) {
      cancelAnimation(breath);
      breath.set(withTiming(0, { duration: 240 }));
    } else {
      breath.set(withRepeat(withTiming(1, { duration: 1_600, easing: Easing.inOut(Easing.sin) }), -1, true));
    }
    settle.set(withTiming(locked ? 1 : 0, { duration: 420 }));
    return () => cancelAnimation(breath);
  }, [breath, locked, reduceMotion, settle]);

  const style = useAnimatedStyle(() => ({
    opacity: (0.55 + breath.get() * 0.35) * (1 - settle.get() * 0.65),
  }));

  return (
    <Animated.View style={[ABSOLUTE_FILL, style]}>
      <GridLines coarse="rgba(229, 193, 88, 0.26)" fine="rgba(255, 255, 255, 0.08)" height={height} width={width} />
      <View
        style={{
          position: 'absolute',
          left: width / 2 - 7,
          top: height / 2 - 0.5,
          width: 14,
          height: 1,
          backgroundColor: 'rgba(229, 193, 88, 0.6)',
        }}
      />
      <View
        style={{
          position: 'absolute',
          left: width / 2 - 0.5,
          top: height / 2 - 7,
          width: 1,
          height: 14,
          backgroundColor: 'rgba(229, 193, 88, 0.6)',
        }}
      />
    </Animated.View>
  );
}

// Spotlight --------------------------------------------------------------------------------------

function Spotlight({ width, height, box }: { width: number; height: number; box: NormalizedBox }) {
  const reveal = useSharedValue(0);
  const rect = rectOf(box, width, height);

  useEffect(() => {
    reveal.set(withDelay(160, withTiming(1, { duration: 460, easing: Easing.out(Easing.cubic) })));
  }, [reveal]);

  const style = useAnimatedStyle(() => ({ opacity: reveal.get() }));
  const shade = 'rgba(0, 0, 0, 0.38)';

  return (
    <Animated.View style={[ABSOLUTE_FILL, style]}>
      <View style={{ position: 'absolute', left: 0, right: 0, top: 0, height: rect.t, backgroundColor: shade }} />
      <View style={{ position: 'absolute', left: 0, right: 0, top: rect.b, bottom: 0, backgroundColor: shade }} />
      <View style={{ position: 'absolute', left: 0, width: rect.l, top: rect.t, height: rect.b - rect.t, backgroundColor: shade }} />
      <View style={{ position: 'absolute', left: rect.r, right: 0, top: rect.t, height: rect.b - rect.t, backgroundColor: shade }} />
    </Animated.View>
  );
}

// Pulse beam -------------------------------------------------------------------------------------

type BeamFrame = { y: number; forward: boolean; speed: number };

/**
 * One loop of `phase` (0..2) is a full down-and-up sweep with sine easing. `speed` is the eased
 * velocity (0 at the turnarounds), so the trail fades out before it flips sides.
 */
function beamAt(phase: number, height: number): BeamFrame {
  'worklet';
  const forward = phase < 1;
  const progress = forward ? phase : 2 - phase;
  const eased = (1 - Math.cos(Math.PI * progress)) / 2;
  const margin = 4;
  return {
    y: margin + eased * (height - margin * 2),
    forward,
    speed: Math.sin(Math.PI * progress),
  };
}

function PulseBeam({ width, height }: { width: number; height: number }) {
  const phase = useSharedValue(0);
  const flare = useSharedValue(0);
  const trail = Math.min(160, height * 0.42);

  useEffect(() => {
    phase.set(withRepeat(withTiming(2, { duration: SWEEP_MS * 2, easing: Easing.linear }), -1, false));
    flare.set(withRepeat(withTiming(1, { duration: 980, easing: Easing.inOut(Easing.quad) }), -1, true));
    return () => {
      cancelAnimation(phase);
      cancelAnimation(flare);
    };
  }, [flare, phase]);

  const trailStyle = useAnimatedStyle(() => {
    const beam = beamAt(phase.get(), height);
    return {
      opacity: 0.2 + beam.speed * 0.8,
      transform: [{ translateY: beam.forward ? beam.y - trail : beam.y }, { scaleY: beam.forward ? 1 : -1 }],
    };
  });
  const coreStyle = useAnimatedStyle(() => ({
    transform: [{ translateY: beamAt(phase.get(), height).y - 1 }],
  }));
  const glowStyle = useAnimatedStyle(() => {
    const beam = beamAt(phase.get(), height);
    return {
      opacity: 0.55 + beam.speed * 0.45,
      transform: [{ translateY: beam.y - 9 }],
    };
  });
  const flareStyle = useAnimatedStyle(() => ({
    transform: [{ translateX: 12 + flare.get() * Math.max(0, width - 76) }, { translateY: beamAt(phase.get(), height).y - 2 }],
  }));

  return (
    <View style={[ABSOLUTE_FILL, { overflow: 'hidden' }]}>
      <WakeBand height={height} intensity={0.35} phase={phase} reach={trail} width={width} />
      <WakeBand height={height} intensity={0.3} phase={phase} reach={trail * 0.55} width={width} />
      <WakeBand height={height} intensity={0.35} phase={phase} reach={trail * 0.22} width={width} />
      <Animated.View style={[{ position: 'absolute', left: 0, right: 0, top: 0, height: trail, pointerEvents: 'none' }, trailStyle]}>
        <LinearGradient
          colors={['rgba(229, 193, 88, 0)', 'rgba(229, 193, 88, 0.07)', 'rgba(229, 193, 88, 0.3)'] as const}
          locations={[0, 0.55, 1]}
          style={{ flex: 1 }}
        />
      </Animated.View>
      <Animated.View
        style={[
          {
            position: 'absolute',
            left: 0,
            right: 0,
            top: 0,
            height: 18,
            borderRadius: 9,
            backgroundColor: 'rgba(229, 193, 88, 0.16)',
            boxShadow: '0 0 22px rgba(229, 193, 88, 0.7)',
            pointerEvents: 'none',
          },
          glowStyle,
        ]}
      />
      <Animated.View
        style={[
          {
            position: 'absolute',
            left: 0,
            right: 0,
            top: 0,
            height: 2,
            backgroundColor: palette.ivory,
            boxShadow: '0 0 6px rgba(255, 255, 255, 0.95), 0 0 16px rgba(229, 193, 88, 0.95)',
            pointerEvents: 'none',
          },
          coreStyle,
        ]}
      />
      <Animated.View
        style={[
          {
            position: 'absolute',
            top: 0,
            left: 0,
            width: 52,
            height: 4,
            borderRadius: 2,
            backgroundColor: palette.ivory,
            boxShadow: '0 0 14px rgba(255, 255, 255, 1), 0 0 30px rgba(229, 193, 88, 0.9)',
            pointerEvents: 'none',
          },
          flareStyle,
        ]}
      />
    </View>
  );
}

/**
 * A strip of brighter grid that rides directly behind the beam. The strip clips; its grid is
 * counter-translated so lines stay fixed to the image. Stacking strips of decreasing reach gives
 * the trailing light a stepped decay without a mask view.
 */
function WakeBand({
  width,
  height,
  phase,
  reach,
  intensity,
}: {
  width: number;
  height: number;
  phase: SharedValue<number>;
  reach: number;
  intensity: number;
}) {
  const clipStyle = useAnimatedStyle(() => {
    const beam = beamAt(phase.get(), height);
    return {
      opacity: intensity * beam.speed,
      transform: [{ translateY: beam.forward ? beam.y - reach : beam.y }],
    };
  });
  const gridStyle = useAnimatedStyle(() => {
    const beam = beamAt(phase.get(), height);
    return { transform: [{ translateY: -(beam.forward ? beam.y - reach : beam.y) }] };
  });

  return (
    <Animated.View
      style={[{ position: 'absolute', left: 0, top: 0, width, height: reach, overflow: 'hidden', pointerEvents: 'none' }, clipStyle]}
    >
      <Animated.View style={[{ position: 'absolute', left: 0, top: 0, width, height }, gridStyle]}>
        <GridLines coarse="rgba(255, 246, 214, 0.95)" fine="rgba(244, 215, 116, 0.75)" height={height} width={width} />
      </Animated.View>
    </Animated.View>
  );
}

// Reticles ---------------------------------------------------------------------------------------

type Corner = 'tl' | 'tr' | 'bl' | 'br';
const CORNERS: readonly Corner[] = ['tl', 'tr', 'bl', 'br'];

function Reticles({
  width,
  height,
  box,
  reduceMotion,
}: {
  width: number;
  height: number;
  box: NormalizedBox | null;
  reduceMotion: boolean;
}) {
  const locked = box !== null;
  const start = box ? rectOf(box, width, height) : acquireRect(width, height);
  const left = useSharedValue(start.l);
  const top = useSharedValue(start.t);
  const right = useSharedValue(start.r);
  const bottom = useSharedValue(start.b);
  const breath = useSharedValue(0.5);
  const lockMix = useSharedValue(locked ? 1 : 0);
  const flash = useSharedValue(0);
  const mountedLocked = useRef(locked);

  useEffect(() => {
    if (locked || reduceMotion) {
      cancelAnimation(breath);
      breath.set(withTiming(0.5, { duration: 200 }));
      return;
    }
    breath.set(withRepeat(withTiming(1, { duration: 900, easing: Easing.inOut(Easing.sin) }), -1, true));
    return () => cancelAnimation(breath);
  }, [breath, locked, reduceMotion]);

  useEffect(() => {
    const next = box ? rectOf(box, width, height) : acquireRect(width, height);
    if (!box) {
      mountedLocked.current = false;
      left.set(withTiming(next.l, { duration: 260 }));
      top.set(withTiming(next.t, { duration: 260 }));
      right.set(withTiming(next.r, { duration: 260 }));
      bottom.set(withTiming(next.b, { duration: 260 }));
      lockMix.set(withTiming(0, { duration: 200 }));
      return;
    }
    if (mountedLocked.current || reduceMotion) {
      // Reopened on a finished scan, or motion reduced: show the lock without replaying it.
      mountedLocked.current = true;
      left.set(next.l);
      top.set(next.t);
      right.set(next.r);
      bottom.set(next.b);
      lockMix.set(1);
      return;
    }
    mountedLocked.current = true;
    left.set(withSpring(next.l, LOCK_SPRING));
    top.set(withDelay(35, withSpring(next.t, LOCK_SPRING)));
    right.set(withDelay(70, withSpring(next.r, LOCK_SPRING)));
    bottom.set(withDelay(105, withSpring(next.b, LOCK_SPRING)));
    lockMix.set(withTiming(1, { duration: 380 }));
    flash.set(withSequence(withDelay(120, withTiming(1, { duration: 150 })), withTiming(0, { duration: 560 })));
    playLock();
  }, [bottom, box, flash, height, left, lockMix, reduceMotion, right, top, width]);

  const target = box ? rectOf(box, width, height) : null;
  const flashStyle = useAnimatedStyle(() => ({
    opacity: flash.get() * 0.85,
    transform: [{ scale: 1 + flash.get() * 0.05 }],
  }));
  const frameStyle = useAnimatedStyle(() => ({ opacity: lockMix.get() }));

  return (
    <View style={ABSOLUTE_FILL}>
      {target ? (
        <>
          <Animated.View
            style={[
              {
                position: 'absolute',
                left: target.l,
                top: target.t,
                width: target.r - target.l,
                height: target.b - target.t,
                borderRadius: 12,
                borderWidth: 1,
                borderColor: 'rgba(229, 193, 88, 0.42)',
                backgroundColor: 'rgba(229, 193, 88, 0.05)',
                pointerEvents: 'none',
              },
              frameStyle,
            ]}
          />
          <Animated.View
            style={[
              {
                position: 'absolute',
                left: target.l,
                top: target.t,
                width: target.r - target.l,
                height: target.b - target.t,
                borderRadius: 12,
                borderWidth: 2,
                borderColor: palette.goldSoft,
                boxShadow: '0 0 24px rgba(229, 193, 88, 0.9)',
                pointerEvents: 'none',
              },
              flashStyle,
            ]}
          />
        </>
      ) : null}
      {CORNERS.map((corner) => (
        <ReticleCorner bottom={bottom} breath={breath} corner={corner} key={corner} left={left} lockMix={lockMix} right={right} top={top} />
      ))}
    </View>
  );
}

function ReticleCorner({
  corner,
  left,
  top,
  right,
  bottom,
  breath,
  lockMix,
}: {
  corner: Corner;
  left: SharedValue<number>;
  top: SharedValue<number>;
  right: SharedValue<number>;
  bottom: SharedValue<number>;
  breath: SharedValue<number>;
  lockMix: SharedValue<number>;
}) {
  const isTop = corner === 'tl' || corner === 'tr';
  const isLeft = corner === 'tl' || corner === 'bl';

  const placement = useAnimatedStyle(() => {
    // While hunting, the frame breathes ±3.5% around its center; locked frames hold still.
    const scale = 1 + (breath.get() - 0.5) * 0.07 * (1 - lockMix.get());
    const cx = (left.get() + right.get()) / 2;
    const cy = (top.get() + bottom.get()) / 2;
    const x = isLeft ? cx - (cx - left.get()) * scale : cx + (right.get() - cx) * scale - ARM;
    const y = isTop ? cy - (cy - top.get()) * scale : cy + (bottom.get() - cy) * scale - ARM;
    return {
      opacity: 0.72 + breath.get() * 0.28 * (1 - lockMix.get()) + lockMix.get() * 0.28,
      transform: [{ translateX: x }, { translateY: y }],
    };
  });
  const ivoryStyle = useAnimatedStyle(() => ({ opacity: 1 - lockMix.get() }));
  const goldStyle = useAnimatedStyle(() => ({ opacity: lockMix.get() }));

  return (
    <Animated.View style={[{ position: 'absolute', left: 0, top: 0, width: ARM, height: ARM, pointerEvents: 'none' }, placement]}>
      <Animated.View style={[ABSOLUTE_FILL, ivoryStyle]}>
        <CornerArms
          color={palette.ivory}
          glow="0 0 4px rgba(255, 255, 255, 0.9), 0 0 12px rgba(229, 193, 88, 0.85)"
          isLeft={isLeft}
          isTop={isTop}
        />
      </Animated.View>
      <Animated.View style={[ABSOLUTE_FILL, goldStyle]}>
        <CornerArms
          color={palette.goldSoft}
          glow="0 0 6px rgba(244, 215, 116, 1), 0 0 18px rgba(212, 175, 55, 0.95)"
          isLeft={isLeft}
          isTop={isTop}
        />
      </Animated.View>
    </Animated.View>
  );
}

function CornerArms({ isTop, isLeft, color, glow }: { isTop: boolean; isLeft: boolean; color: string; glow: string }) {
  const arm: ViewStyle = { position: 'absolute', backgroundColor: color, borderRadius: STROKE / 2, boxShadow: glow };
  return (
    <>
      <View style={[arm, { left: 0, width: ARM, height: STROKE, top: isTop ? 0 : ARM - STROKE }]} />
      <View style={[arm, { top: 0, height: ARM, width: STROKE, left: isLeft ? 0 : ARM - STROKE }]} />
    </>
  );
}

// Tags -------------------------------------------------------------------------------------------

function AcquireTag({ width, height, reduceMotion }: { width: number; height: number; reduceMotion: boolean }) {
  const rect = acquireRect(width, height);
  const pulse = useSharedValue(1);

  useEffect(() => {
    if (reduceMotion) {
      return;
    }
    pulse.set(withRepeat(withTiming(0.35, { duration: 620, easing: Easing.inOut(Easing.quad) }), -1, true));
    return () => cancelAnimation(pulse);
  }, [pulse, reduceMotion]);

  const dotStyle = useAnimatedStyle(() => ({ opacity: pulse.get() }));

  return (
    <View
      style={{
        position: 'absolute',
        left: rect.l,
        top: Math.max(4, rect.t - 22),
        flexDirection: 'row',
        alignItems: 'center',
        gap: 5,
        paddingHorizontal: 6,
        paddingVertical: 2,
        borderRadius: 4,
        backgroundColor: 'rgba(0, 0, 0, 0.45)',
        pointerEvents: 'none',
      }}
    >
      <Animated.View style={[{ width: 5, height: 5, borderRadius: 3, backgroundColor: '#7CF2C6' }, dotStyle]} />
      <Text style={{ color: palette.ivory, fontSize: 9, fontWeight: '700', letterSpacing: 1.2, fontFamily: MONO }}>
        {t('search.hudAcquiring')}
      </Text>
    </View>
  );
}

function LockTag({
  width,
  height,
  box,
  label,
  confidence,
}: {
  width: number;
  height: number;
  box: NormalizedBox;
  label: string;
  confidence: number | null;
}) {
  const rect = rectOf(box, width, height);
  const reveal = useSharedValue(0);
  const above = rect.t >= 34;

  useEffect(() => {
    reveal.set(withDelay(260, withSpring(1, { stiffness: 260, damping: 20 })));
  }, [reveal]);

  const style = useAnimatedStyle(() => ({
    opacity: reveal.get(),
    transform: [{ translateY: (1 - reveal.get()) * 6 }],
  }));
  const text = confidence === null ? label : t('search.categoryBadge', { category: label, score: String(confidence) });

  return (
    <Animated.View
      style={[
        {
          position: 'absolute',
          left: Math.max(6, Math.min(rect.l, width - 200)),
          top: above ? rect.t - 30 : rect.t + 8,
          maxWidth: Math.max(120, width - 12),
          flexDirection: 'row',
          alignItems: 'center',
          gap: 6,
          paddingHorizontal: 9,
          paddingVertical: 5,
          borderRadius: 999,
          backgroundColor: 'rgba(8, 8, 8, 0.78)',
          borderWidth: 1,
          borderColor: 'rgba(229, 193, 88, 0.7)',
          boxShadow: '0 0 14px rgba(229, 193, 88, 0.45)',
          pointerEvents: 'none',
        },
        style,
      ]}
    >
      <View style={{ width: 6, height: 6, borderRadius: 3, backgroundColor: palette.goldSoft }} />
      <Text numberOfLines={1} style={{ color: palette.ivory, fontSize: 11, fontWeight: '700', letterSpacing: 0.2 }}>
        {text}
      </Text>
    </Animated.View>
  );
}

// Diagnostics panel ------------------------------------------------------------------------------

function useTypewriter(text: string, instant: boolean): string {
  const [source, setSource] = useState(text);
  const [shown, setShown] = useState(instant ? text.length : 0);

  if (source !== text) {
    setSource(text);
    setShown(instant ? text.length : 0);
  }

  useEffect(() => {
    if (instant || shown >= text.length) {
      return;
    }
    const id = setTimeout(() => setShown((count) => Math.min(text.length, count + 2)), 24);
    return () => clearTimeout(id);
  }, [instant, shown, text.length]);

  return text.slice(0, shown);
}

/** Milliseconds since `startedAt`, ticking at 10 Hz until `frozenAt` is set. */
function useElapsed(startedAt: number, frozenAt: number | null): number {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (frozenAt !== null) {
      return;
    }
    const id = setInterval(() => setNow(Date.now()), 100);
    return () => clearInterval(id);
  }, [frozenAt]);

  return Math.max(0, (frozenAt ?? now) - startedAt);
}

/** Height the screen reserves for `ScanDiagnostics`, including its gap to the frame. */
export const DIAGNOSTICS_SPACE = 86;

/**
 * Floating diagnostics panel: live stage text (typed out), elapsed time, a three-step meter and,
 * once locked, the product bounds. It holds "locked" for a beat and then fades away; reopening a
 * finished scan does not replay it.
 */
export function ScanDiagnostics({
  stage,
  startedAt,
  stageAt,
  box,
  style,
}: {
  stage: ScanStage;
  startedAt: number;
  stageAt: number;
  box: NormalizedBox | null;
  style?: ViewStyle;
}) {
  const reduceMotion = useReducedMotion();
  const locked = stage === 'locked';
  const typed = useTypewriter(t(STAGE_COPY[stage]), reduceMotion);
  const elapsed = useElapsed(startedAt, locked ? stageAt : null);
  const float = useSharedValue(0);
  const pulse = useSharedValue(0);
  const presence = useSharedValue(locked ? 0 : 1);
  const mountedLocked = useRef(locked);

  useEffect(() => {
    if (reduceMotion) {
      return;
    }
    float.set(withRepeat(withTiming(1, { duration: 2_600, easing: Easing.inOut(Easing.sin) }), -1, true));
    pulse.set(withRepeat(withTiming(1, { duration: 700, easing: Easing.inOut(Easing.quad) }), -1, true));
    return () => {
      cancelAnimation(float);
      cancelAnimation(pulse);
    };
  }, [float, pulse, reduceMotion]);

  useEffect(() => {
    if (!locked || mountedLocked.current) {
      return;
    }
    presence.set(withDelay(1_100, withTiming(0, { duration: 420 })));
  }, [locked, presence]);

  const floatStyle = useAnimatedStyle(() => ({
    opacity: presence.get(),
    transform: [{ translateY: (float.get() - 0.5) * 4 + (1 - presence.get()) * 8 }],
  }));
  const dotStyle = useAnimatedStyle(() => ({ opacity: locked ? 1 : 0.35 + pulse.get() * 0.65 }));
  const current = locked ? STAGE_STEPS.length : STAGE_STEPS.indexOf(stage);

  return (
    <Animated.View
      accessibilityLiveRegion="polite"
      accessible
      accessibilityLabel={t(STAGE_COPY[stage])}
      style={[
        {
          paddingHorizontal: 12,
          paddingVertical: 10,
          gap: 6,
          borderRadius: 16,
          backgroundColor: 'rgba(14, 13, 10, 0.78)',
          borderWidth: 1,
          borderColor: locked ? 'rgba(229, 193, 88, 0.55)' : 'rgba(229, 193, 88, 0.26)',
          boxShadow: '0 10px 28px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.08)',
          pointerEvents: 'none',
        },
        style,
        floatStyle,
      ]}
    >
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 7 }}>
        <Animated.View
          style={[{ width: 6, height: 6, borderRadius: 3, backgroundColor: locked ? palette.goldSoft : '#7CF2C6' }, dotStyle]}
        />
        <Text style={{ flex: 1, color: locked ? palette.goldSoft : palette.muted, fontSize: 10, fontWeight: '700', letterSpacing: 1.4 }}>
          {locked ? t('search.hudLocked') : t('search.hudLive')}
        </Text>
        <Text style={{ color: palette.faint, fontSize: 10, fontFamily: MONO, fontVariant: ['tabular-nums'] }}>
          {t('search.hudElapsed', { seconds: (elapsed / 1000).toFixed(2) })}
        </Text>
      </View>
      <Text numberOfLines={1} style={{ color: palette.ivory, fontSize: 12, fontFamily: MONO, letterSpacing: -0.2 }}>
        {typed}
        {locked ? '' : '▍'}
      </Text>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
        {STAGE_STEPS.map((step, index) => (
          <View
            key={step}
            style={{
              width: 22,
              height: 3,
              borderRadius: 2,
              backgroundColor:
                index < current ? palette.goldSoft : index === current ? 'rgba(229, 193, 88, 0.55)' : 'rgba(255, 255, 255, 0.14)',
            }}
          />
        ))}
        <Text style={{ marginLeft: 4, color: palette.faint, fontSize: 10, fontFamily: MONO, fontVariant: ['tabular-nums'] }}>
          {locked && box
            ? t('search.hudBox', {
                x: box.x.toFixed(2),
                y: box.y.toFixed(2),
                w: box.width.toFixed(2),
                h: box.height.toFixed(2),
              })
            : t('search.hudStep', { step: String(Math.min(current + 1, STAGE_STEPS.length)), total: String(STAGE_STEPS.length) })}
        </Text>
      </View>
    </Animated.View>
  );
}
