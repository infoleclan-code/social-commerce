import { Image } from 'expo-image';
import { Check } from 'lucide-react-native';
import { useEffect } from 'react';
import { Platform, ScrollView, Text, View, useWindowDimensions } from 'react-native';
import Animated, { SlideInDown, SlideOutDown, useAnimatedStyle, useSharedValue, withDelay, withSpring } from 'react-native-reanimated';

import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { savingsPercent } from '@/features/search/catalog';
import type { MerchantOffer, OfferSource } from '@/features/search/types';
import { playSelection } from '@/lib/feedback';
import { t } from '@/lib/i18n';
import { formatMoney, type DisplayMoney } from '@/lib/money';
import { useCheckoutStore } from '@/stores/checkout';
import { elevation, palette, radius } from '@/theme/tokens';

/**
 * Layout contract. Web receives the literal utility classes (real CSS); native receives the same
 * box through style props, because React Native has no overflow-y or overscroll-behavior.
 */
const DRAWER_CLASS = 'z-50';
const WEB_SCROLL_CLASS = 'max-h-[75vh] overflow-y-auto overscroll-contain pb-24';
const MAX_HEIGHT_RATIO = 0.75;
const BOTTOM_PADDING = 96;
/** Display estimate only; the server owns each merchant's lock period (merchants.typical_lock_days). */
const CASHBACK_ESTIMATE_DAYS = 45;
const DAY_MS = 86_400_000;

const sheetEntering = Platform.OS === 'web' ? SlideInDown.duration(340) : SlideInDown.springify().damping(22).stiffness(190).mass(0.9);
const sheetExiting = SlideOutDown.duration(200);
const CARD_SPRING = { stiffness: 220, damping: 22, mass: 0.8 } as const;

type Props = {
  offers: readonly MerchantOffer[];
  source: OfferSource | null;
  title: string;
  /** Reports the drawer's rendered height so the scanner frame can fit above it. */
  onHeight: (height: number) => void;
};

function isoDateIn(days: number): string {
  return new Date(Date.now() + days * DAY_MS).toISOString().slice(0, 10);
}

function savedPercent(price: DisplayMoney | null, original: DisplayMoney | null): number {
  if (!price || !original || price.currency !== original.currency) {
    return 0;
  }
  return savingsPercent(original.amount_minor, price.amount_minor);
}

export function MatchDrawer({ offers, source, title, onHeight }: Props) {
  const { height } = useWindowDimensions();
  const openPrepared = useCheckoutStore((state) => state.openPrepared);
  const records = useCheckoutStore((state) => state.records);
  const ordered = new Set(records.map((record) => record.slotId));
  const web = Platform.OS === 'web';
  // The original's price is the same reference for every store, so it is shown once, in the header.
  const original = offers.find((offer) => offer.originalPrice !== null)?.originalPrice ?? null;

  function buy(offer: MerchantOffer) {
    if (!offer.price) {
      return;
    }
    playSelection();
    const variants = offer.variants.length > 0 ? offer.variants : ['One size'];
    // The search overlay is an in-tree layer, not a native modal, so the checkout sheet presents
    // directly above it on every platform. The drawer stays put underneath for "back to results".
    openPrepared({
      slotId: offer.id,
      action: 'buy',
      title: offer.title,
      eyebrow: offer.merchantName,
      image: offer.image,
      showPrice: true,
      price: offer.price,
      cashbackBadge: null,
      expectedOn: isoDateIn(CASHBACK_ESTIMATE_DAYS),
      endsAt: new Date(Date.now() + 30 * DAY_MS).toISOString(),
      purchased: 0,
      goal: 1,
      variants,
      variant: variants[0] ?? 'One size',
      merchantId: `merchant-${offer.partner}`,
      merchantUrl: offer.merchantUrl,
    });
  }

  return (
    <Animated.View
      className={DRAWER_CLASS}
      entering={sheetEntering}
      exiting={sheetExiting}
      onLayout={(event) => onHeight(Math.round(event.nativeEvent.layout.height))}
      style={{ position: 'absolute', left: 0, right: 0, bottom: 0, zIndex: 50 }}
    >
      <GlassSurface
        radius={radius.sheet}
        style={{
          borderBottomLeftRadius: 0,
          borderBottomRightRadius: 0,
          borderBottomWidth: 0,
          backgroundColor: 'rgba(10, 10, 10, 0.9)',
          borderColor: 'rgba(229, 193, 88, 0.22)',
          boxShadow: `${elevation.inset}, 0 -18px 48px rgba(0, 0, 0, 0.55)`,
        }}
      >
        <ScrollView
          bounces={false}
          className="z-50 max-h-[75vh] overflow-y-auto pb-24"
          contentContainerStyle={{ paddingHorizontal: 16, paddingTop: 10, paddingBottom: BOTTOM_PADDING, gap: 10 }}
          keyboardShouldPersistTaps="always"
          nestedScrollEnabled
          overScrollMode="never"
          showsVerticalScrollIndicator
          style={{ maxHeight: Math.round(height * MAX_HEIGHT_RATIO) }}
        >
          <View style={{ alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: palette.lineStrong }} />
          <DrawerHeader original={original} source={source} title={title} />
          {offers.map((offer, index) => (
            <OfferCard
              best={index === 0 && offer.price !== null}
              index={index}
              key={offer.id}
              offer={offer}
              onBuy={buy}
              ordered={ordered.has(offer.id)}
            />
          ))}
        </ScrollView>
      </GlassSurface>
    </Animated.View>
  );
}

function DrawerHeader({ title, source, original }: { title: string; source: OfferSource | null; original: DisplayMoney | null }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'flex-start', gap: 12, paddingTop: 6, paddingBottom: 6 }}>
      <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
        <Text style={{ color: palette.gold, fontSize: 11, fontWeight: '700', letterSpacing: 1.5, textTransform: 'uppercase' }}>
          {t('search.storesEyebrow')}
        </Text>
        <Text numberOfLines={1} style={{ color: palette.ivory, fontSize: 18, fontWeight: '600', letterSpacing: -0.3 }}>
          {title}
        </Text>
        <Text numberOfLines={1} style={{ color: palette.faint, fontSize: 12 }}>
          {original ? t('search.storesSortedFrom', { amount: formatMoney(original) }) : t('search.storesSorted')}
        </Text>
      </View>
      {source === 'demo' ? (
        <View
          accessibilityLabel={t('search.demoA11y')}
          style={{
            marginTop: 2,
            borderRadius: radius.pill,
            borderWidth: 1,
            borderColor: palette.lineStrong,
            paddingHorizontal: 9,
            paddingVertical: 4,
          }}
        >
          <Text style={{ color: palette.muted, fontSize: 10, fontWeight: '700', letterSpacing: 1 }}>{t('search.demoPrices')}</Text>
        </View>
      ) : null}
    </View>
  );
}

function OfferCard({
  offer,
  index,
  best,
  ordered,
  onBuy,
}: {
  offer: MerchantOffer;
  index: number;
  best: boolean;
  ordered: boolean;
  onBuy: (offer: MerchantOffer) => void;
}) {
  const saved = savedPercent(offer.price, offer.originalPrice);
  const available = offer.price !== null;
  // Most useful first, so a narrow card truncates the match score rather than the saving.
  const meta = [
    ordered ? t('search.ordered') : null,
    saved > 0 ? t('search.save', { percent: String(saved) }) : null,
    offer.similarityScore > 0 ? t('search.matchBadge', { score: String(offer.similarityScore) }) : null,
  ]
    .filter((part): part is string => part !== null)
    .join(' · ');
  const appear = useSharedValue(0);

  // Staggered rise-in, driven by a shared value rather than a delayed layout animation so it behaves
  // the same on iOS, Android and web.
  useEffect(() => {
    appear.set(withDelay(80 + index * 70, withSpring(1, CARD_SPRING)));
  }, [appear, index]);

  const appearStyle = useAnimatedStyle(() => ({
    opacity: appear.get(),
    transform: [{ translateY: (1 - appear.get()) * 16 }],
  }));

  return (
    <Animated.View style={[{ marginTop: best ? 8 : 0 }, appearStyle]}>
      <View
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          gap: 12,
          paddingVertical: 12,
          paddingLeft: 12,
          paddingRight: 10,
          borderRadius: radius.chip + 2,
          borderWidth: 1,
          borderColor: best ? 'rgba(229, 193, 88, 0.6)' : palette.line,
          backgroundColor: best ? 'rgba(212, 175, 55, 0.08)' : 'rgba(255, 255, 255, 0.04)',
          boxShadow: best ? '0 0 22px rgba(212, 175, 55, 0.22)' : undefined,
          opacity: available ? 1 : 0.62,
        }}
      >
        <Image
          cachePolicy="memory-disk"
          contentFit="cover"
          source={{ uri: offer.image }}
          style={{ width: 56, height: 56, borderRadius: 14, backgroundColor: '#111111' }}
          transition={160}
        />
        <View style={{ flex: 1, minWidth: 0, gap: 1 }}>
          <Text numberOfLines={1} style={{ color: palette.ivory, fontSize: 14, fontWeight: '700' }}>
            {offer.merchantName}
          </Text>
          {offer.price ? (
            <Text
              numberOfLines={1}
              style={{ color: palette.ivory, fontSize: 19, fontWeight: '700', letterSpacing: -0.4, fontVariant: ['tabular-nums'] }}
            >
              {formatMoney(offer.price)}
            </Text>
          ) : (
            <Text style={{ color: palette.muted, fontSize: 14, fontWeight: '600' }}>{t('search.noListing')}</Text>
          )}
          {meta ? (
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
              {ordered ? <Check color={palette.goldSoft} size={12} strokeWidth={3} /> : null}
              <Text
                numberOfLines={1}
                style={{ flexShrink: 1, color: best || ordered ? palette.goldSoft : palette.muted, fontSize: 12, fontWeight: '600' }}
              >
                {meta}
              </Text>
            </View>
          ) : null}
        </View>
        <View style={{ alignSelf: 'center' }}>
          <PressableScale
            accessibilityHint={t('search.instantBuyHint')}
            accessibilityLabel={
              offer.price
                ? t('search.buyA11y', { merchant: offer.merchantName, price: formatMoney(offer.price) })
                : t('search.noListingA11y', { merchant: offer.merchantName })
            }
            accessibilityState={{ disabled: !available }}
            disabled={!available}
            hitSlop={6}
            onPress={() => onBuy(offer)}
            testID={`instant-buy-${offer.partner}`}
          >
            <View
              style={{
                minHeight: 40,
                paddingHorizontal: 13,
                borderRadius: radius.pill,
                alignItems: 'center',
                justifyContent: 'center',
                backgroundColor: available ? palette.ivory : 'rgba(255, 255, 255, 0.08)',
                boxShadow: available && best ? '0 0 18px rgba(255, 255, 255, 0.28)' : undefined,
              }}
            >
              <Text numberOfLines={1} style={{ color: available ? palette.ink : palette.faint, fontSize: 14, fontWeight: '700' }}>
                {available ? t('search.instantBuy') : t('search.unavailable')}
              </Text>
            </View>
          </PressableScale>
        </View>
      </View>
      {best ? (
        <View
          style={{
            position: 'absolute',
            top: -9,
            left: 14,
            borderRadius: radius.pill,
            paddingHorizontal: 8,
            paddingVertical: 2,
            backgroundColor: palette.gold,
            boxShadow: '0 0 12px rgba(212, 175, 55, 0.55)',
          }}
        >
          <Text style={{ color: palette.ink, fontSize: 10, fontWeight: '800', letterSpacing: 0.8, textTransform: 'uppercase' }}>
            {t('search.bestPrice')}
          </Text>
        </View>
      ) : null}
    </Animated.View>
  );
}
