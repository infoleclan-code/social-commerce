import { Image } from 'expo-image';
import { X } from 'lucide-react-native';
import { useEffect, useRef, useState } from 'react';
import { FlatList, Modal, Pressable, ScrollView, Text, View, useWindowDimensions } from 'react-native';
import { Gesture, GestureDetector, GestureHandlerRootView } from 'react-native-gesture-handler';
import Animated, {
  runOnJS,
  useAnimatedStyle,
  useSharedValue,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { PressableScale } from '@/components/ui/pressable-scale';
import { ProgressBar } from '@/components/ui/progress-bar';
import { TimerPill } from '@/components/ui/timer-pill';
import {
  feedDrops,
  feedProducts,
  resolveSlot,
  type SlotAction,
} from '@/features/feed/fixtures';
import { SlotActionButton } from '@/features/feed/slot-action-button';
import { useNow } from '@/features/feed/use-now';
import { formatCountdown, formatExpectedDate } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { formatMoney, type DisplayMoney } from '@/lib/money';
import { useCheckoutStore } from '@/stores/checkout';
import { displayedPurchased, useDropJoinStore } from '@/stores/drop-join';
import { useProductSheetStore, type SheetTarget } from '@/stores/product-sheet';
import { palette, radius } from '@/theme/tokens';

type Detail = {
  title: string;
  eyebrow: string;
  images: string[];
  showPrice: boolean;
  price: DisplayMoney | null;
  cashbackBadge: string;
  expectedOn: string;
  slotId: string;
  action: SlotAction;
};

export function ProductSheet() {
  const target = useProductSheetStore((state) => state.target);
  const close = useProductSheetStore((state) => state.close);
  const [shown, setShown] = useState<SheetTarget | null>(null);
  const { height } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const translateY = useSharedValue(height);
  const drag = useSharedValue(0);
  const backdrop = useSharedValue(0);
  const generation = useRef(0);
  const dismissGeneration = useRef(0);
  const closing = useRef(false);
  const sheetHeight = Math.max(520, Math.round(height * 0.88));

  useEffect(() => {
    generation.current += 1;
    closing.current = false;
    if (!target) {
      setShown(null);
      return;
    }
    setShown(target);
    drag.value = 0;
    translateY.value = sheetHeight;
    translateY.value = withSpring(0, { damping: 24, stiffness: 240, mass: 0.75 });
    backdrop.value = withTiming(1, { duration: 240 });
  }, [backdrop, drag, sheetHeight, target, translateY]);

  function finishClose() {
    closing.current = false;
    if (dismissGeneration.current !== generation.current) {
      return;
    }
    setShown(null);
    close();
  }

  function releaseDismiss() {
    closing.current = false;
  }

  function dismiss() {
    if (closing.current) {
      return;
    }
    closing.current = true;
    dismissGeneration.current = generation.current;
    drag.value = 0;
    backdrop.value = withTiming(0, { duration: 200 });
    translateY.value = withTiming(sheetHeight, { duration: 260 }, (finished) => {
      if (finished) {
        runOnJS(finishClose)();
      } else {
        runOnJS(releaseDismiss)();
      }
    });
  }

  const pan = Gesture.Pan()
    .onUpdate((event) => {
      drag.value = Math.max(0, event.translationY);
    })
    .onEnd((event) => {
      if (event.translationY > 110 || event.velocityY > 900) {
        runOnJS(dismiss)();
      } else {
        drag.value = withSpring(0);
      }
    });

  const sheetStyle = useAnimatedStyle(() => ({
    transform: [{ translateY: translateY.value + drag.value }],
  }));
  const backdropStyle = useAnimatedStyle(() => ({
    opacity: backdrop.value * 0.72,
  }));

  const checkoutOpen = useCheckoutStore((state) => state.draft !== null);

  useEffect(() => {
    if (!checkoutOpen || !shown) {
      return;
    }
    setShown(null);
    close();
  }, [checkoutOpen, close, shown]);

  const detail = shown ? resolveDetail(shown) : null;

  return (
    <Modal animationType="none" onRequestClose={dismiss} transparent visible={shown !== null}>
      <GestureHandlerRootView style={{ flex: 1 }}>
        <View style={{ flex: 1, justifyContent: 'flex-end' }}>
          <Animated.View
            style={[
              { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: palette.ink },
              backdropStyle,
            ]}
          />
          <Pressable
            accessibilityLabel={t('feed.closeA11y')}
            onPress={dismiss}
            style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }}
          />
          <Animated.View
            style={[
              {
                height: sheetHeight,
                borderTopLeftRadius: radius.sheet,
                borderTopRightRadius: radius.sheet,
                overflow: 'hidden',
                borderWidth: 1,
                borderColor: palette.line,
                backgroundColor: palette.ink,
              },
              sheetStyle,
            ]}
          >
            {detail ? (
              <SheetBody
                detail={detail}
                onClose={dismiss}
                pan={pan}
                paddingBottom={Math.max(insets.bottom, 16)}
              />
            ) : null}
          </Animated.View>
        </View>
      </GestureHandlerRootView>
    </Modal>
  );
}

function SheetBody({
  detail,
  onClose,
  pan,
  paddingBottom,
}: {
  detail: Detail;
  onClose: () => void;
  pan: ReturnType<typeof Gesture.Pan>;
  paddingBottom: number;
}) {
  const now = useNow();
  const joined = useDropJoinStore((state) => Boolean(state.joinedIds[detail.slotId]));
  const slot = resolveSlot(detail.slotId);
  const purchased = displayedPurchased(slot.purchased, slot.goal, joined);
  const remaining = formatCountdown(slot.endsAt, now);
  const countdown =
    remaining.days > 0
      ? t('feed.countdownDays', { days: String(remaining.days), clock: remaining.clock })
      : remaining.clock;
  const pending = t('cashback.pending', { date: formatExpectedDate(detail.expectedOn) });
  const frame = 280;

  return (
    <ScrollView
      className="flex-1 px-5 pt-3"
      contentContainerStyle={{ paddingBottom }}
      showsVerticalScrollIndicator={false}
    >
      <GestureDetector gesture={pan}>
        <View className="items-center py-2">
          <View style={{ width: 42, height: 4, borderRadius: radius.pill, backgroundColor: palette.line }} />
        </View>
      </GestureDetector>

      <View className="flex-row items-center justify-between">
        <TimerPill label={countdown} />
        <PressableScale accessibilityLabel={t('feed.closeA11y')} onPress={onClose}>
          <View
            className="h-10 w-10 items-center justify-center rounded-full"
            style={{ backgroundColor: palette.raised, borderWidth: 1, borderColor: palette.line }}
          >
            <X color={palette.ivory} size={16} strokeWidth={1.6} />
          </View>
        </PressableScale>
      </View>

      <View accessibilityLabel={t('feed.galleryA11y')} style={{ marginTop: 16 }}>
        <FlatList
          data={detail.images}
          decelerationRate="fast"
          horizontal
          keyExtractor={(uri, index) => `${index}-${uri}`}
          showsHorizontalScrollIndicator={false}
          snapToInterval={frame + 12}
          style={{ flexGrow: 0 }}
          renderItem={({ item }) => (
            <Image
              contentFit="cover"
              source={{ uri: item }}
              style={{
                width: frame,
                height: 210,
                borderRadius: radius.media,
                marginRight: 12,
                backgroundColor: palette.ink,
              }}
            />
          )}
        />
      </View>

      <Text className="mt-5 text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{detail.eyebrow}</Text>
      <Text className="mt-1 text-[32px] font-medium text-ivory">{detail.title}</Text>
      {detail.showPrice && detail.price ? (
        <Text className="mt-2 text-[16px] font-medium text-ivory">{formatMoney(detail.price)}</Text>
      ) : null}

      <View className="mt-4">
        <ProgressBar ratio={purchased / slot.goal} />
        <Text className="mt-2 text-[12px] text-muted">
          {t('feed.purchased', { purchased: String(purchased), goal: String(slot.goal) })}
        </Text>
      </View>

      <View className="mt-5">
        <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">
          {t('feed.cashbackEyebrow')}
        </Text>
        <Text className="mt-2 text-[14px] leading-5 text-muted">{pending}</Text>
        <View
          accessibilityLabel={pending}
          style={{
            alignSelf: 'flex-start',
            marginTop: 10,
            borderRadius: radius.pill,
            borderWidth: 1,
            borderColor: palette.gold,
            backgroundColor: palette.overlay,
            paddingHorizontal: 8,
            paddingVertical: 4,
          }}
        >
          <Text style={{ color: palette.goldSoft, fontSize: 11, fontWeight: '600', letterSpacing: 0.6 }}>
            {detail.cashbackBadge}
          </Text>
        </View>
      </View>

      <View className="mt-6 gap-3">
        <SlotActionButton action={detail.action} itemTitle={detail.title} slotId={detail.slotId} />
        <Text className="text-center text-[12px] leading-5 text-faint">
          {detail.action === 'join' ? t('feed.slotNote') : t('feed.buyNote')}
        </Text>
      </View>
    </ScrollView>
  );
}

function resolveDetail(target: SheetTarget): Detail | null {
  if (target.kind === 'drop') {
    const drop = feedDrops.find((item) => item.id === target.id);
    if (!drop) {
      return null;
    }
    return {
      title: drop.title,
      eyebrow: drop.detail,
      images: [drop.image],
      showPrice: false,
      price: null,
      cashbackBadge: t('feed.cashbackEyebrow'),
      expectedOn: '2026-11-03',
      slotId: drop.id,
      action: 'join',
    };
  }

  const product = feedProducts.find((item) => item.id === target.id);
  if (!product) {
    return null;
  }
  return {
    title: product.name,
    eyebrow: product.brand,
    images: product.gallery,
    showPrice: product.showPrice,
    price: product.price,
    cashbackBadge: product.cashbackBadge,
    expectedOn: product.expectedOn,
    slotId: product.slotId,
    action: product.action,
  };
}
