import { useEffect } from 'react';
import { Text, View } from 'react-native';
import Animated, {
  cancelAnimation,
  useAnimatedStyle,
  useSharedValue,
  withDelay,
  withRepeat,
  withTiming,
} from 'react-native-reanimated';

import { regionsFor, type RegionKind, type ScanRegion } from '@/features/dupes/catalog';
import { t, type MessageKey } from '@/lib/i18n';
import { elevation, palette, radius } from '@/theme/tokens';

const KIND_KEY: Record<RegionKind, MessageKey> = {
  shoes: 'dupe.kind.shoes',
  top: 'dupe.kind.top',
  jacket: 'dupe.kind.jacket',
  accessory: 'dupe.kind.accessory',
};

type Props = {
  productId: string;
};

export function ScanOverlay({ productId }: Props) {
  const regions = regionsFor(productId);
  const travel = useSharedValue(0);

  useEffect(() => {
    travel.value = withRepeat(withTiming(1, { duration: 1400 }), -1, false);
    return () => {
      cancelAnimation(travel);
    };
  }, [travel]);

  const lineStyle = useAnimatedStyle(() => ({
    transform: [{ translateY: travel.value * 520 }],
  }));

  return (
    <View
      style={{
        position: 'absolute',
        top: 0,
        right: 0,
        bottom: 0,
        left: 0,
        pointerEvents: 'none',
      }}
    >
      <View style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: palette.scrim, opacity: 0.35 }} />
      <Animated.View
        style={[
          {
            position: 'absolute',
            left: 24,
            right: 24,
            top: 0,
            height: 2,
            backgroundColor: palette.goldSoft,
            boxShadow: elevation.gold,
          },
          lineStyle,
        ]}
      />
      {regions.map((region, index) => (
        <ScanBox index={index} key={`${region.kind}-${index}`} region={region} />
      ))}
      <View
        style={{
          position: 'absolute',
          top: 108,
          alignSelf: 'center',
          borderRadius: radius.pill,
          borderWidth: 1,
          borderColor: palette.goldRing,
          backgroundColor: palette.goldWash,
          paddingHorizontal: 12,
          paddingVertical: 6,
        }}
      >
        <Text style={{ color: palette.goldSoft, fontSize: 11, fontWeight: '600', letterSpacing: 1.5, textTransform: 'uppercase' }}>
          {t('dupe.scanning')}
        </Text>
      </View>
    </View>
  );
}

function ScanBox({ region, index }: { region: ScanRegion; index: number }) {
  const pulse = useSharedValue(0.35);

  useEffect(() => {
    pulse.value = withDelay(index * 160, withRepeat(withTiming(1, { duration: 700 }), -1, true));
    return () => {
      cancelAnimation(pulse);
    };
  }, [index, pulse]);

  const frame = useAnimatedStyle(() => ({
    opacity: 0.45 + pulse.value * 0.55,
  }));

  return (
    <Animated.View
      style={[
        {
          position: 'absolute',
          left: `${region.x * 100}%`,
          top: `${region.y * 100}%`,
          width: `${region.width * 100}%`,
          height: `${region.height * 100}%`,
          borderWidth: 1.5,
          borderColor: palette.goldSoft,
          borderRadius: radius.chip,
          boxShadow: elevation.gold,
        },
        frame,
      ]}
    >
      <View
        style={{
          alignSelf: 'flex-start',
          margin: 6,
          borderRadius: radius.pill,
          backgroundColor: palette.ink,
          paddingHorizontal: 8,
          paddingVertical: 3,
        }}
      >
        <Text style={{ color: palette.goldSoft, fontSize: 10, fontWeight: '600', letterSpacing: 1.2, textTransform: 'uppercase' }}>
          {t(KIND_KEY[region.kind])}
        </Text>
      </View>
    </Animated.View>
  );
}
