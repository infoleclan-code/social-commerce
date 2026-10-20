import { useEffect } from 'react';
import { View } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withRepeat, withSpring, withTiming } from 'react-native-reanimated';

import { palette } from '@/theme/tokens';

export function SuccessBurst() {
  const ring = useSharedValue(0.86);
  const tick = useSharedValue(0);

  useEffect(() => {
    ring.value = withRepeat(withTiming(1.16, { duration: 420 }), 2, true);
    tick.value = withSpring(1, { damping: 12, stiffness: 180 });
  }, [ring, tick]);

  const ringStyle = useAnimatedStyle(() => ({
    opacity: 0.35 + (1.16 - ring.value) * 1.6,
    transform: [{ scale: ring.value }],
    boxShadow: '0 0 22px rgba(212, 175, 55, 0.85)',
  }));
  const tickStyle = useAnimatedStyle(() => ({
    opacity: tick.value,
    transform: [{ scale: 0.72 + tick.value * 0.28 }],
  }));

  return (
    <View style={{ width: 84, height: 84, alignItems: 'center', justifyContent: 'center' }}>
      <Animated.View
        style={[
          {
            position: 'absolute',
            width: 84,
            height: 84,
            borderRadius: 42,
            borderWidth: 2,
            borderColor: palette.gold,
          },
          ringStyle,
        ]}
      />
      <Animated.View
        style={[
          {
            width: 64,
            height: 64,
            borderRadius: 32,
            backgroundColor: palette.gold,
            alignItems: 'center',
            justifyContent: 'center',
          },
          tickStyle,
        ]}
      >
        <View
          style={{
            width: 14,
            height: 24,
            borderRightWidth: 3,
            borderBottomWidth: 3,
            borderColor: palette.ink,
            transform: [{ rotate: '40deg' }],
            marginTop: -4,
          }}
        />
      </Animated.View>
    </View>
  );
}
