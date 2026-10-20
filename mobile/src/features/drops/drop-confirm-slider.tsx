import { LinearGradient } from 'expo-linear-gradient';
import { Check, ChevronsRight } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { Text, View, type AccessibilityActionEvent, type StyleProp, type TextStyle } from 'react-native';
import { Gesture, GestureDetector } from 'react-native-gesture-handler';
import Animated, {
  cancelAnimation,
  Easing,
  Extrapolation,
  interpolate,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withRepeat,
  withSequence,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import { scheduleOnRN } from 'react-native-worklets';

import { playDragStart, playLock, playRelease, playSelection } from '@/lib/feedback';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

export const DROP_SLIDER_HEIGHT = 60;

const INSET = 5;
const THUMB = DROP_SLIDER_HEIGHT - INSET * 2;
/** Where the free label area starts: just right of the resting thumb. */
const LABEL_START = THUMB + INSET * 2;
/** Release past this share of the travel and the thumb snaps home. */
const COMMIT_AT = 0.86;
/** Detent ticks at 25%, 50% and 75% of the travel. */
const DETENTS = 4;
const SHIMMER_BAND = 72;
const LEAD_GLOW = THUMB / 2 + 46;
const PRESS_SPRING = { stiffness: 420, damping: 26, mass: 0.6 } as const;
const SNAP_SPRING = { stiffness: 520, damping: 32, mass: 0.7, overshootClamping: true } as const;
const RETURN_SPRING = { stiffness: 260, damping: 17, mass: 0.8 } as const;
const POP_SPRING = { stiffness: 300, damping: 12, mass: 0.6 } as const;

type Props = {
  label: string;
  onConfirm: () => void;
  disabled?: boolean;
  confirmedLabel?: string;
  testID?: string;
};

/**
 * Swipe-to-confirm for money actions (drop slots, purchases, withdrawals).
 *
 * - Deep glass pill with an inner gradient; a liquid gold fill follows the thumb with a moving sheen.
 * - The label never sits under the thumb: it is clipped at the thumb's leading edge (an inverse
 *   mask built from a clipping layer and a counter-translated label), fades 1 -> 0 over the first
 *   55% of travel, and a glow rides ahead of the thumb to feather the cut.
 * - Haptics: a tick on touch-down, detent ticks at 25/50/75%, a rigid snap at the end stop, a soft
 *   bump on early release. Success feedback belongs to whatever `onConfirm` completes.
 * - Releasing past 86% (or flinging past the end from halfway) snaps to 100% with a clamped spring
 *   and a thumb "pop"; anything short springs back with the release velocity.
 * - Touch and mouse drags run entirely on the UI thread; React renders only on confirm.
 * - Screen readers get a single button with an "activate" action instead of a drag.
 */
export function DropConfirmSlider({ label, onConfirm, disabled = false, confirmedLabel, testID }: Props) {
  const reduceMotion = useReducedMotion();
  const width = useSharedValue(0);
  const x = useSharedValue(0);
  const origin = useSharedValue(0);
  const press = useSharedValue(0);
  const done = useSharedValue(0);
  const detent = useSharedValue(0);
  const shimmer = useSharedValue(0);
  const pop = useSharedValue(0);
  /** Set once `onConfirm` has run; guards against a gesture and an accessibility action racing. */
  const committed = useSharedValue(0);
  const [trackWidth, setTrackWidth] = useState(0);
  const [confirmed, setConfirmed] = useState(false);
  const locked = disabled || confirmed;

  useEffect(() => {
    if (reduceMotion || confirmed) {
      cancelAnimation(shimmer);
      return;
    }
    shimmer.set(0);
    shimmer.set(
      withRepeat(
        withSequence(withTiming(1, { duration: 1_600, easing: Easing.inOut(Easing.quad) }), withTiming(1, { duration: 900 })),
        -1,
        false,
      ),
    );
    return () => cancelAnimation(shimmer);
  }, [confirmed, reduceMotion, shimmer]);

  function commit() {
    if (committed.get() === 1) {
      return;
    }
    committed.set(1);
    setConfirmed(true);
    onConfirm();
  }

  function confirmFromAccessibility() {
    if (locked || done.get() === 1) {
      return;
    }
    const max = Math.max(1, trackWidth - THUMB - INSET * 2);
    done.set(1);
    playLock();
    x.set(
      withTiming(max, { duration: 240, easing: Easing.out(Easing.cubic) }, (finished) => {
        if (finished) {
          scheduleOnRN(commit);
        }
      }),
    );
  }

  const pan = Gesture.Pan()
    .enabled(!locked)
    .activeOffsetX([-6, 6])
    .failOffsetY([-16, 16])
    .hitSlop({ horizontal: 14, vertical: 10 })
    .onBegin(() => {
      'worklet';
      press.set(withSpring(1, PRESS_SPRING));
    })
    .onStart(() => {
      'worklet';
      origin.set(x.get());
      scheduleOnRN(playDragStart);
    })
    .onUpdate((event) => {
      'worklet';
      if (done.get() === 1) {
        return;
      }
      const max = Math.max(1, width.get() - THUMB - INSET * 2);
      const next = Math.min(Math.max(origin.get() + event.translationX, 0), max);
      x.set(next);
      const progress = next / max;
      const step = Math.min(DETENTS - 1, Math.floor(progress * DETENTS));
      if (step > detent.get()) {
        detent.set(step);
        scheduleOnRN(playSelection);
      } else if (step < detent.get()) {
        detent.set(step);
      }
      if (progress >= 0.995) {
        // Reached the end stop while still dragging: lock immediately.
        done.set(1);
        pop.set(withSequence(withTiming(1, { duration: 110 }), withSpring(0, POP_SPRING)));
        x.set(
          withSpring(max, SNAP_SPRING, (finished) => {
            if (finished) {
              scheduleOnRN(commit);
            }
          }),
        );
        scheduleOnRN(playLock);
      }
    })
    .onEnd((event) => {
      'worklet';
      if (done.get() === 1) {
        return;
      }
      const max = Math.max(1, width.get() - THUMB - INSET * 2);
      const progress = x.get() / max;
      const projected = (x.get() + event.velocityX * 0.15) / max;
      if (progress >= COMMIT_AT || (progress >= 0.5 && projected >= 1.1)) {
        done.set(1);
        pop.set(withSequence(withTiming(1, { duration: 110 }), withSpring(0, POP_SPRING)));
        x.set(
          withSpring(max, { ...SNAP_SPRING, velocity: event.velocityX }, (finished) => {
            if (finished) {
              scheduleOnRN(commit);
            }
          }),
        );
        scheduleOnRN(playLock);
        return;
      }
      detent.set(0);
      x.set(withSpring(0, { ...RETURN_SPRING, velocity: event.velocityX }));
      if (progress > 0.04) {
        scheduleOnRN(playRelease);
      }
    })
    .onFinalize(() => {
      'worklet';
      press.set(withSpring(0, PRESS_SPRING));
    });

  const fillStyle = useAnimatedStyle(() => {
    const max = Math.max(1, width.get() - THUMB - INSET * 2);
    const progress = Math.min(1, x.get() / max);
    return {
      opacity: 0.62 + progress * 0.38,
      transform: [{ translateX: x.get() + LABEL_START - width.get() }],
    };
  });
  const sheenStyle = useAnimatedStyle(() => ({
    opacity: 1 - committed.get(),
    transform: [{ translateX: -SHIMMER_BAND + shimmer.get() * (width.get() + SHIMMER_BAND) }],
  }));
  const leadStyle = useAnimatedStyle(() => {
    const max = Math.max(1, width.get() - THUMB - INSET * 2);
    const progress = Math.min(1, x.get() / max);
    return {
      opacity: interpolate(progress, [0, 0.05, 0.9, 1], [0, 1, 1, 0], Extrapolation.CLAMP),
      transform: [{ translateX: x.get() + INSET + THUMB / 2 }],
    };
  });
  const clipStyle = useAnimatedStyle(() => ({ transform: [{ translateX: x.get() + LABEL_START }] }));
  const counterStyle = useAnimatedStyle(() => ({ transform: [{ translateX: -(x.get() + LABEL_START) }] }));
  const labelStyle = useAnimatedStyle(() => {
    const max = Math.max(1, width.get() - THUMB - INSET * 2);
    const progress = Math.min(1, x.get() / max);
    return {
      opacity: interpolate(progress, [0, 0.55], [1, 0], Extrapolation.CLAMP),
      transform: [{ translateX: progress * 14 }],
    };
  });
  const bandStyle = useAnimatedStyle(() => {
    const travel = LABEL_START - SHIMMER_BAND + shimmer.get() * (width.get() - LABEL_START + SHIMMER_BAND);
    return { transform: [{ translateX: travel }] };
  });
  const bandCounterStyle = useAnimatedStyle(() => {
    const travel = LABEL_START - SHIMMER_BAND + shimmer.get() * (width.get() - LABEL_START + SHIMMER_BAND);
    return { transform: [{ translateX: -travel }] };
  });
  const glowStyle = useAnimatedStyle(() => {
    const max = Math.max(1, width.get() - THUMB - INSET * 2);
    const progress = Math.min(1, x.get() / max);
    return { opacity: Math.min(1, press.get() * 0.7 + progress * 0.5 + pop.get() * 0.4) };
  });
  const thumbStyle = useAnimatedStyle(() => ({
    transform: [{ translateX: x.get() }, { scale: 1 + press.get() * 0.06 + pop.get() * 0.12 }],
  }));
  const thumbRingStyle = useAnimatedStyle(() => ({
    opacity: press.get() * 0.9 + pop.get(),
    transform: [{ scale: 1 + pop.get() * 0.25 }],
  }));

  const labelText: TextStyle = { fontSize: 15, fontWeight: '600', letterSpacing: 0.2, textAlign: 'center' };

  return (
    <View
      accessibilityActions={[{ name: 'activate', label }]}
      accessibilityHint={t('checkout.swipeA11yHint')}
      accessibilityLabel={confirmed ? (confirmedLabel ?? t('checkout.swipeConfirmed')) : label}
      accessibilityRole="button"
      accessibilityState={{ disabled: locked }}
      accessible
      onAccessibilityAction={(event: AccessibilityActionEvent) => {
        if (event.nativeEvent.actionName === 'activate') {
          confirmFromAccessibility();
        }
      }}
      style={{ height: DROP_SLIDER_HEIGHT, opacity: disabled && !confirmed ? 0.55 : 1 }}
      testID={testID}
    >
      <Animated.View
        style={[
          {
            position: 'absolute',
            top: 0,
            right: 0,
            bottom: 0,
            left: 0,
            borderRadius: DROP_SLIDER_HEIGHT / 2,
            boxShadow: '0 0 26px rgba(212, 175, 55, 0.55), 0 0 2px rgba(229, 193, 88, 0.9)',
            pointerEvents: 'none',
          },
          glowStyle,
        ]}
      />
      <View
        onLayout={(event) => {
          const next = event.nativeEvent.layout.width;
          width.set(next);
          setTrackWidth(next);
        }}
        style={{
          flex: 1,
          borderRadius: DROP_SLIDER_HEIGHT / 2,
          overflow: 'hidden',
          borderWidth: 1,
          borderColor: 'rgba(255, 255, 255, 0.13)',
          backgroundColor: 'rgba(255, 255, 255, 0.05)',
          boxShadow: 'inset 0 1px 0 rgba(255, 255, 255, 0.16), inset 0 -12px 20px rgba(0, 0, 0, 0.38)',
        }}
      >
        <LinearGradient
          colors={['rgba(212, 175, 55, 0.12)', 'rgba(255, 255, 255, 0.02)', 'rgba(212, 175, 55, 0.07)'] as const}
          end={{ x: 1, y: 1 }}
          start={{ x: 0, y: 0 }}
          style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }}
        />

        {/* Inverse mask: everything left of the thumb's leading edge is clipped away. */}
        <Animated.View
          style={[{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, overflow: 'hidden', pointerEvents: 'none' }, clipStyle]}
        >
          <Animated.View style={[{ position: 'absolute', top: 0, bottom: 0, left: 0, width: trackWidth }, counterStyle]}>
            <Animated.View style={[{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }, labelStyle]}>
              <LabelRow style={[labelText, { color: 'rgba(255, 255, 255, 0.58)' }]} text={label} />
              <Animated.View
                style={[{ position: 'absolute', top: 0, bottom: 0, left: 0, width: SHIMMER_BAND, overflow: 'hidden' }, bandStyle]}
              >
                <Animated.View style={[{ position: 'absolute', top: 0, bottom: 0, left: 0, width: trackWidth }, bandCounterStyle]}>
                  <LabelRow style={[labelText, { color: palette.ivory }]} text={label} />
                </Animated.View>
              </Animated.View>
            </Animated.View>
          </Animated.View>
        </Animated.View>

        {/* Liquid fill: a full-width gold layer slid in from the left, its rounded edge riding the thumb. */}
        <Animated.View
          style={[
            {
              position: 'absolute',
              top: 0,
              bottom: 0,
              left: 0,
              right: 0,
              borderRadius: DROP_SLIDER_HEIGHT / 2,
              overflow: 'hidden',
              boxShadow: '0 0 18px rgba(212, 175, 55, 0.6)',
              pointerEvents: 'none',
            },
            fillStyle,
          ]}
        >
          <LinearGradient
            colors={['#7A5A10', palette.gold, palette.goldSoft] as const}
            end={{ x: 1, y: 0.5 }}
            start={{ x: 0, y: 0.5 }}
            style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }}
          />
          <Animated.View style={[{ position: 'absolute', top: 0, bottom: 0, left: 0, width: SHIMMER_BAND }, sheenStyle]}>
            <LinearGradient
              colors={['rgba(255, 255, 255, 0)', 'rgba(255, 255, 255, 0.45)', 'rgba(255, 255, 255, 0)'] as const}
              end={{ x: 1, y: 0.5 }}
              start={{ x: 0, y: 0.5 }}
              style={{ flex: 1 }}
            />
          </Animated.View>
          <View style={{ position: 'absolute', top: 1, left: 0, right: 0, height: 1, backgroundColor: 'rgba(255, 255, 255, 0.35)' }} />
          {confirmed ? (
            <View
              style={{
                position: 'absolute',
                top: 0,
                bottom: 0,
                left: 0,
                right: LABEL_START,
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Text style={[labelText, { color: palette.ink, fontWeight: '700' }]}>{confirmedLabel ?? t('checkout.swipeConfirmed')}</Text>
            </View>
          ) : null}
        </Animated.View>

        {/* Liquid light spilling ahead of the thumb feathers the label's clip edge. It starts under
            the thumb's center, so only its soft tail shows. */}
        <Animated.View style={[{ position: 'absolute', top: 0, bottom: 0, left: 0, width: LEAD_GLOW, pointerEvents: 'none' }, leadStyle]}>
          <LinearGradient
            colors={['rgba(229, 193, 88, 0.42)', 'rgba(229, 193, 88, 0.12)', 'rgba(229, 193, 88, 0)'] as const}
            end={{ x: 1, y: 0.5 }}
            locations={[0, 0.55, 1]}
            start={{ x: 0, y: 0.5 }}
            style={{ flex: 1 }}
          />
        </Animated.View>

        <GestureDetector gesture={pan} touchAction="pan-y" userSelect="none">
          <Animated.View
            style={[
              { position: 'absolute', top: INSET, left: INSET, width: THUMB, height: THUMB, cursor: locked ? 'auto' : 'pointer' },
              thumbStyle,
            ]}
          >
            <Animated.View
              style={[
                {
                  position: 'absolute',
                  top: -4,
                  right: -4,
                  bottom: -4,
                  left: -4,
                  borderRadius: (THUMB + 8) / 2,
                  borderWidth: 1.5,
                  borderColor: 'rgba(244, 215, 116, 0.9)',
                  boxShadow: '0 0 16px rgba(229, 193, 88, 0.85)',
                  pointerEvents: 'none',
                },
                thumbRingStyle,
              ]}
            />
            <LinearGradient
              colors={[palette.ivory, '#EFE3C0'] as const}
              end={{ x: 1, y: 1 }}
              start={{ x: 0, y: 0 }}
              style={{
                flex: 1,
                borderRadius: THUMB / 2,
                alignItems: 'center',
                justifyContent: 'center',
                boxShadow: '0 6px 16px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.9)',
              }}
            >
              {confirmed ? (
                <Check color={palette.ink} size={22} strokeWidth={2.75} />
              ) : (
                <ChevronsRight color={palette.ink} size={22} strokeWidth={2.25} />
              )}
            </LinearGradient>
          </Animated.View>
        </GestureDetector>
      </View>
    </View>
  );
}

function LabelRow({ text, style }: { text: string; style: StyleProp<TextStyle> }) {
  return (
    <View
      style={{
        position: 'absolute',
        top: 0,
        right: 0,
        bottom: 0,
        left: 0,
        paddingLeft: LABEL_START,
        paddingRight: INSET * 3,
        alignItems: 'center',
        justifyContent: 'center',
      }}
    >
      <Text numberOfLines={1} style={style}>
        {text}
      </Text>
    </View>
  );
}
