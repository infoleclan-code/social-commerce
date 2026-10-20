import { useEffect } from 'react';
import { Text, View } from 'react-native';
import Animated, { cancelAnimation, useAnimatedStyle, useSharedValue, withRepeat, withTiming } from 'react-native-reanimated';

import { palette, radius } from '@/theme/tokens';

type Props = {
  label: string;
  playing?: boolean;
};

export function TimerPill({ label, playing = true }: Props) {
  const pulse = useSharedValue(0.35);

  useEffect(() => {
    if (!playing) {
      cancelAnimation(pulse);
      pulse.value = 0.35;
      return;
    }
    pulse.value = withRepeat(withTiming(1, { duration: 1400 }), -1, true);
    return () => {
      cancelAnimation(pulse);
    };
  }, [playing, pulse]);

  const glow = useAnimatedStyle(() => ({
    opacity: 0.45 + pulse.value * 0.55,
  }));

  return (
    <View
      style={{
        alignSelf: 'flex-start',
        overflow: 'hidden',
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: palette.lineStrong,
        backgroundColor: palette.sheet,
      }}
    >
      <Animated.View
        style={[
          {
            position: 'absolute',
            top: 0,
            right: 0,
            bottom: 0,
            left: 0,
            backgroundColor: palette.goldPulse,
            pointerEvents: 'none',
          },
          glow,
        ]}
      />
      <Text style={{ color: palette.ivory, fontSize: 12, fontWeight: '600', letterSpacing: 0.6, paddingHorizontal: 10, paddingVertical: 5 }}>
        {label}
      </Text>
    </View>
  );
}
