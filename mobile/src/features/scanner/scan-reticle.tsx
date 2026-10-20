import { LinearGradient } from 'expo-linear-gradient';
import { useEffect } from 'react';
import { View } from 'react-native';
import Animated, {
  cancelAnimation,
  Easing,
  interpolate,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withRepeat,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import Svg, { Path } from 'react-native-svg';

import { palette } from '@/theme/tokens';

export type ReticleMode = 'live' | 'analyzing' | 'locked';

type Props = {
  width: number;
  height: number;
  mode: ReticleMode;
};

const GRID_STEP = 22;
const TRAIL = 72;
const CORNER = 30;

function gridPath(width: number, height: number): string {
  const parts: string[] = [];
  for (let y = GRID_STEP; y < height; y += GRID_STEP) {
    parts.push(`M0 ${y} H${width}`);
  }
  for (let x = GRID_STEP; x < width; x += GRID_STEP) {
    parts.push(`M${x} 0 V${height}`);
  }
  return parts.join(' ');
}

/**
 * The scanner's targeting frame: a fine grid, gold corner brackets and a radar sweep line with a fading
 * trail. Live: a slow sweep. Analyzing: a fast sweep, brighter grid and a radar ping from the centre.
 * Locked: the sweep stops and the brackets snap in. All motion runs on the UI thread; with Reduce Motion
 * on, the line rests in the middle.
 */
export function ScanReticle({ width, height, mode }: Props) {
  const reduceMotion = useReducedMotion();
  const sweep = useSharedValue(0);
  const ping = useSharedValue(0);
  const intensity = useSharedValue(0);
  const lock = useSharedValue(0);

  useEffect(() => {
    intensity.set(withTiming(mode === 'analyzing' ? 1 : 0, { duration: 260 }));
    lock.set(mode === 'locked' ? withSpring(1, { damping: 14, stiffness: 220 }) : withTiming(0, { duration: 160 }));

    if (reduceMotion || mode === 'locked') {
      cancelAnimation(sweep);
      cancelAnimation(ping);
      sweep.set(withTiming(0.5, { duration: 200 }));
      ping.set(0);
      return;
    }
    const duration = mode === 'analyzing' ? 1_050 : 2_300;
    sweep.set(0);
    sweep.set(withRepeat(withTiming(1, { duration, easing: Easing.inOut(Easing.quad) }), -1, false));
    if (mode === 'analyzing') {
      ping.set(0);
      ping.set(withRepeat(withTiming(1, { duration: 1_200, easing: Easing.out(Easing.cubic) }), -1, false));
    } else {
      cancelAnimation(ping);
      ping.set(0);
    }
    return () => {
      cancelAnimation(sweep);
      cancelAnimation(ping);
    };
  }, [intensity, lock, mode, ping, reduceMotion, sweep]);

  const lineStyle = useAnimatedStyle(() => ({
    opacity: mode === 'locked' ? 0 : 1,
    transform: [{ translateY: interpolate(sweep.get(), [0, 1], [-TRAIL, height - TRAIL]) }],
  }));
  const gridStyle = useAnimatedStyle(() => ({ opacity: 0.32 + intensity.get() * 0.5 }));
  const pingStyle = useAnimatedStyle(() => {
    const value = ping.get();
    return {
      opacity: value === 0 ? 0 : interpolate(value, [0, 0.1, 1], [0, 0.55, 0]),
      transform: [{ scale: interpolate(value, [0, 1], [0.2, 1.5]) }],
    };
  });
  const cornerStyle = useAnimatedStyle(() => ({
    transform: [{ scale: 1 - lock.get() * 0.04 }],
    opacity: 0.85 + lock.get() * 0.15,
  }));

  const pingSize = Math.min(width, height) * 0.72;

  return (
    <View style={{ width, height, pointerEvents: 'none' }}>
      <View style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, borderRadius: 22, overflow: 'hidden' }}>
        <Animated.View style={[{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }, gridStyle]}>
          <Svg height={height} width={width}>
            <Path d={gridPath(width, height)} stroke="rgba(229, 193, 88, 0.28)" strokeWidth={0.6} />
          </Svg>
        </Animated.View>

        <Animated.View
          style={[
            {
              position: 'absolute',
              left: (width - pingSize) / 2,
              top: (height - pingSize) / 2,
              width: pingSize,
              height: pingSize,
              borderRadius: pingSize / 2,
              borderWidth: 1.5,
              borderColor: palette.goldSoft,
            },
            pingStyle,
          ]}
        />

        <Animated.View style={[{ position: 'absolute', left: 0, right: 0, top: 0, height: TRAIL }, lineStyle]}>
          <LinearGradient colors={['rgba(229, 193, 88, 0)', 'rgba(229, 193, 88, 0.22)']} style={{ flex: 1 }} />
          <View style={{ height: 2, backgroundColor: palette.goldSoft, boxShadow: '0 0 14px rgba(229, 193, 88, 0.95)' }} />
        </Animated.View>
      </View>

      <Animated.View style={[{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }, cornerStyle]}>
        <Corner horizontal="left" vertical="top" />
        <Corner horizontal="right" vertical="top" />
        <Corner horizontal="left" vertical="bottom" />
        <Corner horizontal="right" vertical="bottom" />
      </Animated.View>
    </View>
  );
}

function Corner({ vertical, horizontal }: { vertical: 'top' | 'bottom'; horizontal: 'left' | 'right' }) {
  const top = vertical === 'top';
  const left = horizontal === 'left';
  return (
    <View
      style={{
        position: 'absolute',
        width: CORNER,
        height: CORNER,
        top: top ? 0 : undefined,
        bottom: top ? undefined : 0,
        left: left ? 0 : undefined,
        right: left ? undefined : 0,
        borderColor: palette.goldSoft,
        borderTopWidth: top ? 3 : 0,
        borderBottomWidth: top ? 0 : 3,
        borderLeftWidth: left ? 3 : 0,
        borderRightWidth: left ? 0 : 3,
        borderTopLeftRadius: top && left ? 22 : 0,
        borderTopRightRadius: top && !left ? 22 : 0,
        borderBottomLeftRadius: !top && left ? 22 : 0,
        borderBottomRightRadius: !top && !left ? 22 : 0,
      }}
    />
  );
}
