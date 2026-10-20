import { useEffect } from 'react';
import { View } from 'react-native';
import Animated, { Easing, useAnimatedStyle, useSharedValue, withTiming } from 'react-native-reanimated';

import { elevation, palette, radius } from '@/theme/tokens';

type Props = {
  ratio: number;
};

export function ProgressBar({ ratio }: Props) {
  const progress = useSharedValue(0);

  useEffect(() => {
    const clamped = Math.min(1, Math.max(0, ratio));
    progress.value = withTiming(clamped, {
      duration: 900,
      easing: Easing.out(Easing.cubic),
    });
  }, [progress, ratio]);

  const fillStyle = useAnimatedStyle(() => ({
    width: `${progress.value * 100}%`,
  }));

  return (
    <View
      style={{
        height: 3,
        borderRadius: radius.pill,
        backgroundColor: palette.line,
      }}
    >
      <Animated.View
        style={[
          fillStyle,
          {
            height: '100%',
            borderRadius: radius.pill,
            backgroundColor: palette.goldSoft,
            boxShadow: elevation.goldBar,
          },
        ]}
      />
    </View>
  );
}
