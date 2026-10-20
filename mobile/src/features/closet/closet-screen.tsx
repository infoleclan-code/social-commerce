import { useIsFocused } from 'expo-router';
import { Heart, Shirt } from 'lucide-react-native';
import { Text, View } from 'react-native';

import { DisplayText } from '@/components/ui/display-text';
import { EmptyHero } from '@/components/ui/empty-hero';
import { Eyebrow } from '@/components/ui/eyebrow';
import { ProgressBar } from '@/components/ui/progress-bar';
import { Reveal } from '@/components/ui/reveal';
import { Screen } from '@/components/ui/screen';
import { Surface } from '@/components/ui/surface';
import { TimerPill } from '@/components/ui/timer-pill';
import { feedProducts } from '@/features/feed/fixtures';
import { useNow } from '@/features/feed/use-now';
import { formatCountdown } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { useCheckoutStore } from '@/stores/checkout';
import { useClosetStore } from '@/stores/closet';
import { displayedPurchased } from '@/stores/drop-join';
import { useReelSocialStore } from '@/stores/reel-social';
import { palette } from '@/theme/tokens';
import { PressableScale } from '@/components/ui/pressable-scale';

export function ClosetScreen() {
  const focused = useIsFocused();
  const now = useNow(focused);
  const savedIds = useReelSocialStore((state) => state.savedIds);
  const likedIds = useReelSocialStore((state) => state.likedIds);
  const records = useCheckoutStore((state) => state.records);
  const owned = useClosetStore((state) => state.items);
  const saved = feedProducts.filter((product) => savedIds[product.id]);
  const liked = feedProducts.filter((product) => likedIds[product.id]);
  const empty = saved.length === 0 && liked.length === 0 && records.length === 0 && owned.length === 0;
  const track = useClosetStore((state) => state.track);

  return (
    <Screen>
      <Reveal>
        <Eyebrow>{t('closet.eyebrow')}</Eyebrow>
        <DisplayText className="mt-3 text-[40px] leading-[46px] text-ivory">{t('closet.title')}</DisplayText>
      </Reveal>

      {empty ? (
        <Reveal delay={80}>
          <EmptyHero body={t('closet.body')} eyebrow={t('closet.eyebrow')} icon={Shirt} title={t('closet.empty')} />
        </Reveal>
      ) : null}

      {owned.length > 0 ? (
        <Reveal delay={60}>
          <Surface>
            <Eyebrow>{t('closet.inTransit')}</Eyebrow>
            {owned.map((item) => (
              <PressableScale
                accessibilityLabel={t('closet.trackTitle')}
                block
                className="mt-4 border-t border-line pt-4"
                key={item.id}
                onPress={() => track(item.id)}
              >
                <Text className="text-[18px] font-medium text-ivory">{item.title}</Text>
                <Text className="mt-1 text-[12px] text-gold-soft">
                  {item.variant} · {t('closet.inTransit')}
                </Text>
              </PressableScale>
            ))}
          </Surface>
        </Reveal>
      ) : null}

      {records.length > 0 ? (
        <Reveal delay={80}>
          <Surface>
            <Eyebrow>{t('closet.active')}</Eyebrow>
            <Text className="mt-3 text-[14px] leading-5 text-muted">{t('closet.activeNote')}</Text>
            {records.map((record) => {
              const purchased = displayedPurchased(record.purchased, record.goal, true);
              const remaining = formatCountdown(record.endsAt, now);
              const countdown =
                remaining.days > 0
                  ? t('feed.countdownDays', { days: String(remaining.days), clock: remaining.clock })
                  : remaining.clock;
              return (
                <View className="mt-5 border-t border-line pt-4" key={record.id}>
                  <Text className="text-[18px] font-medium text-ivory">{record.title}</Text>
                  <Text className="mt-1 text-[12px] text-muted">{record.variant}</Text>
                  <View className="mt-3">
                    <ProgressBar ratio={purchased / record.goal} />
                  </View>
                  <Text className="mt-2 text-[12px] text-muted">
                    {t('feed.purchased', { purchased: String(purchased), goal: String(record.goal) })}
                  </Text>
                  <View className="mt-3">
                    <TimerPill label={countdown} />
                  </View>
                </View>
              );
            })}
          </Surface>
        </Reveal>
      ) : null}

      {saved.length > 0 ? (
        <Reveal delay={120}>
          <Surface>
            <Eyebrow>{t('closet.saved')}</Eyebrow>
            <Text className="mt-3 text-[14px] leading-5 text-muted">{t('closet.savedNote')}</Text>
            {saved.map((product) => (
              <Text className="mt-4 text-[16px] font-medium text-ivory" key={product.id}>
                {product.name}
              </Text>
            ))}
          </Surface>
        </Reveal>
      ) : null}

      {liked.length > 0 ? (
        <Reveal delay={160}>
          <Surface>
            <View className="flex-row items-center gap-2">
              <Heart color={palette.goldSoft} size={14} strokeWidth={1.6} />
              <Eyebrow>{t('closet.liked')}</Eyebrow>
            </View>
            {liked.map((product) => (
              <Text className="mt-4 text-[16px] font-medium text-ivory" key={product.id}>
                {product.name}
              </Text>
            ))}
          </Surface>
        </Reveal>
      ) : null}
    </Screen>
  );
}
