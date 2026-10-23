import { useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { Check, ExternalLink, ShieldCheck, Zap } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View, type StyleProp, type ViewStyle } from 'react-native';
import Animated, {
  cancelAnimation,
  Easing,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withDelay,
  withRepeat,
  withSequence,
  withTiming,
} from 'react-native-reanimated';

import type { PaymentMethodSummary, ProductCard, ProductVariant } from '@/api/contracts';
import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { startOneTap } from '@/features/one-tap/one-tap-engine';
import { useOneTapStore, type Purchase } from '@/features/one-tap/one-tap-store';
import { useDefaultPaymentMethod } from '@/features/one-tap/use-payment-method';
import { playError, playLock, playSelection } from '@/lib/feedback';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette, radius } from '@/theme/tokens';

type Props = {
  product: ProductCard;
  /** Denser layout for result lists (the scanner). */
  compact?: boolean;
  style?: StyleProp<ViewStyle>;
  /** The landed total on screen. Sent so a price move is confirmed instead of charged. */
  expectedTotalMinor?: number | null;
};

type ButtonIcon = 'zap' | 'spinner' | 'check' | 'shield' | 'external' | null;

type ButtonState = {
  label: string;
  icon: ButtonIcon;
  disabled: boolean;
  /** The animated gold halo: only for a live 1-Tap button. */
  glow: boolean;
  tone: 'gold' | 'outline' | 'muted';
};

const asOfFormat = new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric' });

function buttonState(product: ProductCard, purchase: Purchase | undefined): ButtonState {
  if (product.purchase.mode === 'unavailable') {
    return { label: t('oneTap.soldOut'), icon: null, disabled: true, glow: false, tone: 'muted' };
  }
  if (product.purchase.mode === 'redirect') {
    const opening = purchase?.phase === 'submitting';
    return {
      label: t('oneTap.shopAt', { merchant: product.merchant.name }),
      icon: opening ? 'spinner' : 'external',
      disabled: opening,
      glow: false,
      tone: 'outline',
    };
  }
  switch (purchase?.phase) {
    case 'submitting':
      return { label: t('oneTap.state.submitting'), icon: 'spinner', disabled: true, glow: true, tone: 'gold' };
    case 'saving_card':
      return { label: t('oneTap.state.savingCard'), icon: 'spinner', disabled: true, glow: true, tone: 'gold' };
    case 'authenticating':
      return { label: t('oneTap.state.authenticating'), icon: 'shield', disabled: true, glow: true, tone: 'gold' };
    case 'confirming':
    case 'cancelling':
      return { label: t('oneTap.state.confirming'), icon: 'spinner', disabled: true, glow: true, tone: 'gold' };
    case 'authorized':
      return { label: t('oneTap.state.authorized'), icon: 'check', disabled: true, glow: false, tone: 'gold' };
    case 'completed':
      // One order per product per session from this button; repeat purchases go through Closet.
      return { label: t('oneTap.state.completed'), icon: 'check', disabled: true, glow: false, tone: 'gold' };
    default:
      return { label: t('oneTap.buy'), icon: 'zap', disabled: false, glow: true, tone: 'gold' };
  }
}

function methodLabel(summary: PaymentMethodSummary | null | undefined): string | null {
  if (!summary) {
    return null;
  }
  if (summary.wallet === 'apple_pay') {
    return t('oneTap.applePay');
  }
  if (summary.wallet === 'google_pay') {
    return t('oneTap.googlePay');
  }
  const brand = summary.brand ? summary.brand.charAt(0).toUpperCase() + summary.brand.slice(1) : '';
  return t('oneTap.card', { brand, last4: summary.last4 });
}

/**
 * The persistent "1-Tap Buy" card under a reel (and in scanner results). One press buys with the saved
 * card; the server enforces stock, limits and idempotency, and the banner (OneTapBanner) offers the undo.
 * Affiliate products show "Shop at {merchant}" and open a tracked link instead.
 */
export function OneTapBuyCard({ product, compact = false, style, expectedTotalMinor = null }: Props) {
  const queryClient = useQueryClient();
  const reduceMotion = useReducedMotion();
  const purchase = useOneTapStore((state) => state.purchases[product.id]);
  const isOneTap = product.purchase.mode === 'one_tap';
  const method = useDefaultPaymentMethod(isOneTap);
  const inStockVariants = product.variants.filter((variant) => variant.in_stock);
  const [variantSku, setVariantSku] = useState<string | null>(() =>
    inStockVariants.length === 1 && product.variants.length === 1 ? (inStockVariants[0]?.sku ?? null) : null,
  );
  const [buttonWidth, setButtonWidth] = useState(0);
  const state = buttonState(product, purchase);
  const glowing = state.glow && !reduceMotion;

  const pulse = useSharedValue(0);
  const sheen = useSharedValue(0);
  const shake = useSharedValue(0);

  useEffect(() => {
    if (!glowing) {
      cancelAnimation(pulse);
      cancelAnimation(sheen);
      pulse.set(0);
      sheen.set(0);
      return;
    }
    pulse.set(withRepeat(withTiming(1, { duration: 1_400, easing: Easing.inOut(Easing.sin) }), -1, true));
    sheen.set(
      withRepeat(
        withSequence(withTiming(0, { duration: 0 }), withDelay(1_600, withTiming(1, { duration: 900, easing: Easing.out(Easing.quad) }))),
        -1,
        false,
      ),
    );
    return () => {
      cancelAnimation(pulse);
      cancelAnimation(sheen);
    };
  }, [glowing, pulse, sheen]);

  const haloStyle = useAnimatedStyle(() => ({
    opacity: 0.3 + pulse.get() * 0.6,
    transform: [{ scale: 1 + pulse.get() * 0.06 }],
  }));
  const sheenStyle = useAnimatedStyle(() => ({
    transform: [{ translateX: -72 + sheen.get() * (buttonWidth + 144) }, { skewX: '-18deg' }],
  }));
  const shakeStyle = useAnimatedStyle(() => ({ transform: [{ translateX: shake.get() }] }));

  const selected = product.variants.find((variant) => variant.sku === variantSku && variant.in_stock) ?? null;
  const needsVariant = product.variants.length > 0 && selected === null;
  const price = product.price ? formatMoney(product.price) : null;
  const compareAt =
    product.compare_at_price && product.price && product.compare_at_price.amount_minor > product.price.amount_minor
      ? formatMoney(product.compare_at_price)
      : null;
  const payWith = isOneTap ? methodLabel(method.data?.summary) : null;
  const asOf = product.price_as_of ? asOfFormat.format(new Date(product.price_as_of)) : null;

  function onBuy() {
    if (state.disabled) {
      return;
    }
    if (isOneTap && needsVariant) {
      shake.set(
        withSequence(
          withTiming(-7, { duration: 45 }),
          withTiming(7, { duration: 45 }),
          withTiming(-5, { duration: 45 }),
          withTiming(5, { duration: 45 }),
          withTiming(0, { duration: 45 }),
        ),
      );
      playError();
      return;
    }
    playLock();
    startOneTap(queryClient, product, isOneTap ? (selected?.sku ?? null) : null, 1, expectedTotalMinor);
  }

  const imageSize = compact ? 56 : 52;
  const a11yLabel =
    product.purchase.mode === 'redirect'
      ? t('oneTap.shopAtA11y', { merchant: product.merchant.name, title: product.title })
      : isOneTap
        ? t('oneTap.buyA11y', { title: product.title, price: price ?? '' })
        : state.label;

  return (
    <GlassSurface
      radius={radius.panel}
      style={[
        {
          backgroundColor: 'rgba(10, 10, 10, 0.72)',
          borderColor: isOneTap && state.glow ? palette.goldRing : palette.line,
        },
        style,
      ]}
    >
      <View style={{ padding: compact ? 10 : 12, gap: 10 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
          <View
            style={{
              width: imageSize,
              height: imageSize,
              borderRadius: 14,
              overflow: 'hidden',
              backgroundColor: palette.overlay,
              borderWidth: 1,
              borderColor: palette.line,
            }}
          >
            {product.image_url ? (
              <Image
                accessibilityIgnoresInvertColors
                cachePolicy="memory-disk"
                contentFit="cover"
                recyclingKey={product.id}
                source={{ uri: product.image_url }}
                style={{ width: '100%', height: '100%' }}
                transition={120}
              />
            ) : null}
          </View>

          <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
            <Text numberOfLines={compact ? 2 : 1} style={{ color: palette.ivory, fontSize: 14, fontWeight: '600', letterSpacing: -0.1 }}>
              {product.title}
            </Text>
            <View style={{ flexDirection: 'row', alignItems: 'baseline', gap: 6, flexWrap: 'wrap' }}>
              {price ? <Text style={{ color: palette.goldSoft, fontSize: 15, fontWeight: '700' }}>{price}</Text> : null}
              {compareAt ? (
                <Text
                  accessibilityLabel={t('oneTap.compareAt', { price: compareAt })}
                  style={{ color: palette.faint, fontSize: 12, textDecorationLine: 'line-through' }}
                >
                  {compareAt}
                </Text>
              ) : null}
              <Text numberOfLines={1} style={{ color: palette.muted, fontSize: 12, flexShrink: 1 }}>
                {product.merchant.name}
              </Text>
            </View>
            {asOf ? <Text style={{ color: palette.faint, fontSize: 11 }}>{t('oneTap.priceAsOf', { date: asOf })}</Text> : null}
            {payWith ? <Text style={{ color: palette.faint, fontSize: 11 }}>{t('oneTap.payWith', { method: payWith })}</Text> : null}
          </View>

          <View style={{ justifyContent: 'center' }}>
            {state.glow ? (
              <Animated.View
                style={[
                  {
                    position: 'absolute',
                    top: 0,
                    right: 0,
                    bottom: 0,
                    left: 0,
                    pointerEvents: 'none',
                    borderRadius: radius.pill,
                    boxShadow: '0 0 22px rgba(212, 175, 55, 0.85)',
                    backgroundColor: palette.goldWash,
                  },
                  haloStyle,
                ]}
              />
            ) : null}
            <PressableScale
              accessibilityHint={isOneTap ? t('oneTap.buyHint') : undefined}
              accessibilityLabel={a11yLabel}
              accessibilityState={{ disabled: state.disabled, busy: state.icon === 'spinner' }}
              disabled={state.disabled}
              onPress={onBuy}
            >
              <View
                onLayout={(event) => setButtonWidth(Math.round(event.nativeEvent.layout.width))}
                style={{
                  minHeight: 44,
                  minWidth: compact ? 112 : 124,
                  paddingHorizontal: 16,
                  borderRadius: radius.pill,
                  overflow: 'hidden',
                  flexDirection: 'row',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: 6,
                  backgroundColor: state.tone === 'gold' ? palette.gold : state.tone === 'outline' ? 'rgba(255, 255, 255, 0.06)' : palette.overlay,
                  borderWidth: state.tone === 'outline' ? 1 : 0,
                  borderColor: palette.lineStrong,
                  opacity: state.tone === 'muted' ? 0.6 : 1,
                }}
              >
                {glowing ? (
                  <Animated.View style={[{ position: 'absolute', top: -8, bottom: -8, left: 0, width: 56, pointerEvents: 'none' }, sheenStyle]}>
                    <LinearGradient
                      colors={['rgba(255, 255, 255, 0)', 'rgba(255, 255, 255, 0.55)', 'rgba(255, 255, 255, 0)']}
                      end={{ x: 1, y: 0.5 }}
                      start={{ x: 0, y: 0.5 }}
                      style={{ flex: 1 }}
                    />
                  </Animated.View>
                ) : null}
                <ButtonGlyph icon={state.icon} tone={state.tone} />
                <Text
                  numberOfLines={1}
                  style={{
                    color: state.tone === 'gold' ? palette.ink : palette.ivory,
                    fontSize: 14,
                    fontWeight: '700',
                    letterSpacing: 0.1,
                  }}
                >
                  {state.label}
                </Text>
              </View>
            </PressableScale>
          </View>
        </View>

        {isOneTap && product.variants.length > 0 && !state.disabled ? (
          <Animated.View style={shakeStyle}>
            {needsVariant ? (
              <Text style={{ color: palette.muted, fontSize: 11, marginBottom: 6 }}>{t('oneTap.chooseSize')}</Text>
            ) : null}
            <ScrollView contentContainerStyle={{ gap: 6 }} horizontal keyboardShouldPersistTaps="handled" showsHorizontalScrollIndicator={false}>
              {product.variants.map((variant) => (
                <VariantChip
                  key={variant.sku}
                  onPress={() => {
                    playSelection();
                    setVariantSku(variant.sku);
                  }}
                  selected={variant.sku === variantSku}
                  variant={variant}
                />
              ))}
            </ScrollView>
          </Animated.View>
        ) : null}
      </View>
    </GlassSurface>
  );
}

function ButtonGlyph({ icon, tone }: { icon: ButtonIcon; tone: ButtonState['tone'] }) {
  const color = tone === 'gold' ? palette.ink : palette.ivory;
  switch (icon) {
    case 'spinner':
      return <ActivityIndicator color={color} size="small" />;
    case 'zap':
      return <Zap color={color} fill={color} size={15} strokeWidth={2} />;
    case 'check':
      return <Check color={color} size={16} strokeWidth={2.5} />;
    case 'shield':
      return <ShieldCheck color={color} size={16} strokeWidth={2} />;
    case 'external':
      return <ExternalLink color={color} size={15} strokeWidth={2} />;
    default:
      return null;
  }
}

function VariantChip({ variant, selected, onPress }: { variant: ProductVariant; selected: boolean; onPress: () => void }) {
  return (
    <Pressable
      accessibilityLabel={
        variant.in_stock ? t('oneTap.sizeA11y', { label: variant.label }) : t('oneTap.sizeSoldOutA11y', { label: variant.label })
      }
      accessibilityRole="button"
      accessibilityState={{ selected, disabled: !variant.in_stock }}
      disabled={!variant.in_stock}
      hitSlop={4}
      onPress={onPress}
      style={{
        minWidth: 44,
        minHeight: 32,
        paddingHorizontal: 12,
        borderRadius: radius.pill,
        alignItems: 'center',
        justifyContent: 'center',
        borderWidth: 1,
        borderColor: selected ? palette.gold : palette.line,
        backgroundColor: selected ? palette.goldWash : 'rgba(255, 255, 255, 0.04)',
        opacity: variant.in_stock ? 1 : 0.4,
      }}
    >
      <Text
        style={{
          color: selected ? palette.goldSoft : palette.ivory,
          fontSize: 13,
          fontWeight: '600',
          textDecorationLine: variant.in_stock ? 'none' : 'line-through',
        }}
      >
        {variant.label}
      </Text>
    </Pressable>
  );
}
