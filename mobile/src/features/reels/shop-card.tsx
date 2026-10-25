import { Image } from 'expo-image';
import { useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';

import type { ShopOffer } from '@/api/contracts';
import { saveCartItem } from '@/api/social-commerce';
import { expectedTotalMinor } from '@/features/payments/expected-total';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette, radius } from '@/theme/tokens';

type Props = {
  offer: ShopOffer;
  busy: boolean;
  couponLabel: string | null;
  onBuy: () => void;
  onSquad: () => void;
  onHold: () => void;
  onGift: () => void;
  onTryOn: () => void;
};

export function ShopCard({ offer, busy, couponLabel, onBuy, onSquad, onHold, onGift, onTryOn }: Props) {
  const router = useRouter();
  const flash = offer.flash;
  const seconds = useCountdown(flash?.ends_at ?? null);
  const liveFlash = flash !== null && seconds > 0;
  const price = {
    ...offer.product.price,
    amount_minor: expectedTotalMinor(offer, 0, 1),
  };

  return (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        gap: 10,
        padding: 8,
        borderRadius: radius.chip,
        backgroundColor: 'rgba(12, 10, 8, 0.72)',
        borderWidth: 1,
        borderColor: 'rgba(255, 255, 255, 0.12)',
      }}
    >
      <Image
        accessibilityIgnoresInvertColors
        contentFit="cover"
        source={{ uri: offer.product.image_url }}
        style={{ width: 54, height: 68, borderRadius: 8, backgroundColor: palette.raised }}
      />
      <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
        <Text numberOfLines={1} style={{ color: palette.muted, fontSize: 11, fontWeight: '600' }}>
          {offer.product.brand}
        </Text>
        <Text numberOfLines={1} style={{ color: palette.ivory, fontSize: 14, fontWeight: '700' }}>
          {offer.product.title}
        </Text>
        <Text style={{ color: palette.ivory, fontSize: 13 }}>
          {formatMoney(price)}
          {offer.product.squad_price ? `  ·  ${formatMoney(offer.product.squad_price)}` : ''}
          {liveFlash ? `  ${t('shop.off', { percent: String(flash.discount_bps / 100) })}` : ''}
        </Text>
        {offer.landed ? (
          <Text style={{ color: palette.muted, fontSize: 11 }}>
            {t('shop.landed', {
              ship: formatMoney(offer.landed.shipping),
              vat: formatMoney(offer.landed.vat),
              duty: formatMoney(offer.landed.duty),
            })}
          </Text>
        ) : null}
        {offer.landed ? <Text style={{ color: palette.goldSoft, fontSize: 11 }}>{t('shop.arrives', { date: offer.landed.delivers_on })}</Text> : null}
        <Text style={{ color: palette.goldSoft, fontSize: 11 }}>{t('shop.protected', { days: String(offer.price_protection_days ?? 14) })}</Text>
        {offer.source === 'auto' ? <Text style={{ color: palette.muted, fontSize: 11 }}>{t('shop.autoTag')}</Text> : null}
        {liveFlash ? (
          <Text style={{ color: palette.goldSoft, fontSize: 12, fontWeight: '700' }}>{t('shop.flash', { seconds: String(seconds) })}</Text>
        ) : null}
        {couponLabel ? (
          <Text numberOfLines={1} style={{ color: palette.goldSoft, fontSize: 11 }}>
            {t('shop.coupon', { label: couponLabel })}
          </Text>
        ) : null}
        <Text style={{ color: palette.muted, fontSize: 11 }}>{t('shop.shareEarn', { percent: String(offer.commission_bps / 100) })}</Text>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 4 }}>
          <Chip disabled={busy || offer.product.stock < 1} label={t('shop.paySolo')} onPress={onBuy} primary />
          <Chip disabled={busy || offer.product.stock < 1} label={t('shop.holdSquad')} onPress={onSquad} />
          <Chip disabled={busy || offer.product.stock < 1} label={t('shop.gift')} onPress={onGift} />
          {offer.drop_hour && offer.drop_hour.remaining > 0 ? <Chip disabled={busy} label={t('shop.dropHold')} onPress={onHold} /> : null}
          {offer.product.try_on ? <Chip disabled={busy} label={t('shop.tryOn')} onPress={onTryOn} /> : null}
          <Chip disabled={busy || offer.product.stock < 1} label={t('shop.addCart')} onPress={() => void saveCartItem(offer.product.id, 1)} />
          <Chip disabled={busy} label={t('shop.reviews')} onPress={() => router.push(`/product/${encodeURIComponent(offer.product.id)}`)} />
          {busy ? <ActivityIndicator color={palette.goldSoft} size="small" /> : null}
        </View>
      </View>
    </View>
  );
}

function Chip({ label, onPress, disabled, primary = false }: { label: string; onPress: () => void; disabled: boolean; primary?: boolean }) {
  return (
    <Pressable
      accessibilityRole="button"
      disabled={disabled}
      onPress={onPress}
      style={{
        paddingHorizontal: 10,
        paddingVertical: 6,
        borderRadius: 999,
        backgroundColor: primary ? palette.gold : 'rgba(255,255,255,0.12)',
        opacity: disabled ? 0.5 : 1,
      }}
    >
      <Text style={{ color: primary ? palette.ink : palette.ivory, fontSize: 12, fontWeight: '700' }}>{label}</Text>
    </Pressable>
  );
}

function useCountdown(endsAt: string | null): number {
  const [seconds, setSeconds] = useState(() => remaining(endsAt));
  const [trackedEnd, setTrackedEnd] = useState(endsAt);
  if (endsAt !== trackedEnd) {
    setTrackedEnd(endsAt);
    setSeconds(remaining(endsAt));
  }
  useEffect(() => {
    if (endsAt === null) {
      return;
    }
    const timer = setInterval(() => setSeconds(remaining(endsAt)), 250);
    return () => clearInterval(timer);
  }, [endsAt]);
  return seconds;
}

function remaining(endsAt: string | null): number {
  if (endsAt === null) {
    return 0;
  }
  return Math.max(0, Math.ceil((Date.parse(endsAt) - Date.now()) / 1000));
}
