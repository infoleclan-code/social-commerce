import { Pressable, Text, View } from 'react-native';

import { GlassSurface } from '@/components/ui/glass-surface';
import { LiveBadge } from '@/components/ui/live-badge';
import { MediaCard } from '@/components/ui/media-card';
import { ProgressBar } from '@/components/ui/progress-bar';
import { TimerPill } from '@/components/ui/timer-pill';
import type { FeedDrop } from '@/features/feed/fixtures';
import { SlotActionButton } from '@/features/feed/slot-action-button';
import { formatCountdown } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { displayedPurchased, useDropJoinStore } from '@/stores/drop-join';
import { useProductSheetStore } from '@/stores/product-sheet';

type Props = {
  drop: FeedDrop;
  now: number;
  playing?: boolean;
};

export function DropSlide({ drop, now, playing = true }: Props) {
  const openDrop = useProductSheetStore((state) => state.openDrop);
  const joined = useDropJoinStore((state) => Boolean(state.joinedIds[drop.id]));
  const purchased = displayedPurchased(drop.purchased, drop.goal, joined);
  const remaining = formatCountdown(drop.endsAt, now);
  const countdown =
    remaining.days > 0
      ? t('feed.countdownDays', { days: String(remaining.days), clock: remaining.clock })
      : remaining.clock;

  return (
    <MediaCard image={drop.image} style={{ flex: 1 }}>
      <Pressable
        accessibilityLabel={drop.title}
        accessibilityRole="button"
        onPress={() => openDrop(drop.id)}
        style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }}
      />
      <View className="gap-3 px-4 pb-4">
        <View className="flex-row items-center justify-between" style={{ pointerEvents: 'none' }}>
          <LiveBadge playing={playing} />
          <TimerPill label={countdown} playing={playing} />
        </View>
        <GlassSurface radius={22}>
          <View className="gap-3 px-4 py-4">
            <View style={{ pointerEvents: 'none' }}>
              <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{drop.detail}</Text>
              <Text className="mt-1 text-[28px] font-medium leading-8 text-ivory">{drop.title}</Text>
              <View className="mt-3">
                <ProgressBar ratio={purchased / drop.goal} />
              </View>
              <Text className="mt-2 text-[12px] text-muted">
                {t('feed.purchased', { purchased: String(purchased), goal: String(drop.goal) })}
              </Text>
            </View>
            <SlotActionButton action="join" itemTitle={drop.title} slotId={drop.id} />
          </View>
        </GlassSurface>
      </View>
    </MediaCard>
  );
}
