import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ShoppingBag } from 'lucide-react-native';
import { useState } from 'react';
import { ActivityIndicator, Pressable, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { checkoutCart, fetchAddresses, fetchCart, removeCartItem, saveAddress, saveCartItem, type CartLine } from '@/api/social-commerce';
import { EmptyHero } from '@/components/ui/empty-hero';
import { PressableScale } from '@/components/ui/pressable-scale';
import { Reveal } from '@/components/ui/reveal';
import { Screen } from '@/components/ui/screen';
import { newIdempotencyKey } from '@/lib/idempotency';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette } from '@/theme/tokens';

type Props = {
  onBack: () => void;
};

export function CartScreen({ onBack }: Props) {
  const queryClient = useQueryClient();
  const cart = useQuery({ queryKey: ['cart'], queryFn: ({ signal }) => fetchCart(signal) });
  const addresses = useQuery({ queryKey: ['addresses'], queryFn: ({ signal }) => fetchAddresses(signal) });
  const [line, setLine] = useState('');
  const [locality, setLocality] = useState('');
  const [postal, setPostal] = useState('');
  const [country, setCountry] = useState('');
  const [note, setNote] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const chosen = (addresses.data ?? []).find((address) => address.is_default) ?? addresses.data?.[0] ?? null;

  async function addAddress() {
    setBusy(true);
    setNote(null);
    try {
      await saveAddress({ line: line.trim(), locality: locality.trim(), postal_code: postal.trim(), country: country.trim().toUpperCase(), is_default: true });
      setLine('');
      setLocality('');
      setPostal('');
      setCountry('');
      await queryClient.invalidateQueries({ queryKey: ['addresses'] });
    } catch (error) {
      setNote(error instanceof ApiError ? error.message : t('cart.failed'));
    } finally {
      setBusy(false);
    }
  }

  async function pay() {
    if (!chosen) {
      return;
    }
    setBusy(true);
    setNote(null);
    try {
      await checkoutCart(chosen.id, newIdempotencyKey('cart'));
      setNote(t('cart.done'));
      await queryClient.invalidateQueries({ queryKey: ['cart'] });
    } catch (error) {
      setNote(error instanceof ApiError ? error.message : t('cart.failed'));
    } finally {
      setBusy(false);
    }
  }

  const lines = cart.data ?? [];

  return (
    <Screen tabBar={false}>
      <Reveal>
        <PressableScale
          accessibilityLabel={t('cart.back')}
          onPress={onBack}
          className="h-11 w-11 items-center justify-center rounded-full border border-line bg-raised"
        >
          <ChevronLeft color={palette.ivory} size={20} strokeWidth={1.5} />
        </PressableScale>
      </Reveal>
      {cart.isPending ? (
        <ActivityIndicator color={palette.goldSoft} style={{ marginTop: 32 }} />
      ) : lines.length === 0 ? (
        <Reveal delay={60}>
          <EmptyHero body={t('cart.body')} eyebrow={t('cart.title')} icon={ShoppingBag} title={t('cart.empty')} />
        </Reveal>
      ) : (
        <View style={{ gap: 16, marginTop: 16 }}>
          {lines.map((item) => (
            <CartRow
              item={item}
              key={item.product_id}
              onChange={(quantity) => {
                void saveCartItem(item.product_id, quantity).then(() => queryClient.invalidateQueries({ queryKey: ['cart'] }));
              }}
              onRemove={() => {
                void removeCartItem(item.product_id).then(() => queryClient.invalidateQueries({ queryKey: ['cart'] }));
              }}
            />
          ))}
          <Text style={{ color: palette.ivory, fontSize: 16, fontWeight: '700' }}>{t('cart.address')}</Text>
          {chosen ? (
            <Text style={{ color: palette.muted }}>
              {chosen.line}, {chosen.locality} {chosen.postal_code}, {chosen.country}
            </Text>
          ) : null}
          <TextInput accessibilityLabel={t('cart.line')} onChangeText={setLine} placeholder={t('cart.line')} placeholderTextColor={palette.muted} style={field} value={line} />
          <TextInput accessibilityLabel={t('cart.locality')} onChangeText={setLocality} placeholder={t('cart.locality')} placeholderTextColor={palette.muted} style={field} value={locality} />
          <TextInput accessibilityLabel={t('cart.postal')} onChangeText={setPostal} placeholder={t('cart.postal')} placeholderTextColor={palette.muted} style={field} value={postal} />
          <TextInput accessibilityLabel={t('cart.country')} autoCapitalize="characters" maxLength={2} onChangeText={setCountry} placeholder={t('cart.country')} placeholderTextColor={palette.muted} style={field} value={country} />
          <Pressable accessibilityRole="button" disabled={busy} onPress={() => void addAddress()} style={button}>
            <Text style={label}>{t('cart.saveAddress')}</Text>
          </Pressable>
          <Pressable accessibilityRole="button" disabled={busy || chosen === null} onPress={() => void pay()} style={[button, { opacity: chosen === null ? 0.5 : 1 }]}>
            <Text style={label}>{busy ? t('cart.working') : t('cart.checkout')}</Text>
          </Pressable>
          {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
        </View>
      )}
    </Screen>
  );
}

function CartRow({ item, onChange, onRemove }: { item: CartLine; onChange: (quantity: number) => void; onRemove: () => void }) {
  return (
    <View style={{ gap: 6 }}>
      <Text style={{ color: palette.ivory, fontSize: 16, fontWeight: '700' }}>{item.title}</Text>
      <Text style={{ color: palette.goldSoft }}>{formatMoney(item.price)} · {item.quantity}</Text>
      {!item.available ? <Text style={{ color: palette.muted }}>{t('purchase.soldOut')}</Text> : null}
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <Pressable accessibilityLabel={t('cart.remove')} accessibilityRole="button" onPress={onRemove} style={chip}>
          <Text style={{ color: palette.ivory }}>{t('cart.remove')}</Text>
        </Pressable>
        {item.quantity < 3 ? (
          <Pressable accessibilityRole="button" onPress={() => onChange(item.quantity + 1)} style={chip}>
            <Text style={{ color: palette.ivory }}>+</Text>
          </Pressable>
        ) : null}
        {item.quantity > 1 ? (
          <Pressable accessibilityRole="button" onPress={() => onChange(item.quantity - 1)} style={chip}>
            <Text style={{ color: palette.ivory }}>−</Text>
          </Pressable>
        ) : null}
      </View>
    </View>
  );
}

const field = { color: palette.ivory, borderColor: 'rgba(255,255,255,0.2)', borderWidth: 1, borderRadius: 12, padding: 10 };
const button = { alignSelf: 'flex-start' as const, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: palette.gold };
const label = { color: palette.ink, fontWeight: '700' as const };
const chip = { paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, borderWidth: 1, borderColor: 'rgba(255,255,255,0.2)' };
