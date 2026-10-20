import { LinearGradient } from 'expo-linear-gradient';
import { ArrowDownToLine, CreditCard, Landmark, Lock, Plus, Wallet, X, type LucideIcon } from 'lucide-react-native';
import { useEffect, useState, type ReactNode } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Modal, Platform, Pressable, Text, TextInput, View } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import Animated, { Easing, SlideInDown, useAnimatedStyle, useSharedValue, withSpring, withTiming } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { DisplayText } from '@/components/ui/display-text';
import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { SuccessBurst } from '@/components/ui/success-burst';
import { DropConfirmSlider } from '@/features/drops/drop-confirm-slider';
import {
  fundingMode,
  newMoneyKey,
  parseAmountMinor,
  submitTopUp,
  submitWithdrawal,
  TOP_UP_LIMITS,
  type FundingMethod,
} from '@/features/wallet/top-up';
import { playError, playSelection, playSuccess } from '@/lib/feedback';
import { t } from '@/lib/i18n';
import { formatMoney, type DisplayMoney } from '@/lib/money';
import { useWalletStore } from '@/stores/wallet';
import { palette, radius } from '@/theme/tokens';

type Sheet = 'topup' | 'withdraw';

const TOP_UP_PRESETS = [2_000, 5_000, 10_000, 25_000] as const;
const WITHDRAW_SHARES = [25, 50, 75, 100] as const;
const HOVER_SPRING = { stiffness: 320, damping: 22, mass: 0.6 } as const;
const PRESS_SPRING = { stiffness: 520, damping: 30, mass: 0.5 } as const;
const SHEET_ENTERING = Platform.OS === 'web' ? SlideInDown.duration(300) : SlideInDown.springify().damping(24).stiffness(220);
const AUTO_CLOSE_MS = 1_800;
const TILE_RADIUS = 22;
const TILE_HEIGHT = 92;
const DANGER = '#E8A0A0';

function inPurse(amountMinor: number, purse: DisplayMoney): DisplayMoney {
  return { amount_minor: amountMinor, currency: purse.currency, minor_unit: purse.minor_unit };
}

function walletPayLabel(): string {
  return Platform.OS === 'android' ? t('checkout.googlePay') : t('checkout.applePay');
}

/**
 * Wallet action suite: the two money actions directly under the "Available to withdraw" balance.
 * Each sheet remounts per open (`session` key), so amounts, method and idempotency key start fresh.
 */
export function WalletActions() {
  const [sheet, setSheet] = useState<Sheet | null>(null);
  const [session, setSession] = useState(0);

  function open(next: Sheet) {
    setSession((count) => count + 1);
    setSheet(next);
  }

  return (
    <>
      <GlassSurface radius={radius.panel} style={{ borderColor: 'rgba(229, 193, 88, 0.22)' }}>
        <View style={{ padding: 12, gap: 12 }}>
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <SuiteButton icon={Plus} label={t('wallet.addFunds')} onPress={() => open('topup')} primary testID="wallet-top-up" />
            <SuiteButton icon={ArrowDownToLine} label={t('wallet.withdraw')} onPress={() => open('withdraw')} testID="wallet-withdraw" />
          </View>
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
            <Lock color={palette.faint} size={12} strokeWidth={2} />
            <Text style={{ color: palette.faint, fontSize: 12 }}>{t('wallet.topUpNote')}</Text>
          </View>
        </View>
      </GlassSurface>
      <TopUpSheet key={`topup-${session}`} onClose={() => setSheet(null)} visible={sheet === 'topup'} />
      <WithdrawSheet key={`withdraw-${session}`} onClose={() => setSheet(null)} visible={sheet === 'withdraw'} />
    </>
  );
}

// Suite buttons ----------------------------------------------------------------------------------

function SuiteButton({
  icon: Icon,
  label,
  onPress,
  primary = false,
  testID,
}: {
  icon: LucideIcon;
  label: string;
  onPress: () => void;
  primary?: boolean;
  testID?: string;
}) {
  const hover = useSharedValue(0);
  const press = useSharedValue(0);
  const sheen = useSharedValue(1);

  function sweep() {
    sheen.set(0);
    sheen.set(withTiming(1, { duration: 760, easing: Easing.out(Easing.cubic) }));
  }

  useEffect(() => {
    if (!primary) {
      return;
    }
    const id = setTimeout(() => {
      sheen.set(0);
      sheen.set(withTiming(1, { duration: 760, easing: Easing.out(Easing.cubic) }));
    }, 650);
    return () => clearTimeout(id);
  }, [primary, sheen]);

  const liftStyle = useAnimatedStyle(() => ({
    transform: [{ translateY: -2 * hover.get() }, { scale: 1 - 0.03 * press.get() }],
  }));
  const glowStyle = useAnimatedStyle(() => ({
    opacity: Math.min(1, (primary ? 0.5 : 0.22) + hover.get() * 0.4 + press.get() * 0.3),
  }));
  const sheenStyle = useAnimatedStyle(() => ({
    opacity: sheen.get() < 1 ? 1 : 0,
    transform: [{ translateX: -90 + sheen.get() * 300 }, { rotate: '18deg' }],
  }));

  const content = (
    <>
      <Animated.View style={[{ position: 'absolute', top: -30, bottom: -30, left: 0, width: 48, pointerEvents: 'none' }, sheenStyle]}>
        <LinearGradient
          colors={
            [
              'rgba(255, 255, 255, 0)',
              primary ? 'rgba(255, 255, 255, 0.55)' : 'rgba(229, 193, 88, 0.35)',
              'rgba(255, 255, 255, 0)',
            ] as const
          }
          end={{ x: 1, y: 0.5 }}
          start={{ x: 0, y: 0.5 }}
          style={{ flex: 1 }}
        />
      </Animated.View>
      <View
        style={{
          width: 36,
          height: 36,
          borderRadius: 18,
          alignItems: 'center',
          justifyContent: 'center',
          backgroundColor: primary ? 'rgba(0, 0, 0, 0.14)' : 'rgba(212, 175, 55, 0.16)',
          borderWidth: primary ? 0 : 1,
          borderColor: 'rgba(229, 193, 88, 0.35)',
        }}
      >
        <Icon color={primary ? palette.ink : palette.goldSoft} size={18} strokeWidth={2.4} />
      </View>
      <Text
        numberOfLines={1}
        style={{ color: primary ? palette.ink : palette.goldSoft, fontSize: 14, fontWeight: '700', letterSpacing: -0.1 }}
      >
        {label}
      </Text>
    </>
  );
  const tile = {
    height: TILE_HEIGHT,
    borderRadius: TILE_RADIUS,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingVertical: 14,
    paddingHorizontal: 8,
    overflow: 'hidden',
  } as const;

  return (
    <Animated.View style={[{ flex: 1 }, liftStyle]}>
      <Animated.View
        style={[
          {
            position: 'absolute',
            top: 0,
            right: 0,
            bottom: 0,
            left: 0,
            borderRadius: TILE_RADIUS,
            boxShadow: primary ? '0 0 26px rgba(212, 175, 55, 0.75)' : '0 0 20px rgba(229, 193, 88, 0.6)',
            pointerEvents: 'none',
          },
          glowStyle,
        ]}
      />
      <Pressable
        accessibilityLabel={label}
        accessibilityRole="button"
        onHoverIn={() => {
          hover.set(withSpring(1, HOVER_SPRING));
          sweep();
        }}
        onHoverOut={() => {
          hover.set(withSpring(0, HOVER_SPRING));
        }}
        onPress={() => {
          playSelection();
          onPress();
        }}
        onPressIn={() => {
          press.set(withSpring(1, PRESS_SPRING));
        }}
        onPressOut={() => {
          press.set(withSpring(0, PRESS_SPRING));
        }}
        style={{ borderRadius: TILE_RADIUS, cursor: 'pointer' }}
        testID={testID}
      >
        {primary ? (
          <LinearGradient colors={['#F3DA82', palette.gold, '#B28822'] as const} end={{ x: 1, y: 1 }} start={{ x: 0, y: 0 }} style={tile}>
            {content}
          </LinearGradient>
        ) : (
          <LinearGradient
            colors={[palette.gold, '#F6E3A1', palette.gold] as const}
            end={{ x: 1, y: 1 }}
            start={{ x: 0, y: 0 }}
            style={{ borderRadius: TILE_RADIUS, padding: 1.5 }}
          >
            <View style={[tile, { height: TILE_HEIGHT - 3, borderRadius: TILE_RADIUS - 1.5, backgroundColor: '#0E0C08' }]}>{content}</View>
          </LinearGradient>
        )}
      </Pressable>
    </Animated.View>
  );
}

// Sheet chrome -----------------------------------------------------------------------------------

function ActionSheet({ visible, title, onClose, children }: { visible: boolean; title: string; onClose: () => void; children: ReactNode }) {
  const insets = useSafeAreaInsets();

  return (
    <Modal animationType="fade" onRequestClose={onClose} statusBarTranslucent transparent visible={visible}>
      <GestureHandlerRootView style={{ flex: 1 }}>
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, justifyContent: 'flex-end' }}>
          <Pressable
            accessibilityLabel={t('feed.closeA11y')}
            onPress={onClose}
            style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: palette.scrim }}
          />
          <Animated.View entering={SHEET_ENTERING} style={{ marginHorizontal: 10, marginBottom: Math.max(insets.bottom, 10) }}>
            <GlassSurface
              radius={radius.sheet}
              style={{ backgroundColor: 'rgba(14, 14, 14, 0.96)', borderColor: 'rgba(229, 193, 88, 0.24)' }}
            >
              <View style={{ padding: 20, gap: 16 }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                  <View style={{ flex: 1, gap: 6 }}>
                    <Text accessibilityRole="header" style={{ color: palette.ivory, fontSize: 22, fontWeight: '600', letterSpacing: -0.4 }}>
                      {title}
                    </Text>
                    {fundingMode === 'demo' ? (
                      <View
                        style={{
                          alignSelf: 'flex-start',
                          borderRadius: radius.pill,
                          borderWidth: 1,
                          borderColor: palette.lineStrong,
                          paddingHorizontal: 8,
                          paddingVertical: 3,
                        }}
                      >
                        <Text style={{ color: palette.muted, fontSize: 11, fontWeight: '600' }}>{t('wallet.demoMode')}</Text>
                      </View>
                    ) : null}
                  </View>
                  <Pressable accessibilityLabel={t('feed.closeA11y')} accessibilityRole="button" hitSlop={8} onPress={onClose}>
                    <View
                      style={{
                        width: 40,
                        height: 40,
                        borderRadius: 20,
                        alignItems: 'center',
                        justifyContent: 'center',
                        backgroundColor: palette.overlay,
                        borderWidth: 1,
                        borderColor: palette.line,
                      }}
                    >
                      <X color={palette.ivory} size={16} strokeWidth={1.75} />
                    </View>
                  </Pressable>
                </View>
                {children}
              </View>
            </GlassSurface>
          </Animated.View>
        </KeyboardAvoidingView>
      </GestureHandlerRootView>
    </Modal>
  );
}

function Choice({
  label,
  selected,
  disabled = false,
  onPress,
}: {
  label: string;
  selected: boolean;
  disabled?: boolean;
  onPress: () => void;
}) {
  return (
    <PressableScale accessibilityState={{ selected, disabled }} disabled={disabled} fill onPress={onPress}>
      <View
        style={{
          minHeight: 44,
          borderRadius: radius.pill,
          borderWidth: 1,
          borderColor: selected ? palette.gold : palette.line,
          backgroundColor: selected ? palette.goldWash : 'rgba(255, 255, 255, 0.03)',
          boxShadow: selected ? '0 0 14px rgba(212, 175, 55, 0.35)' : undefined,
          alignItems: 'center',
          justifyContent: 'center',
          paddingHorizontal: 6,
          opacity: disabled ? 0.4 : 1,
        }}
      >
        <Text
          numberOfLines={1}
          style={{ color: selected ? palette.goldSoft : palette.ivory, fontSize: 14, fontWeight: '600', fontVariant: ['tabular-nums'] }}
        >
          {label}
        </Text>
      </View>
    </PressableScale>
  );
}

function MethodRow({ icon: Icon, label, selected, onPress }: { icon: LucideIcon; label: string; selected: boolean; onPress: () => void }) {
  return (
    <PressableScale accessibilityLabel={label} accessibilityState={{ selected }} block onPress={onPress}>
      <View
        style={{
          minHeight: 52,
          flexDirection: 'row',
          alignItems: 'center',
          gap: 12,
          paddingHorizontal: 14,
          borderRadius: radius.chip,
          borderWidth: 1.5,
          borderColor: selected ? palette.gold : palette.line,
          backgroundColor: selected ? 'rgba(212, 175, 55, 0.08)' : 'rgba(255, 255, 255, 0.03)',
        }}
      >
        <Icon color={selected ? palette.goldSoft : palette.ivory} size={20} strokeWidth={1.7} />
        <Text style={{ flex: 1, color: palette.ivory, fontSize: 15, fontWeight: '600' }}>{label}</Text>
        <View
          style={{
            width: 20,
            height: 20,
            borderRadius: 10,
            borderWidth: 2,
            borderColor: selected ? palette.gold : palette.lineStrong,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          {selected ? <View style={{ width: 10, height: 10, borderRadius: 5, backgroundColor: palette.gold }} /> : null}
        </View>
      </View>
    </PressableScale>
  );
}

function Done({ headline, detail, onClose }: { headline: string; detail: string; onClose: () => void }) {
  useEffect(() => {
    const id = setTimeout(onClose, AUTO_CLOSE_MS);
    return () => clearTimeout(id);
  }, [onClose]);

  return (
    <View accessibilityLiveRegion="polite" style={{ alignItems: 'center', gap: 12, paddingVertical: 8 }}>
      <SuccessBurst />
      <Text style={{ color: palette.ivory, fontSize: 18, fontWeight: '600', textAlign: 'center' }}>{headline}</Text>
      <Text style={{ color: palette.muted, fontSize: 14, textAlign: 'center' }}>{detail}</Text>
      <PressableScale accessibilityLabel={t('wallet.done')} block onPress={onClose}>
        <View
          style={{
            minHeight: 48,
            borderRadius: radius.pill,
            borderWidth: 1,
            borderColor: palette.lineStrong,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Text style={{ color: palette.ivory, fontSize: 15, fontWeight: '600' }}>{t('wallet.done')}</Text>
        </View>
      </PressableScale>
    </View>
  );
}

// Top up -----------------------------------------------------------------------------------------

function TopUpSheet({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const available = useWalletStore((state) => state.available);
  const [preset, setPreset] = useState<number | null>(5_000);
  const [custom, setCustom] = useState('');
  const [method, setMethod] = useState<FundingMethod>('apple_pay');
  const [status, setStatus] = useState<'idle' | 'processing' | 'done'>('idle');
  const [credited, setCredited] = useState<DisplayMoney | null>(null);
  const [attemptKey, setAttemptKey] = useState(() => newMoneyKey('topup'));

  const typed = custom.trim().length > 0;
  const amountMinor = typed ? parseAmountMinor(custom, available.minor_unit) : preset;
  const valid = amountMinor !== null && amountMinor >= TOP_UP_LIMITS.min && amountMinor <= TOP_UP_LIMITS.max;
  const amount = amountMinor !== null ? inPurse(amountMinor, available) : null;
  const busy = status === 'processing';

  async function confirm() {
    if (busy || status === 'done') {
      return;
    }
    if (!valid || !amount) {
      playError();
      return;
    }
    setStatus('processing');
    const result = await submitTopUp(amount, method, attemptKey);
    if (result.status === 'succeeded') {
      playSuccess();
      setCredited(amount);
      setStatus('done');
      return;
    }
    playError();
    setAttemptKey(newMoneyKey('topup'));
    setStatus('idle');
  }

  return (
    <ActionSheet onClose={onClose} title={t('wallet.topUpTitle')} visible={visible}>
      {status === 'done' && credited ? (
        <Done
          detail={t('checkout.walletBalance', { amount: formatMoney(available) })}
          headline={t('wallet.topUpDone', { amount: formatMoney(credited) })}
          onClose={onClose}
        />
      ) : (
        <>
          <DisplayText
            accessibilityLiveRegion="polite"
            style={{ color: valid ? palette.ivory : palette.faint, fontSize: 40, lineHeight: 46, textAlign: 'center' }}
          >
            {amount ? formatMoney(amount) : formatMoney(inPurse(0, available))}
          </DisplayText>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            {TOP_UP_PRESETS.map((value) => (
              <Choice
                disabled={busy}
                key={value}
                label={formatMoney(inPurse(value, available)).replace(/[.,]00$/, '')}
                onPress={() => {
                  playSelection();
                  setPreset(value);
                  setCustom('');
                }}
                selected={!typed && preset === value}
              />
            ))}
          </View>
          <View style={{ gap: 6 }}>
            <TextInput
              accessibilityLabel={t('wallet.topUpCustomA11y', { currency: available.currency })}
              editable={!busy}
              inputMode="decimal"
              keyboardType="decimal-pad"
              maxLength={10}
              onChangeText={(value) => setCustom(value.replace(/[^0-9.,]/g, ''))}
              placeholder={t('wallet.topUpCustom')}
              placeholderTextColor={palette.faint}
              style={{
                minHeight: 48,
                borderRadius: radius.pill,
                borderWidth: 1,
                borderColor: typed && !valid ? DANGER : typed ? palette.gold : palette.line,
                backgroundColor: 'rgba(255, 255, 255, 0.04)',
                color: palette.ivory,
                paddingHorizontal: 18,
                fontSize: 16,
                fontVariant: ['tabular-nums'],
              }}
              value={custom}
            />
            <Text style={{ color: typed && !valid ? DANGER : palette.faint, fontSize: 12, paddingHorizontal: 6 }}>
              {t('wallet.topUpLimits', {
                min: formatMoney(inPurse(TOP_UP_LIMITS.min, available)),
                max: formatMoney(inPurse(TOP_UP_LIMITS.max, available)),
              })}
            </Text>
          </View>
          <View style={{ gap: 8 }}>
            <Text style={{ color: palette.gold, fontSize: 11, fontWeight: '700', letterSpacing: 1.5, textTransform: 'uppercase' }}>
              {t('wallet.topUpMethod')}
            </Text>
            <MethodRow icon={Wallet} label={walletPayLabel()} onPress={() => setMethod('apple_pay')} selected={method === 'apple_pay'} />
            <MethodRow icon={CreditCard} label={t('checkout.card')} onPress={() => setMethod('card')} selected={method === 'card'} />
          </View>
          <PressableScale
            accessibilityLabel={amount ? t('wallet.topUpCta', { amount: formatMoney(amount) }) : t('wallet.confirmTopUp')}
            accessibilityState={{ disabled: !valid || busy, busy }}
            block
            onPress={() => void confirm()}
            testID="wallet-top-up-confirm"
          >
            <LinearGradient
              colors={valid ? (['#F3DA82', palette.gold, '#B28822'] as const) : (['#3A3427', '#2A261D'] as const)}
              end={{ x: 1, y: 1 }}
              start={{ x: 0, y: 0 }}
              style={{
                minHeight: 54,
                borderRadius: radius.pill,
                flexDirection: 'row',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 10,
                boxShadow: valid ? '0 0 22px rgba(212, 175, 55, 0.45)' : undefined,
              }}
            >
              {busy ? <ActivityIndicator color={palette.ink} /> : null}
              <Text style={{ color: valid ? palette.ink : palette.faint, fontSize: 16, fontWeight: '700' }}>
                {busy ? t('wallet.processing') : amount ? t('wallet.topUpCta', { amount: formatMoney(amount) }) : t('wallet.confirmTopUp')}
              </Text>
            </LinearGradient>
          </PressableScale>
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
            <Lock color={palette.faint} size={12} strokeWidth={2} />
            <Text style={{ color: palette.faint, fontSize: 12 }}>{t('wallet.topUpNote')}</Text>
          </View>
        </>
      )}
    </ActionSheet>
  );
}

// Withdraw ---------------------------------------------------------------------------------------

function WithdrawSheet({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const available = useWalletStore((state) => state.available);
  const [share, setShare] = useState<(typeof WITHDRAW_SHARES)[number]>(100);
  const [status, setStatus] = useState<'idle' | 'processing' | 'done'>('idle');
  const [sent, setSent] = useState<DisplayMoney | null>(null);
  const [attempt, setAttempt] = useState(0);
  const [attemptKey, setAttemptKey] = useState(() => newMoneyKey('withdraw'));

  const empty = available.amount_minor <= 0;
  const amountMinor = share === 100 ? available.amount_minor : Math.floor((available.amount_minor * share) / 100);
  const amount = inPurse(amountMinor, available);

  async function confirm() {
    if (status !== 'idle' || amountMinor <= 0) {
      playError();
      return;
    }
    setStatus('processing');
    const result = await submitWithdrawal(amount, attemptKey);
    if (result.status === 'succeeded') {
      playSuccess();
      setSent(amount);
      setStatus('done');
      return;
    }
    playError();
    setAttemptKey(newMoneyKey('withdraw'));
    setAttempt((count) => count + 1);
    setStatus('idle');
  }

  return (
    <ActionSheet onClose={onClose} title={t('wallet.withdrawTitle')} visible={visible}>
      {status === 'done' && sent ? (
        <Done detail={t('wallet.withdrawNote')} headline={t('wallet.withdrawDone', { amount: formatMoney(sent) })} onClose={onClose} />
      ) : (
        <>
          <View style={{ alignItems: 'center', gap: 4 }}>
            <Text style={{ color: palette.gold, fontSize: 11, fontWeight: '700', letterSpacing: 1.5, textTransform: 'uppercase' }}>
              {t('cashback.available')}
            </Text>
            <DisplayText
              accessibilityLiveRegion="polite"
              style={{ color: empty ? palette.faint : palette.ivory, fontSize: 40, lineHeight: 46 }}
            >
              {formatMoney(empty ? available : amount)}
            </DisplayText>
            {!empty && share !== 100 ? (
              <Text style={{ color: palette.faint, fontSize: 12 }}>{t('wallet.withdrawOf', { total: formatMoney(available) })}</Text>
            ) : null}
          </View>
          {empty ? (
            <Text style={{ color: palette.muted, fontSize: 14, lineHeight: 20, textAlign: 'center' }}>{t('wallet.withdrawEmpty')}</Text>
          ) : (
            <>
              <View style={{ flexDirection: 'row', gap: 8 }}>
                {WITHDRAW_SHARES.map((value) => (
                  <Choice
                    disabled={status !== 'idle'}
                    key={value}
                    label={value === 100 ? t('wallet.withdrawAll') : `${value}%`}
                    onPress={() => {
                      playSelection();
                      setShare(value);
                    }}
                    selected={share === value}
                  />
                ))}
              </View>
              <View
                style={{
                  flexDirection: 'row',
                  alignItems: 'center',
                  gap: 12,
                  padding: 14,
                  borderRadius: radius.chip,
                  borderWidth: 1,
                  borderColor: palette.line,
                  backgroundColor: 'rgba(255, 255, 255, 0.03)',
                }}
              >
                <View
                  style={{
                    width: 36,
                    height: 36,
                    borderRadius: 18,
                    alignItems: 'center',
                    justifyContent: 'center',
                    backgroundColor: 'rgba(212, 175, 55, 0.14)',
                  }}
                >
                  <Landmark color={palette.goldSoft} size={18} strokeWidth={1.8} />
                </View>
                <View style={{ flex: 1, gap: 2 }}>
                  <Text style={{ color: palette.ivory, fontSize: 15, fontWeight: '600' }}>{t('wallet.withdrawTo')}</Text>
                  <Text style={{ color: palette.faint, fontSize: 12 }}>{t('wallet.withdrawNote')}</Text>
                </View>
              </View>
              <DropConfirmSlider
                confirmedLabel={t('wallet.withdrawConfirmed')}
                disabled={status !== 'idle' || amountMinor <= 0}
                key={`withdraw-${attempt}`}
                label={t('wallet.withdrawSwipe', { amount: formatMoney(amount) })}
                onConfirm={() => void confirm()}
                testID="wallet-withdraw-swipe"
              />
            </>
          )}
        </>
      )}
    </ActionSheet>
  );
}
