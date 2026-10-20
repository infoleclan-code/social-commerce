import { useEffect } from 'react';
import { Text, View } from 'react-native';
import Animated, { cancelAnimation, useAnimatedStyle, useSharedValue, withRepeat, withTiming } from 'react-native-reanimated';

import { t } from '@/lib/i18n';
import { elevation, palette, radius } from '@/theme/tokens';

export function LiveBadge({ playing = true }: { playing?: boolean }) {
  const pulse = useSharedValue(0.4);

  useEffect(() => {
    if (!playing) {
      cancelAnimation(pulse);
      pulse.value = 0.4;
      return;
    }
    pulse.value = withRepeat(withTiming(1, { duration: 1100 }), -1, true);
    return () => {
      cancelAnimation(pulse);
    };
  }, [playing, pulse]);

  const glow = useAnimatedStyle(() => ({
    opacity: 0.72 + pulse.value * 0.28,
  }));

  return (
    <Animated.View
      style={[
        {
          flexDirection: 'row',
          alignItems: 'center',
          gap: 6,
          alignSelf: 'flex-start',
          paddingHorizontal: 10,
          paddingVertical: 5,
          borderRadius: radius.pill,
          backgroundColor: palette.goldWash,
          borderWidth: 1,
          borderColor: palette.goldRing,
          boxShadow: elevation.gold,
        },
        glow,
      ]}
    >
      <View style={{ width: 6, height: 6, borderRadius: 3, backgroundColor: palette.goldSoft }} />
      <Text
        style={{
          color: palette.goldSoft,
          fontSize: 10,
          fontWeight: '600',
          letterSpacing: 1.5,
          textTransform: 'uppercase',
        }}
      >
        {t('feed.live')}
      </Text>
    </Animated.View>
  );
}
