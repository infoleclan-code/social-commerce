import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { Bookmark, Heart, MessageCircle, ScanSearch, Share2 } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { Share, Text, View } from 'react-native';
import Animated, { cancelAnimation, useAnimatedStyle, useSharedValue, withRepeat, withTiming } from 'react-native-reanimated';

import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { TimerPill } from '@/components/ui/timer-pill';
import { ScanOverlay } from '@/features/dupes/scan-overlay';
import { SlotActionButton } from '@/features/feed/slot-action-button';
import type { FeedProduct } from '@/features/feed/fixtures';
import { formatCountdown } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { useDupeScanStore } from '@/stores/dupe-scan';
import { useReelSocialStore } from '@/stores/reel-social';
import { useProductSheetStore } from '@/stores/product-sheet';
import { palette, radius, scrim } from '@/theme/tokens';

const CREATORS: Record<string, { handle: string; likes: number; comments: number }> = {
  'column-coat': { handle: 'nord.atelier', likes: 1284, comments: 86 },
  'silk-slip': { handle: 'maison.rue', likes: 964, comments: 41 },
  loafer: { handle: 'atelier.sole', likes: 742, comments: 28 },
  'hour-watch': { handle: 'field.co', likes: 2104, comments: 133 },
  signet: { handle: 'lumen', likes: 518, comments: 19 },
  frame: { handle: 'optic.studio', likes: 390, comments: 12 },
};

type Props = {
  product: FeedProduct;
  now: number;
  active: boolean;
  bottomInset: number;
  topInset: number;
  onComment: (productId: string) => void;
};

export function ReelPage({ product, now, active, bottomInset, topInset, onComment }: Props) {
  const openProduct = useProductSheetStore((state) => state.openProduct);
  const liked = useReelSocialStore((state) => Boolean(state.likedIds[product.id]));
  const saved = useReelSocialStore((state) => Boolean(state.savedIds[product.id]));
  const commentCount = useReelSocialStore((state) => state.comments[product.id]?.length ?? 0);
  const toggleLike = useReelSocialStore((state) => state.toggleLike);
  const toggleSaved = useReelSocialStore((state) => state.toggleSaved);
  const scanning = useDupeScanStore((state) => state.phase === 'scanning' && state.productId === product.id);
  const startScan = useDupeScanStore((state) => state.startScan);
  const creator = CREATORS[product.id] ?? { handle: 'studio', likes: 0, comments: 0 };
  const remaining = formatCountdown(product.endsAt, now);
  const countdown =
    remaining.days > 0
      ? t('feed.countdownDays', { days: String(remaining.days), clock: remaining.clock })
      : remaining.clock;

  return (
    <View style={{ flex: 1 }}>
      <ReelStage active={active} uris={product.gallery.length > 0 ? product.gallery : [product.image]} />
      {scanning ? <ScanOverlay productId={product.id} /> : null}
      <View style={{ position: 'absolute', top: topInset, left: 16, zIndex: 3 }}>
        <PressableScale accessibilityLabel={t('dupe.scanA11y')} onPress={() => startScan(product.id)}>
          <GlassSurface radius={radius.pill}>
            <View className="h-10 flex-row items-center gap-2 px-3">
              <ScanSearch color={palette.goldSoft} size={16} strokeWidth={1.6} />
              <Text className="text-[13px] font-semibold text-ivory">{t('dupe.scan')}</Text>
            </View>
          </GlassSurface>
        </PressableScale>
      </View>
      <View style={{ position: 'absolute', right: 10, bottom: bottomInset + 28, alignItems: 'center', gap: 18 }}>
        <RailButton
          accessibilityLabel={liked ? t('drops.unlike') : t('drops.like')}
          count={creator.likes + (liked ? 1 : 0)}
          icon={Heart}
          selected={liked}
          onPress={() => toggleLike(product.id)}
        />
        <RailButton
          accessibilityLabel={t('drops.comment')}
          count={creator.comments + commentCount}
          icon={MessageCircle}
          onPress={() => onComment(product.id)}
        />
        <RailButton
          accessibilityLabel={t('drops.share')}
          icon={Share2}
          onPress={() => {
            void Share.share({ message: t('drops.shareMessage', { name: product.name }) }).catch(() => undefined);
          }}
        />
        <RailButton
          accessibilityLabel={saved ? t('drops.saved') : t('drops.save')}
          icon={Bookmark}
          selected={saved}
          onPress={() => toggleSaved(product.id)}
        />
      </View>

      <View style={{ position: 'absolute', left: 14, right: 78, bottom: bottomInset }}>
        <GlassSurface radius={radius.panel}>
          <View className="gap-3 px-4 py-4">
            <View className="flex-row items-center gap-2">
              <View
                style={{
                  width: 32,
                  height: 32,
                  borderRadius: radius.chip,
                  alignItems: 'center',
                  justifyContent: 'center',
                  borderWidth: 1,
                  borderColor: palette.gold,
                  backgroundColor: palette.overlay,
                }}
              >
                <Text style={{ color: palette.goldSoft, fontSize: 13, fontWeight: '600' }}>
                  {creator.handle.slice(0, 1).toUpperCase()}
                </Text>
              </View>
              <Text className="text-[13px] font-medium text-ivory">
                {t('drops.creator', { handle: creator.handle })}
              </Text>
            </View>

            <PressableScale
              accessibilityLabel={t('drops.openProduct', { name: product.name })}
              onPress={() => openProduct(product.id)}
            >
              <View
                style={{
                  alignSelf: 'flex-start',
                  borderRadius: radius.pill,
                  borderWidth: 1,
                  borderColor: palette.line,
                  backgroundColor: palette.overlay,
                  paddingHorizontal: 12,
                  paddingVertical: 6,
                }}
              >
                <Text className="text-[16px] font-medium text-ivory">{product.name}</Text>
              </View>
            </PressableScale>

            {product.showPrice && product.price ? (
              <Text className="text-[15px] font-medium text-ivory">{formatMoney(product.price)}</Text>
            ) : null}
            <TimerPill label={countdown} playing={active} />
            <SlotActionButton
              action={product.action}
              idleBuyLabel={t('drops.instantBuy')}
              itemTitle={product.name}
              slotId={product.slotId}
            />
          </View>
        </GlassSurface>
      </View>
    </View>
  );
}

function ReelStage({ uris, active }: { uris: string[]; active: boolean }) {
  const [frame, setFrame] = useState(0);
  const scale = useSharedValue(1);

  useEffect(() => {
    if (!active) {
      cancelAnimation(scale);
      scale.value = 1;
      return;
    }
    scale.value = withRepeat(withTiming(1.08, { duration: 7200 }), -1, true);
    return () => {
      cancelAnimation(scale);
    };
  }, [active, scale]);

  useEffect(() => {
    if (!active || uris.length < 2) {
      return;
    }
    const id = setInterval(() => {
      setFrame((current) => (current + 1) % uris.length);
    }, 3800);
    return () => {
      clearInterval(id);
    };
  }, [active, uris.length]);

  const motion = useAnimatedStyle(() => ({
    transform: [{ scale: scale.value }],
  }));

  return (
    <View style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, overflow: 'hidden', backgroundColor: palette.ink }}>
      <Animated.View style={[{ position: 'absolute', top: -24, right: -24, bottom: -24, left: -24 }, motion]}>
        <Image
          contentFit="cover"
          source={{ uri: uris[frame] ?? uris[0] }}
          style={{ width: '100%', height: '100%' }}
          transition={active ? 420 : 0}
        />
      </Animated.View>
      <LinearGradient
        colors={scrim.reel}
        locations={[0, 0.42, 1]}
        style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }}
      />
    </View>
  );
}

function RailButton({
  icon: Icon,
  accessibilityLabel,
  onPress,
  count,
  selected = false,
}: {
  icon: typeof Heart;
  accessibilityLabel: string;
  onPress: () => void;
  count?: number;
  selected?: boolean;
}) {
  const color = selected ? palette.goldSoft : palette.ivory;

  return (
    <PressableScale accessibilityLabel={accessibilityLabel} accessibilityState={{ selected }} onPress={onPress}>
      <View style={{ alignItems: 'center', minWidth: 44, minHeight: 44, justifyContent: 'center' }}>
        <Icon color={color} fill={selected ? color : 'transparent'} size={26} strokeWidth={1.6} />
        {typeof count === 'number' ? (
          <Text className="mt-1 text-[11px] text-ivory">{formatCount(count)}</Text>
        ) : null}
      </View>
    </PressableScale>
  );
}

function formatCount(value: number): string {
  return new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}
