import { Image } from 'expo-image';
import { Wallet } from 'lucide-react-native';
import { useEffect, useRef, useState } from 'react';
import { Modal, Platform, Pressable, ScrollView, Text, View, useWindowDimensions } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import Animated, {
  interpolateColor,
  runOnJS,
  useAnimatedStyle,
  useSharedValue,
  withDelay,
  withRepeat,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Circle } from 'react-native-svg';

import { PressableScale } from '@/components/ui/pressable-scale';
import { SuccessBurst } from '@/components/ui/success-burst';
import { DROP_SLIDER_HEIGHT, DropConfirmSlider } from '@/features/drops/drop-confirm-slider';
import { formatExpectedDate } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { formatMoney, type DisplayMoney } from '@/lib/money';
import { useCheckoutStore, type PayMethod } from '@/stores/checkout';
import { useWalletStore } from '@/stores/wallet';
import { palette, radius, elevation, motion } from '@/theme/tokens';

const METHODS: PayMethod[] = ['wallet', 'apple_pay', 'card', 'paypal', 'klarna'];
const ACTION_HEIGHT = DROP_SLIDER_HEIGHT;

export function CheckoutSheet() {
  const draft = useCheckoutStore((state) => state.draft);
  const close = useCheckoutStore((state) => state.close);
  const setVariant = useCheckoutStore((state) => state.setVariant);
  const setMethod = useCheckoutStore((state) => state.setMethod);
  const confirm = useCheckoutStore((state) => state.confirm);
  const completeTopUp = useCheckoutStore((state) => state.completeTopUp);
  const processing = useCheckoutStore((state) => state.processing);
  const shortfall = useCheckoutStore((state) => state.shortfall);
  const celebrating = useCheckoutStore((state) => state.celebrating);
  const finishCelebration = useCheckoutStore((state) => state.finishCelebration);
  const walletBalance = useWalletStore((state) => state.available);
  const [visible, setVisible] = useState(false);
  const [paySheet, setPaySheet] = useState<'purchase' | 'topup' | null>(null);
  const { height } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const translateY = useSharedValue(height);
  const generation = useRef(0);
  const dismissGeneration = useRef(0);
  const closing = useRef(false);
  const sheetHeight = Math.max(560, Math.round(height * 0.9));
  const open = draft !== null;

  useEffect(() => {
    generation.current += 1;
    closing.current = false;
    if (!open) {
      setVisible(false);
      return;
    }
    setVisible(true);
    setPaySheet(null);
    translateY.value = sheetHeight;
    translateY.value = withSpring(0, { damping: 24, stiffness: 240, mass: 0.75 });
  }, [open, sheetHeight, translateY]);

  useEffect(() => {
    if (!celebrating) {
      return;
    }
    const id = setTimeout(() => {
      if (useCheckoutStore.getState().celebrating) {
        finishCelebration();
      }
    }, 900);
    return () => clearTimeout(id);
  }, [celebrating, finishCelebration]);

  function finishClose() {
    closing.current = false;
    if (dismissGeneration.current !== generation.current) {
      return;
    }
    setVisible(false);
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
    translateY.value = withTiming(sheetHeight, { duration: 240 }, (finished) => {
      if (finished) {
        runOnJS(finishClose)();
      } else {
        runOnJS(releaseDismiss)();
      }
    });
  }

  function requestConfirm() {
    if (closing.current || processing) {
      return;
    }
    confirm();
  }

  function openPaySheet(kind: 'purchase' | 'topup') {
    if (closing.current || processing || paySheet) {
      return;
    }
    setPaySheet(kind);
  }

  function settlePaySheet() {
    const kind = paySheet;
    setPaySheet(null);
    if (kind === 'topup') {
      completeTopUp('apple_pay');
      return;
    }
    requestConfirm();
  }

  const sheetStyle = useAnimatedStyle(() => ({
    transform: [{ translateY: translateY.value }],
  }));

  const pending = draft ? t('cashback.pending', { date: formatExpectedDate(draft.expectedOn) }) : '';
  const swipeLabel = draft?.action === 'join' ? t('checkout.swipeDrop') : t('checkout.swipePay');
  const expressPay = draft?.method === 'apple_pay';

  return (
    <Modal animationType="none" onRequestClose={dismiss} transparent visible={visible && draft !== null}>
      <GestureHandlerRootView style={{ flex: 1 }}>
        <View style={{ flex: 1, justifyContent: 'flex-end' }}>
          <Pressable
            accessibilityLabel={t('feed.closeA11y')}
            onPress={dismiss}
            style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: palette.scrim }}
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
            {draft ? (
              <View style={{ flex: 1 }}>
              <ScrollView
                className="px-5 pt-4"
                style={{ flex: 1 }}
                contentContainerStyle={{ paddingBottom: 24 }}
                showsVerticalScrollIndicator={false}
              >
                <View className="mb-4 h-1 w-10 self-center rounded-full" style={{ backgroundColor: palette.line }} />
                <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{t('checkout.item')}</Text>
                <View className="mt-3 flex-row items-center gap-3">
                  <Image
                    contentFit="cover"
                    source={{ uri: draft.image }}
                    style={{ width: 72, height: 88, borderRadius: radius.chip, backgroundColor: palette.ink }}
                  />
                  <View className="flex-1">
                    <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-muted">{draft.eyebrow}</Text>
                    <Text className="mt-1 text-[22px] font-medium text-ivory">{draft.title}</Text>
                    {draft.showPrice && draft.price ? (
                      <Text className="mt-1 text-[15px] font-medium text-ivory">{formatMoney(draft.price)}</Text>
                    ) : null}
                  </View>
                </View>

                <Text className="mt-6 text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{t('checkout.size')}</Text>
                <View className="mt-3 flex-row flex-wrap gap-2">
                  {draft.variants.map((variant) => {
                    const selected = variant === draft.variant;
                    return (
                      <PressableScale
                        accessibilityState={{ selected }}
                        key={variant}
                        onPress={() => setVariant(variant)}
                      >
                        <View
                          style={{
                            borderRadius: radius.pill,
                            borderWidth: 1,
                            borderColor: selected ? palette.gold : palette.line,
                            backgroundColor: selected ? palette.goldWash : palette.overlay,
                            paddingHorizontal: 14,
                            paddingVertical: 8,
                          }}
                        >
                          <Text style={{ color: selected ? palette.goldSoft : palette.ivory, fontSize: 14, fontWeight: '600' }}>
                            {variant}
                          </Text>
                        </View>
                      </PressableScale>
                    );
                  })}
                </View>

                <Text className="mt-6 text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">
                  {t('checkout.cashback')}
                </Text>
                <Text className="mt-2 text-[14px] leading-5 text-ivory">{pending}</Text>
                {draft.cashbackBadge ? (
                  <Text className="mt-2 text-[13px] text-gold-soft">{draft.cashbackBadge}</Text>
                ) : null}
                <Text className="mt-2 text-[13px] leading-5 text-faint">{t('checkout.fullPrice')}</Text>

                <Text className="mt-6 text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{t('checkout.method')}</Text>
                <View className="mt-3 gap-2">
                  {METHODS.map((method) => (
                    <PaymentOption
                      amountLabel={method === 'klarna' && draft.price ? formatMoney(splitInFour(draft.price)) : null}
                      balance={method === 'wallet' ? formatMoney(walletBalance) : null}
                      disabled={processing}
                      key={method}
                      method={method}
                      onPress={() => {
                        if (!processing) {
                          setMethod(method);
                        }
                      }}
                      selected={method === draft.method}
                    />
                  ))}
                </View>
                <Text className="mt-3 text-[12px] leading-5 text-faint" numberOfLines={2} style={{ minHeight: 36 }}>
                  {methodNote(draft.method)}
                </Text>
                {shortfall ? (
                  <Text className="mt-3 text-[15px] leading-6 text-ivory">
                    {t('checkout.topUpTitle')}. {t('checkout.topUpBody', { short: formatMoney(shortfall) })}
                  </Text>
                ) : null}
              </ScrollView>
              <View
                style={{
                  height: ACTION_HEIGHT + 44 + Math.max(insets.bottom, 16),
                  paddingHorizontal: 20,
                  paddingTop: 12,
                  paddingBottom: Math.max(insets.bottom, 16),
                  backgroundColor: palette.ink,
                  borderTopWidth: 1,
                  borderTopColor: palette.line,
                }}
              >
                <View style={{ height: ACTION_HEIGHT }}>
                  {shortfall ? (
                    <ExpressPayButton disabled={processing} label={expressTopUpLabel()} onPress={() => openPaySheet('topup')} />
                  ) : expressPay ? (
                    <ExpressPayButton disabled={processing} label={expressPayLabel()} onPress={() => openPaySheet('purchase')} />
                  ) : (
                    <DropConfirmSlider
                      confirmedLabel={draft.action === 'join' ? t('checkout.dropConfirmed') : t('checkout.paymentConfirmed')}
                      disabled={processing}
                      key={`${draft.idempotencyKey}-${draft.method}`}
                      label={swipeLabel}
                      onConfirm={requestConfirm}
                      testID="checkout-swipe"
                    />
                  )}
                </View>
                <View style={{ height: 24, marginTop: 8, alignItems: 'center', justifyContent: 'center' }}>
                  {shortfall ? (
                    <PressableScale accessibilityLabel={t('checkout.topUpCard')} onPress={() => { if (!processing) completeTopUp('card'); }}>
                      <Text className="text-[12px] font-semibold text-ivory">{t('checkout.topUpCard')}</Text>
                    </PressableScale>
                  ) : (
                    <Text className="text-center text-[12px] leading-5 text-faint" numberOfLines={1}>
                      {draft.action === 'join' ? t('feed.slotNote') : t('feed.buyNote')}
                    </Text>
                  )}
                </View>
              </View>
              </View>
            ) : null}
            {celebrating ? (
              <View
                style={{
                  position: 'absolute',
                  top: 0,
                  right: 0,
                  bottom: 0,
                  left: 0,
                  alignItems: 'center',
                  justifyContent: 'center',
                  backgroundColor: palette.scrim,
                  pointerEvents: 'none',
                }}
              >
                <SuccessBurst />
              </View>
            ) : null}
            {paySheet && draft ? (
              <ApplePaySimulator
                amount={paySheet === 'topup' && shortfall ? shortfall : draft.showPrice ? draft.price : null}
                onDismiss={() => setPaySheet(null)}
                onPaid={settlePaySheet}
              />
            ) : null}
          </Animated.View>
        </View>
      </GestureHandlerRootView>
    </Modal>
  );
}

function PaymentOption({
  method,
  selected,
  disabled,
  balance,
  amountLabel,
  onPress,
}: {
  method: PayMethod;
  selected: boolean;
  disabled: boolean;
  balance: string | null;
  amountLabel: string | null;
  onPress: () => void;
}) {
  const progress = useSharedValue(selected ? 1 : 0);

  useEffect(() => {
    progress.value = withSpring(selected ? 1 : 0, motion.spring);
  }, [progress, selected]);

  const frame = useAnimatedStyle(() => ({
    borderColor: interpolateColor(progress.value, [0, 1], [palette.lineStrong, palette.ivory]),
  }));
  const glow = useAnimatedStyle(() => ({
    opacity: progress.value,
    transform: [{ scale: 0.985 + progress.value * 0.015 }],
  }));

  return (
    <PressableScale accessibilityState={{ selected, disabled }} block onPress={onPress}>
      <Animated.View
        style={[
          {
            flexDirection: 'row',
            alignItems: 'center',
            gap: 12,
            borderRadius: radius.chip,
            borderWidth: 1.5,
            paddingHorizontal: 14,
            paddingVertical: 12,
            overflow: 'hidden',
            backgroundColor: palette.overlay,
          },
          frame,
        ]}
      >
        <Animated.View
          style={[
            {
              position: 'absolute',
              top: -1,
              right: -1,
              bottom: -1,
              left: -1,
              borderRadius: radius.chip,
              borderWidth: 1.5,
              borderColor: palette.gold,
              boxShadow: elevation.goldOrb,
              pointerEvents: 'none',
            },
            glow,
          ]}
        />
        <PaymentMark method={method} />
        <View className="flex-1">
          <Text style={{ color: selected ? palette.goldSoft : palette.ivory, fontSize: 14, fontWeight: '600' }}>
            {methodLabel(method)}
          </Text>
          {method === 'klarna' ? <Text className="mt-1 text-[12px] text-muted">{t('checkout.klarnaBadge')}</Text> : null}
          {amountLabel ? <Text className="mt-1 text-[12px] text-muted">{t('checkout.klarnaSplit', { amount: amountLabel })}</Text> : null}
        </View>
        {balance ? (
          <View
            accessibilityLabel={t('checkout.walletBalance', { amount: balance })}
            style={{
              borderRadius: radius.pill,
              backgroundColor: 'rgba(48, 209, 88, 0.14)',
              borderWidth: 1,
              borderColor: 'rgba(61, 220, 132, 0.45)',
              paddingHorizontal: 10,
              paddingVertical: 5,
              boxShadow: '0 0 16px rgba(61, 220, 132, 0.55)',
            }}
          >
            <Text style={{ color: '#3DDC84', fontSize: 12, fontWeight: '600' }}>{balance}</Text>
          </View>
        ) : null}
      </Animated.View>
    </PressableScale>
  );
}

function ExpressPayButton({ label, onPress, disabled }: { label: string; onPress: () => void; disabled: boolean }) {
  return (
    <PressableScale accessibilityLabel={label} accessibilityState={{ disabled }} block onPress={onPress}>
      <View
        style={{
          height: ACTION_HEIGHT,
          borderRadius: 8,
          backgroundColor: '#000000',
          borderWidth: 1,
          borderColor: '#FFFFFF',
          alignItems: 'center',
          justifyContent: 'center',
          opacity: disabled ? 0.5 : 1,
        }}
      >
        <Text style={{ color: '#FFFFFF', fontSize: 18, fontWeight: '600', letterSpacing: -0.2 }}>{label}</Text>
      </View>
    </PressableScale>
  );
}

function methodLabel(method: PayMethod): string {
  if (method === 'wallet') {
    return t('checkout.wallet');
  }
  if (method === 'apple_pay') {
    return Platform.OS === 'android' ? t('checkout.googlePay') : t('checkout.applePay');
  }
  if (method === 'card') {
    return t('checkout.card');
  }
  if (method === 'paypal') {
    return t('checkout.paypal');
  }
  return t('checkout.klarna');
}

function methodNote(method: PayMethod): string {
  if (method === 'wallet') {
    return t('checkout.walletNote');
  }
  if (method === 'apple_pay') {
    return Platform.OS === 'android' ? t('checkout.googleNote') : t('checkout.appleNote');
  }
  if (method === 'card') {
    return t('checkout.cardNote');
  }
  if (method === 'paypal') {
    return t('checkout.paypalNote');
  }
  return t('checkout.klarnaNote');
}

function expressPayLabel(): string {
  return Platform.OS === 'android' ? t('checkout.payWithGoogle') : t('checkout.payWithApple');
}

function expressTopUpLabel(): string {
  return Platform.OS === 'android' ? t('checkout.topUpGoogle') : t('checkout.topUpApple');
}

function splitInFour(price: DisplayMoney): DisplayMoney {
  return { ...price, amount_minor: Math.floor(price.amount_minor / 4) };
}

function PaymentMark({ method }: { method: PayMethod }) {
  if (method === 'wallet') {
    return (
      <View style={{ width: 40, height: 28, alignItems: 'center', justifyContent: 'center' }}>
        <Wallet color={palette.ivory} size={22} strokeWidth={1.6} />
      </View>
    );
  }
  if (method === 'apple_pay') {
    return (
      <View
        style={{
          height: 28,
          paddingHorizontal: 8,
          borderRadius: 6,
          backgroundColor: '#000000',
          borderWidth: 1,
          borderColor: '#FFFFFF',
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        <Text style={{ color: '#FFFFFF', fontSize: 13, fontWeight: '600' }}> Pay</Text>
      </View>
    );
  }
  if (method === 'card') {
    return (
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
        <View
          style={{
            height: 22,
            paddingHorizontal: 6,
            borderRadius: 4,
            backgroundColor: '#1A1F71',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Text style={{ color: '#FFFFFF', fontSize: 10, fontWeight: '800', letterSpacing: 0.6 }}>VISA</Text>
        </View>
        <Svg height={22} width={32}>
          <Circle cx={11} cy={11} fill="#EB001B" r={8} />
          <Circle cx={21} cy={11} fill="#F79E1B" r={8} />
        </Svg>
      </View>
    );
  }
  if (method === 'paypal') {
    return (
      <Text style={{ fontSize: 16, fontWeight: '800' }}>
        <Text style={{ color: '#003087' }}>Pay</Text>
        <Text style={{ color: '#009CDE' }}>Pal</Text>
      </Text>
    );
  }
  return (
    <View
      style={{
        height: 28,
        paddingHorizontal: 8,
        borderRadius: 6,
        backgroundColor: '#FFA8CD',
        alignItems: 'center',
        justifyContent: 'center',
      }}
    >
      <Text style={{ color: '#0B051D', fontSize: 13, fontWeight: '800' }}>Klarna</Text>
    </View>
  );
}

function ApplePaySimulator({
  amount,
  onPaid,
  onDismiss,
}: {
  amount: DisplayMoney | null;
  onPaid: () => void;
  onDismiss: () => void;
}) {
  const ring = useSharedValue(0.86);
  const tick = useSharedValue(0);
  const paid = useRef(false);
  const cancelled = useRef(false);

  useEffect(() => {
    ring.value = withRepeat(withTiming(1.14, { duration: 680 }), 3, true);
    tick.value = withDelay(
      1700,
      withTiming(1, { duration: 320 }, (finished) => {
        if (finished) {
          runOnJS(finish)();
        }
      }),
    );
  }, [ring, tick]);

  function finish() {
    if (cancelled.current || paid.current) {
      return;
    }
    paid.current = true;
    onPaid();
  }

  function dismiss() {
    cancelled.current = true;
    onDismiss();
  }

  const ringStyle = useAnimatedStyle(() => ({
    opacity: 0.45 + (1.14 - ring.value) * 1.4,
    transform: [{ scale: ring.value }],
  }));
  const tickStyle = useAnimatedStyle(() => ({
    opacity: tick.value,
    transform: [{ scale: 0.72 + tick.value * 0.28 }],
  }));

  return (
    <View style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, justifyContent: 'flex-end' }}>
      <Pressable accessibilityLabel={t('feed.closeA11y')} onPress={dismiss} style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: palette.scrim }} />
      <View
        style={{
          marginHorizontal: 12,
          marginBottom: 12,
          borderRadius: radius.sheet,
          backgroundColor: '#111111',
          borderWidth: 1,
          borderColor: palette.line,
          paddingHorizontal: 20,
          paddingTop: 18,
          paddingBottom: 22,
          gap: 16,
        }}
      >
        <Text className="text-center text-[13px] font-semibold text-ivory">{expressPayLabel()}</Text>
        <View>
          <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-muted">{t('checkout.paySheetTotal')}</Text>
          <Text className="mt-1 text-[32px] font-medium text-ivory">{amount ? formatMoney(amount) : t('checkout.fullPrice')}</Text>
        </View>
        <Text className="text-[15px] text-ivory">{t('checkout.paySheetCard')}</Text>
        <View className="items-center gap-3 py-2">
          <View style={{ width: 84, height: 84, alignItems: 'center', justifyContent: 'center' }}>
            <Animated.View
              style={[
                {
                  position: 'absolute',
                  width: 84,
                  height: 84,
                  borderRadius: 42,
                  borderWidth: 2,
                  borderColor: palette.ivory,
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
                  backgroundColor: palette.ivory,
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
                  borderColor: '#111111',
                  transform: [{ rotate: '40deg' }],
                  marginTop: -4,
                }}
              />
            </Animated.View>
          </View>
          <Text className="text-[13px] text-muted">{t('checkout.paySheetFace')}</Text>
        </View>
      </View>
    </View>
  );
}
