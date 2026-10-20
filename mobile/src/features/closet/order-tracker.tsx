import { useEffect, useState } from 'react';
import { Modal, Platform, Pressable, ScrollView, Share, StyleSheet, Text, View, type ViewStyle } from 'react-native';
import { BlurView } from 'expo-blur';
import { MapPin, Receipt, Share as ShareIcon, X } from 'lucide-react-native';
import Svg, { Path } from 'react-native-svg';
import Animated, { useAnimatedStyle, useSharedValue, withRepeat, withSpring, withTiming } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { formatMoney } from '@/lib/money';
import { t } from '@/lib/i18n';
import { useClosetStore } from '@/stores/closet';
import { elevation, motion, palette, radius } from '@/theme/tokens';

const STEPS = ['confirmed', 'verified', 'transit', 'delivered'] as const;

const webBlur: ViewStyle =
  Platform.OS === 'web'
    ? ({ backdropFilter: 'blur(20px)', WebkitBackdropFilter: 'blur(20px)' } as ViewStyle)
    : {};

function stepLabel(step: (typeof STEPS)[number]): string {
  if (step === 'confirmed') {
    return t('closet.step.confirmed');
  }
  if (step === 'verified') {
    return t('closet.step.verified');
  }
  if (step === 'transit') {
    return t('closet.step.transit');
  }
  return t('closet.step.delivered');
}

export function OrderTracker() {
  const insets = useSafeAreaInsets();
  const trackingId = useClosetStore((state) => state.trackingId);
  const item = useClosetStore((state) => state.items.find((owned) => owned.id === trackingId) ?? null);
  const closeTracking = useClosetStore((state) => state.closeTracking);
  const [receipt, setReceipt] = useState(false);
  const [map, setMap] = useState(true);
  const pulse = useSharedValue(0.4);
  const rise = useSharedValue(28);
  const active = 2;

  useEffect(() => {
    setReceipt(false);
    setMap(true);
  }, [trackingId]);

  useEffect(() => {
    pulse.value = withRepeat(withTiming(1, { duration: 900 }), -1, true);
    rise.value = withSpring(0, motion.spring);
  }, [pulse, rise, trackingId]);

  const glow = useAnimatedStyle(() => ({
    opacity: 0.35 + pulse.value * 0.65,
    transform: [{ scale: 0.92 + pulse.value * 0.12 }],
  }));
  const sheetStyle = useAnimatedStyle(() => ({
    transform: [{ translateY: rise.value }],
  }));

  if (!item) {
    return null;
  }

  return (
    <Modal animationType="fade" onRequestClose={closeTracking} statusBarTranslucent transparent visible>
      <View style={styles.root}>
        <Pressable accessibilityLabel={t('feed.closeA11y')} onPress={closeTracking} style={StyleSheet.absoluteFill}>
          {Platform.OS === 'web' ? <View pointerEvents="none" style={[StyleSheet.absoluteFill, styles.wash, webBlur]} /> : null}
          {Platform.OS === 'ios' ? <BlurView intensity={40} pointerEvents="none" style={StyleSheet.absoluteFill} tint="dark" /> : null}
          {Platform.OS !== 'web' ? <View pointerEvents="none" style={[StyleSheet.absoluteFill, styles.wash]} /> : null}
        </Pressable>
        <Animated.View style={[styles.sheet, sheetStyle]}>
          <GlassSurface radius={radius.sheet} style={styles.glass}>
            <View style={styles.chrome}>
              <View style={styles.handle} />
              <Pressable
                accessibilityLabel={t('feed.closeA11y')}
                accessibilityRole="button"
                hitSlop={6}
                onPress={closeTracking}
                style={styles.close}
              >
                <X color={palette.ivory} size={18} strokeWidth={1.75} />
              </Pressable>
            </View>
            <View style={styles.heading}>
              <Text style={styles.eyebrow}>{t('closet.trackTitle')}</Text>
              <Text numberOfLines={2} style={styles.title}>
                {item.title}
              </Text>
              <Text numberOfLines={1} style={styles.variant}>
                {item.variant}
              </Text>
            </View>
            <ScrollView contentContainerStyle={styles.body} showsVerticalScrollIndicator={false} style={styles.scroll}>
              {STEPS.map((step, index) => {
                const done = index < active;
                const current = index === active;
                return (
                  <View className="flex-row items-center gap-3" key={step}>
                    {current ? (
                      <Animated.View style={[{ width: 14, height: 14, borderRadius: 7, backgroundColor: palette.gold }, glow]} />
                    ) : (
                      <View
                        style={{
                          width: 14,
                          height: 14,
                          borderRadius: 7,
                          backgroundColor: done ? palette.gold : palette.line,
                        }}
                      />
                    )}
                    <Text className={done || current ? 'flex-1 text-[15px] text-ivory' : 'flex-1 text-[15px] text-faint'}>
                      {stepLabel(step)}
                    </Text>
                  </View>
                );
              })}
              {map ? (
                <View style={styles.map}>
                  <Svg height="140" width="100%">
                    <Path d="M16 110 C 70 100, 90 40, 150 48 S 240 20, 320 36" fill="none" stroke={palette.goldSoft} strokeWidth={3} />
                    <Path d="M20 40 H 300 M 40 20 V 120 M 120 16 V 124 M 200 18 V 122" stroke="rgba(255,255,255,0.08)" strokeWidth={1} />
                  </Svg>
                  <Text style={styles.mapLabel}>{t('closet.mapPreview')}</Text>
                </View>
              ) : null}
              {receipt ? (
                <View style={styles.receipt}>
                  <Text className="text-[13px] text-ivory">{item.title}</Text>
                  <Text className="text-[12px] text-muted">{item.variant}</Text>
                  <Text className="text-[12px] text-muted">{item.orderedAt}</Text>
                  {item.price ? <Text className="text-[14px] text-ivory">{formatMoney(item.price)}</Text> : null}
                </View>
              ) : null}
            </ScrollView>
            <View style={[styles.actions, { paddingBottom: Math.max(insets.bottom, 16) }]}>
              <PressableScale
                accessibilityLabel={t('closet.shareStory')}
                block
                onPress={() => {
                  void Share.share({ message: t('closet.shareMessage', { name: item.title }) });
                }}
              >
                <View style={styles.primary}>
                  <ShareIcon color={palette.ink} size={18} strokeWidth={2.25} />
                  <Text style={styles.primaryLabel}>{t('closet.shareStory')}</Text>
                </View>
              </PressableScale>
              <PressableScale accessibilityLabel={t('closet.track')} block onPress={() => setMap((value) => !value)}>
                <View style={styles.secondary}>
                  <MapPin color={palette.ivory} size={18} strokeWidth={1.75} />
                  <Text style={styles.secondaryLabel}>{t('closet.track')}</Text>
                </View>
              </PressableScale>
              <PressableScale accessibilityLabel={t('closet.receipt')} block onPress={() => setReceipt((value) => !value)}>
                <View style={styles.textAction}>
                  <Receipt color={palette.muted} size={16} strokeWidth={1.75} />
                  <Text style={styles.textLabel}>{t('closet.receipt')}</Text>
                </View>
              </PressableScale>
            </View>
          </GlassSurface>
        </Animated.View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    justifyContent: 'flex-end',
  },
  wash: {
    backgroundColor: 'rgba(0, 0, 0, 0.78)',
  },
  sheet: {
    maxHeight: '88%',
    width: '100%',
  },
  glass: {
    backgroundColor: 'rgba(14, 14, 14, 0.94)',
    borderBottomLeftRadius: 0,
    borderBottomRightRadius: 0,
  },
  chrome: {
    height: 56,
    alignItems: 'center',
    justifyContent: 'center',
  },
  handle: {
    width: 36,
    height: 5,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(255, 255, 255, 0.28)',
  },
  close: {
    position: 'absolute',
    top: 6,
    right: 12,
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: palette.overlay,
  },
  heading: {
    paddingHorizontal: 24,
    paddingBottom: 12,
    gap: 6,
  },
  eyebrow: {
    color: palette.gold,
    fontSize: 11,
    fontWeight: '600',
    letterSpacing: 1.6,
    textTransform: 'uppercase',
  },
  title: {
    color: palette.ivory,
    fontSize: 28,
    lineHeight: 34,
    fontWeight: '500',
    letterSpacing: -0.4,
  },
  variant: {
    color: palette.muted,
    fontSize: 15,
    lineHeight: 20,
  },
  scroll: {
    flexGrow: 0,
  },
  body: {
    gap: 14,
    paddingHorizontal: 24,
    paddingBottom: 8,
  },
  map: {
    height: 140,
    borderRadius: radius.chip,
    overflow: 'hidden',
    backgroundColor: '#121816',
  },
  mapLabel: {
    position: 'absolute',
    left: 12,
    bottom: 12,
    color: palette.goldSoft,
    fontSize: 12,
  },
  receipt: {
    gap: 4,
    borderRadius: radius.chip,
    borderWidth: 1,
    borderColor: palette.line,
    backgroundColor: palette.overlay,
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  actions: {
    gap: 12,
    paddingHorizontal: 24,
    paddingTop: 16,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: palette.line,
  },
  primary: {
    minHeight: 52,
    borderRadius: radius.pill,
    backgroundColor: palette.gold,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingHorizontal: 20,
    boxShadow: elevation.goldBar,
  },
  primaryLabel: {
    color: palette.ink,
    fontSize: 17,
    lineHeight: 22,
    fontWeight: '600',
    letterSpacing: -0.2,
  },
  secondary: {
    minHeight: 52,
    borderRadius: radius.pill,
    borderWidth: 1.5,
    borderColor: 'rgba(255, 255, 255, 0.42)',
    backgroundColor: 'rgba(255, 255, 255, 0.04)',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingHorizontal: 20,
  },
  secondaryLabel: {
    color: palette.ivory,
    fontSize: 17,
    lineHeight: 22,
    fontWeight: '600',
    letterSpacing: -0.2,
  },
  textAction: {
    minHeight: 44,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingHorizontal: 12,
  },
  textLabel: {
    color: palette.ivory,
    fontSize: 16,
    lineHeight: 21,
    fontWeight: '600',
    letterSpacing: -0.1,
  },
});
