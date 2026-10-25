import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as Linking from 'expo-linking';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { fetchWishlist, openStore, removeWishlist, saveWishlist, searchCompare, type CompareOffer } from '@/api/compare';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette } from '@/theme/tokens';

export default function CompareScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const params = useLocalSearchParams<{ q?: string }>();
  const initial = typeof params.q === 'string' ? params.q : '';
  const queryClient = useQueryClient();
  const [text, setText] = useState(initial);
  const [submitted, setSubmitted] = useState(initial);
  const [brand, setBrand] = useState('');
  const [size, setSize] = useState('');
  const [min, setMin] = useState('');
  const [max, setMax] = useState('');
  const [inStock, setInStock] = useState(true);
  const [sort, setSort] = useState('total_asc');
  const [note, setNote] = useState<string | null>(null);

  const results = useQuery({
    queryKey: ['compare', submitted, brand, size, min, max, inStock, sort],
    enabled: submitted.trim().length >= 2,
    queryFn: () => searchCompare({
      q: submitted.trim(),
      brand,
      size,
      min_minor: min,
      max_minor: max,
      in_stock: inStock,
      sort,
    }),
  });
  const saved = useQuery({ queryKey: ['wishlist'], queryFn: fetchWishlist });
  const save = useMutation({
    mutationFn: () => saveWishlist({ query: submitted.trim(), brand, size }),
    onSuccess: async () => {
      setNote(t('compare.saved'));
      await queryClient.invalidateQueries({ queryKey: ['wishlist'] });
    },
    onError: (error: unknown) => setNote(problem(error)),
  });

  async function visit(offer: CompareOffer) {
    setNote(null);
    try {
      const opened = await openStore(offer.id);
      if (opened.data.url.startsWith('https://')) {
        await Linking.openURL(opened.data.url);
      }
    } catch (error) {
      setNote(problem(error));
    }
  }

  const products = results.data?.data.products ?? [];

  return (
    <ScrollView contentContainerStyle={{ paddingTop: insets.top + 16, paddingHorizontal: 20, paddingBottom: insets.bottom + 120, gap: 14 }} style={{ flex: 1, backgroundColor: palette.ink }}>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('compare.title')}</Text>
      <Text style={{ color: palette.muted }}>{t('compare.body')}</Text>
      <TextInput accessibilityLabel={t('compare.search')} autoCapitalize="none" onChangeText={setText} placeholder={t('compare.search')} placeholderTextColor={palette.faint} style={field} value={text} />
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <TextInput accessibilityLabel={t('compare.brand')} onChangeText={setBrand} placeholder={t('compare.brand')} placeholderTextColor={palette.faint} style={[field, { flex: 1 }]} value={brand} />
        <TextInput accessibilityLabel={t('compare.size')} onChangeText={setSize} placeholder={t('compare.size')} placeholderTextColor={palette.faint} style={[field, { flex: 1 }]} value={size} />
      </View>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <TextInput accessibilityLabel={t('compare.min')} keyboardType="number-pad" onChangeText={setMin} placeholder={t('compare.min')} placeholderTextColor={palette.faint} style={[field, { flex: 1 }]} value={min} />
        <TextInput accessibilityLabel={t('compare.max')} keyboardType="number-pad" onChangeText={setMax} placeholder={t('compare.max')} placeholderTextColor={palette.faint} style={[field, { flex: 1 }]} value={max} />
      </View>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        <Pressable accessibilityRole="button" onPress={() => setInStock((current) => !current)} style={pill}>
          <Text style={pillText}>{inStock ? t('compare.inStock') : t('compare.includeUnavailable')}</Text>
        </Pressable>
        <Pressable accessibilityRole="button" onPress={() => setSort((current) => (current === 'total_asc' ? 'total_desc' : current === 'total_desc' ? 'price_asc' : 'total_asc'))} style={pill}>
          <Text style={pillText}>{t('compare.sort')}</Text>
        </Pressable>
        <Pressable accessibilityRole="button" onPress={() => { setNote(null); setSubmitted(text.trim()); }} style={button}>
          <Text style={buttonText}>{t('compare.searchAction')}</Text>
        </Pressable>
      </View>
      <Pressable accessibilityRole="button" onPress={() => router.push('/scan')}>
        <Text style={{ color: palette.goldSoft, fontWeight: '700' }}>{t('compare.photo')}</Text>
      </Pressable>
      {results.isError ? <Text style={{ color: palette.goldSoft }}>{problem(results.error)}</Text> : null}
      {products.map((product) => (
        <View key={`${product.brand}-${product.model}-${product.size ?? ''}`} style={{ gap: 8 }}>
          <Text style={{ color: palette.ivory, fontSize: 18, fontWeight: '700' }}>{product.title}</Text>
          <Text style={{ color: palette.muted }}>{[product.brand, product.size ? t('compare.sizeValue', { size: product.size }) : null].filter(Boolean).join(' · ')}</Text>
          {product.offers.map((offer, index) => (
            <View key={offer.id} style={{ gap: 4, padding: 12, borderRadius: 16, backgroundColor: 'rgba(255,255,255,0.06)' }}>
              <Text style={{ color: palette.goldSoft, fontWeight: '700' }}>{index === 0 ? t('compare.best') : t('compare.offer')} · {offer.store}</Text>
              <Text style={{ color: palette.ivory }}>{formatMoney({ amount_minor: offer.total_minor, currency: offer.currency, minor_unit: 2 })}</Text>
              <Text style={{ color: palette.muted }}>
                {offer.shipping_known && offer.shipping_minor !== null
                  ? t('compare.shipping', { amount: formatMoney({ amount_minor: offer.shipping_minor, currency: offer.currency, minor_unit: 2 }) })
                  : t('compare.shippingUnknown')}
              </Text>
              <Text style={{ color: palette.muted }}>{t('compare.checked', { time: offer.checked_at })}</Text>
              <Pressable accessibilityRole="link" onPress={() => void visit(offer)}>
                <Text style={{ color: palette.ivory, fontWeight: '700' }}>{t('compare.openStore')}</Text>
              </Pressable>
            </View>
          ))}
          {(product.unavailable ?? []).map((offer) => (
            <Text key={`${offer.store}-${offer.title}`} style={{ color: palette.muted }}>{t('compare.unavailable', { store: offer.store })}</Text>
          ))}
          <Pressable accessibilityRole="button" onPress={() => save.mutate()} style={pill}>
            <Text style={pillText}>{t('compare.save')}</Text>
          </Pressable>
        </View>
      ))}
      {submitted.trim().length >= 2 && products.length === 0 && results.isSuccess ? <Text style={{ color: palette.muted }}>{t('compare.empty')}</Text> : null}
      <Text style={{ color: palette.ivory, fontSize: 18, fontWeight: '700' }}>{t('compare.wishlist')}</Text>
      {(saved.data?.data ?? []).map((item) => (
        <View key={item.id} style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
          <Text style={{ color: palette.ivory, flex: 1 }}>{item.query}{item.best_total_minor !== null ? ` · ${formatMoney({ amount_minor: item.best_total_minor, currency: item.currency, minor_unit: 2 })}` : ''}</Text>
          <Pressable accessibilityRole="button" onPress={() => void removeWishlist(item.id).then(() => queryClient.invalidateQueries({ queryKey: ['wishlist'] }))}>
            <Text style={{ color: palette.goldSoft }}>{t('compare.remove')}</Text>
          </Pressable>
        </View>
      ))}
      <Text style={{ color: palette.muted }}>{t('compare.alertNote')}</Text>
      <Pressable accessibilityRole="button" onPress={() => router.push('/legal')}>
        <Text style={{ color: palette.goldSoft, fontWeight: '700' }}>{t('legal.open')}</Text>
      </Pressable>
      {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
    </ScrollView>
  );
}

function problem(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.code === 'COMPARE_UNAVAILABLE') return t('compare.unavailableError');
    if (error.code === 'OFFER_EXPIRED') return t('compare.expired');
    if (error.code === 'RATE_LIMITED') return t('compare.slow');
  }
  return t('compare.failed');
}

const field = { color: palette.ivory, borderColor: palette.line, borderWidth: 1, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12 };
const button = { backgroundColor: palette.gold, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 10 };
const buttonText = { color: palette.ink, fontWeight: '700' as const };
const pill = { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.12)' };
const pillText = { color: palette.ivory, fontSize: 13, fontWeight: '700' as const };
