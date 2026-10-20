import { Text, View } from 'react-native';
import Animated, { Easing, useAnimatedStyle, useSharedValue, withTiming } from 'react-native-reanimated';

import { PressableScale } from '@/components/ui/pressable-scale';
import type { SlotAction } from '@/features/feed/fixtures';
import { t } from '@/lib/i18n';
import { useCheckoutStore } from '@/stores/checkout';
import { useDropJoinStore } from '@/stores/drop-join';
import { palette } from '@/theme/tokens';

type Props = {
  slotId: string;
  action: SlotAction;
  idleJoinLabel?: string;
  idleBuyLabel?: string;
  itemTitle?: string;
};

export function SlotActionButton({ slotId, action, idleJoinLabel, idleBuyLabel, itemTitle }: Props) {
  const joined = useDropJoinStore((state) => Boolean(state.joinedIds[slotId]));
  const openCheckout = useCheckoutStore((state) => state.open);
  const sheen = useSharedValue(-1.2);
  const sheenStyle = useAnimatedStyle(() => ({
    transform: [{ translateX: sheen.value * 220 }],
  }));

  const label = joined
    ? action === 'join'
      ? t('feed.joined')
      : t('feed.requested')
    : action === 'join'
      ? (idleJoinLabel ?? t('feed.join'))
      : (idleBuyLabel ?? t('feed.buy'));

  return (
    <PressableScale
      block
      accessibilityHint={action === 'join' ? t('feed.slotNote') : t('feed.buyNote')}
      accessibilityLabel={label}
      accessibilityState={{ selected: joined }}
      onPress={() => {
        sheen.value = -1.2;
        sheen.value = withTiming(1.4, { duration: 680, easing: Easing.out(Easing.cubic) });
        if (!joined) {
          openCheckout(slotId, action, itemTitle);
        }
      }}
      className="overflow-hidden rounded-full"
    >
      <View
        className="h-12 items-center justify-center"
        style={{ backgroundColor: joined ? palette.goldSoft : palette.ivory }}
      >
        <Animated.View
          style={[
            {
              position: 'absolute',
              top: 0,
              bottom: 0,
              width: 56,
              backgroundColor: palette.gold,
              opacity: joined ? 0.35 : 0.55,
              pointerEvents: 'none',
            },
            sheenStyle,
          ]}
        />
        <Text className="text-[15px] font-semibold tracking-wide text-ink">{label}</Text>
      </View>
    </PressableScale>
  );
}
