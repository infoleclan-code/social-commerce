import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Linking, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import {
  dollarsToMinor,
  fetchHistory,
  fetchShop,
  fetchWishlist,
  openStore,
  removeWishlist,
  saveShop,
  saveWishlist,
  searchByPhoto,
  searchCompare,
  type CompareOffer,
  type CompareProduct,
} from '@/api/compare';
import { fetchAlternatives, fetchForecast, searchLink } from '@/api/discover';
import { registerPushDevice } from '@/features/auth/register-push';
import { PriceChart } from '@/features/compare/PriceChart';
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
  const [target, setTarget] = useState('');
  const [link, setLink] = useState('');
  const [inStock, setInStock] = useState(true);
  const [sort, setSort] = useState('total_asc');
  const [note, setNote] = useState<string | null>(null);

  const shop = useQuery({ queryKey: ['shop'], queryFn: fetchShop });
  const country = shop.data?.data.country ?? 'US';
  const currency = shop.data?.data.currency ?? 'USD';
  const results = useQuery({
    queryKey: ['compare', submitted, brand, size, min, max, inStock, sort, country, currency],
    enabled: submitted.trim().length >= 2,
    queryFn: () => searchCompare({
      q: submitted.trim(),
      brand,
      size,
      min_minor: min,
      max_minor: max,
      in_stock: inStock,
      sort,
      country,
      currency,
    }),
  });
  const saved = useQuery({ queryKey: ['wishlist'], queryFn: fetchWishlist });
  const region = useMutation({
    mutationFn: saveShop,
    onSuccess: (next) => queryClient.setQueryData(['shop'], next),
  });
  const save = useMutation({
    mutationFn: () => saveWishlist({
      query: submitted.trim(),
      brand,
      size,
      currency,
      country,
      target_minor: minorOrNull(target, currency),
    }),
    onSuccess: async () => {
      setNote(t('compare.saved'));
      void registerPushDevice();
      await queryClient.invalidateQueries({ queryKey: ['wishlist'] });
    },
    onError: (error: unknown) => setNote(problem(error)),
  });

  const products = results.data?.data.products ?? [];
  const unit = results.data?.data.minor_unit ?? 2;
  const histories = useQueries({
    queries: products.map((product) => ({
      queryKey: ['history', product.brand, product.model, product.size ?? '', currency, product.offers[0]?.total_minor ?? null],
      queryFn: () => fetchHistory({
        brand: product.brand,
        model: product.model,
        size: product.size,
        currency,
        current_minor: product.offers[0]?.total_minor,
      }),
    })),
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

  async function removeSaved(id: string) {
    setNote(null);
    try {
      await removeWishlist(id);
      await queryClient.invalidateQueries({ queryKey: ['wishlist'] });
    } catch (error) {
      setNote(problem(error));
    }
  }

  async function fromLink() {
    setNote(null);
    try {
      const found = await searchLink(link.trim(), currency, country);
      const detected = found.data.detected_query ?? found.data.query;
      setNote(t('guide.linkMatched', { query: detected }));
      setText(detected);
      setSubmitted(detected);
    } catch (error) {
      if (error instanceof ApiError && error.code === 'LINK_REJECTED') {
        setNote(t('guide.linkRejected'));
        return;
      }
      if (error instanceof ApiError && error.code === 'LINK_UNAVAILABLE') {
        setNote(t('guide.linkFailed'));
        return;
      }
      setNote(problem(error));
    }
  }

  async function photo() {
    setNote(null);
    try {
      const ImagePicker = await import('expo-image-picker');
      const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (!permission.granted) {
        setNote(t('compare.photoDenied'));
        return;
      }
      const picked = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.6 });
      if (picked.canceled || picked.assets[0] === undefined) {
        return;
      }
      const asset = picked.assets[0];
      const form = new FormData();
      if (Platform.OS === 'web') {
        const response = await fetch(asset.uri);
        const blob = await response.blob();
        form.append('image', blob, blob.type.includes('png') ? 'photo.png' : 'photo.jpg');
      } else {
        form.append('image', { uri: asset.uri, name: 'photo.jpg', type: asset.mimeType ?? 'image/jpeg' } as unknown as Blob);
      }
      form.append('country', country);
      form.append('currency', currency);
      const found = await searchByPhoto(form);
      const detected = found.data.detected_query ?? found.data.query;
      setNote(found.data.photo_match === 'demo' ? t('compare.photoDemo') : t('compare.photoMatched', { query: detected }));
      setText(detected);
      setSubmitted(detected);
    } catch (error) {
      setNote(problem(error));
    }
  }

  return (
    <ScrollView contentContainerStyle={{ paddingTop: insets.top + 16, paddingHorizontal: 20, paddingBottom: insets.bottom + 120, gap: 14 }} style={{ flex: 1, backgroundColor: palette.ink }}>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('compare.title')}</Text>
      <Text style={{ color: palette.muted }}>{t('compare.body')}</Text>
      <Text style={{ color: palette.muted }}>{t('compare.country', { country })} · {t('compare.currency', { currency })}</Text>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {(shop.data?.data.choices ?? []).map((choice) => (
          <Pressable accessibilityRole="button" key={choice.code} onPress={() => region.mutate({ country: choice.code, currency: choice.currency })} style={[pill, choice.code === country ? { backgroundColor: palette.gold } : null]}>
            <Text style={[pillText, choice.code === country ? { color: palette.ink } : null]}>{choice.code}</Text>
          </Pressable>
        ))}
      </View>
      <TextInput accessibilityLabel={t('compare.search')} autoCapitalize="none" onChangeText={setText} placeholder={t('compare.search')} placeholderTextColor={palette.faint} style={field} value={text} />
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <TextInput accessibilityLabel={t('compare.brand')} onChangeText={setBrand} placeholder={t('compare.brand')} placeholderTextColor={palette.faint} style={[field, { flex: 1 }]} value={brand} />
        <TextInput accessibilityLabel={t('compare.size')} onChangeText={setSize} placeholder={t('compare.size')} placeholderTextColor={palette.faint} style={[field, { flex: 1 }]} value={size} />
      </View>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <TextInput accessibilityLabel={t('compare.min')} keyboardType="decimal-pad" onChangeText={setMin} placeholder={t('compare.min')} placeholderTextColor={palette.faint} style={[field, { flex: 1 }]} value={min} />
        <TextInput accessibilityLabel={t('compare.max')} keyboardType="decimal-pad" onChangeText={setMax} placeholder={t('compare.max')} placeholderTextColor={palette.faint} style={[field, { flex: 1 }]} value={max} />
        <TextInput accessibilityLabel={t('compare.target')} keyboardType="decimal-pad" onChangeText={setTarget} placeholder={t('compare.target')} placeholderTextColor={palette.faint} style={[field, { flex: 1 }]} value={target} />
      </View>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        <Pressable accessibilityRole="button" onPress={() => setInStock((current) => !current)} style={pill}>
          <Text style={pillText}>{inStock ? t('compare.inStock') : t('compare.includeUnavailable')}</Text>
        </Pressable>
        <Pressable accessibilityRole="button" onPress={() => setSort((current) => (current === 'total_asc' ? 'total_desc' : current === 'total_desc' ? 'price_asc' : 'total_asc'))} style={pill}>
          <Text style={pillText}>{sortLabel(sort)}</Text>
        </Pressable>
        <Pressable accessibilityRole="button" onPress={() => { setNote(null); setSubmitted(text.trim()); }} style={button}>
          <Text style={buttonText}>{t('compare.searchAction')}</Text>
        </Pressable>
        <Pressable accessibilityRole="button" onPress={() => void photo()} style={pill}>
          <Text style={pillText}>{t('compare.photo')}</Text>
        </Pressable>
      </View>
      <TextInput accessibilityLabel={t('guide.link')} autoCapitalize="none" onChangeText={setLink} placeholder="https://www.youtube.com/watch?v=…" placeholderTextColor={palette.faint} style={field} value={link} />
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        <Pressable accessibilityRole="button" onPress={() => void fromLink()} style={pill}>
          <Text style={pillText}>{t('guide.linkAction')}</Text>
        </Pressable>
        <Pressable accessibilityRole="button" onPress={() => router.push('/assistant')} style={pill}>
          <Text style={pillText}>{t('guide.assistant')}</Text>
        </Pressable>
        <Pressable accessibilityRole="button" onPress={() => router.push('/lists')} style={pill}>
          <Text style={pillText}>{t('guide.lists')}</Text>
        </Pressable>
        <Pressable accessibilityRole="button" onPress={() => router.push({ pathname: '/try-on', params: { title: products[0]?.title ?? '', category: products[0] ? tryOnCategory(products[0]) : 'shoes' } })} style={pill}>
          <Text style={pillText}>{t('guide.tryOn')}</Text>
        </Pressable>
        <Pressable accessibilityRole="button" onPress={() => router.push('/savings')} style={pill}>
          <Text style={pillText}>{t('guide.savings')}</Text>
        </Pressable>
      </View>
      {results.isFetching ? <Text style={{ color: palette.muted }}>{t('compare.loading')}</Text> : null}
      {results.isError ? <Text style={{ color: palette.goldSoft }}>{problem(results.error)}</Text> : null}
      {products.map((product, index) => {
        const history = histories[index]?.data?.data;
        return (
          <View key={`${product.brand}-${product.model}-${product.size ?? ''}`} style={{ gap: 8 }}>
            <Text style={{ color: palette.ivory, fontSize: 18, fontWeight: '700' }}>{product.title}</Text>
            <Pressable accessibilityRole="button" onPress={() => router.push({ pathname: '/try-on', params: { title: product.title, category: tryOnCategory(product) } })} style={pill}>
              <Text style={pillText}>{t('guide.tryOn')}</Text>
            </Pressable>
            <Text style={{ color: palette.muted }}>{[product.brand, product.size ? t('compare.sizeValue', { size: product.size }) : null].filter(Boolean).join(' · ')}</Text>
            {history ? <PriceChart hint={history.hint} points={history.points} /> : null}
            {history?.low_30_minor !== null && history?.low_30_minor !== undefined ? (
              <Text style={{ color: palette.muted }}>{t('compare.chartLow', { amount: money(history.low_30_minor, history.currency, history.minor_unit) })}</Text>
            ) : null}
            <ForecastNote brand={product.brand} currency={currency} current={product.offers[0]?.total_minor} model={product.model} size={product.size} />
            {product.offers.map((offer, offerIndex) => (
              <View key={offer.id} style={{ gap: 4, padding: 12, borderRadius: 16, backgroundColor: 'rgba(255,255,255,0.06)' }}>
                <Text style={{ color: palette.goldSoft, fontWeight: '700' }}>{offerIndex === 0 ? t('compare.best') : t('compare.offer')} · {offer.store}</Text>
                <Text style={{ color: palette.ivory }}>{money(offer.total_minor, offer.currency, unit)}</Text>
                <Text style={{ color: palette.muted }}>{t('compare.item', { amount: money(offer.price_minor, offer.currency, unit) })}</Text>
                <Text style={{ color: palette.muted }}>
                  {offer.shipping_known && offer.shipping_minor !== null
                    ? t('compare.shipping', { amount: money(offer.shipping_minor, offer.currency, unit) })
                    : t('compare.shippingUnknown')}
                </Text>
                <Text style={{ color: palette.muted }}>{taxLine(offer, unit)}</Text>
                {offer.total_known ? null : <Text style={{ color: palette.muted }}>{t('compare.totalPartial')}</Text>}
                <Text style={{ color: palette.ivory }}>{trustLine(offer)}</Text>
                {offer.rating !== null && offer.review_count !== null ? (
                  <Text style={{ color: palette.muted }}>{t('compare.reviews', { rating: String(offer.rating), count: String(offer.review_count) })}</Text>
                ) : null}
                <Text style={{ color: palette.muted }}>{shipsLine(offer.ships_to_user)}</Text>
                {offer.coupons.map((coupon) => (
                  <Text key={coupon.code} style={{ color: palette.goldSoft }}>{t(coupon.included_in_total ? 'compare.couponIncluded' : 'compare.couponAside', { code: coupon.code, label: coupon.label })}</Text>
                ))}
                <Text style={{ color: palette.muted }}>{t('compare.checked', { time: checkedAt(offer.checked_at) })}</Text>
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
        );
      })}
      {submitted.trim().length >= 2 && products.length === 0 && results.isSuccess ? <Text style={{ color: palette.muted }}>{t('compare.empty')}</Text> : null}
      {submitted.trim().length >= 2 ? (
        <AlternativesNote country={country} currency={currency} onPick={(query) => { setText(query); setSubmitted(query); }} query={submitted.trim()} />
      ) : null}
      <Text style={{ color: palette.ivory, fontSize: 18, fontWeight: '700' }}>{t('compare.wishlist')}</Text>
      {(saved.data?.data ?? []).map((item) => (
        <View key={item.id} style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
          <Text style={{ color: palette.ivory, flex: 1 }}>{item.query}{item.best_total_minor !== null ? ` · ${money(item.best_total_minor, item.currency, unit)}` : ''}</Text>
          <Pressable accessibilityRole="button" onPress={() => void removeSaved(item.id)}>
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

function ForecastNote({
  brand,
  model,
  size,
  currency,
  current,
}: {
  brand: string;
  model: string;
  size: string | null;
  currency: string;
  current?: number;
}) {
  const forecast = useQuery({
    queryKey: ['forecast', brand, model, size ?? '', currency, current ?? null],
    queryFn: () => fetchForecast({ brand, model, size, currency, current_minor: current }),
  });
  const data = forecast.data?.data;
  if (!data) {
    return null;
  }
  const amount = formatMoney({ amount_minor: data.expected_drop_minor, currency: data.currency, minor_unit: data.minor_unit });
  return (
    <View style={{ gap: 4 }}>
      <Text style={{ color: palette.goldSoft, fontWeight: '700' }}>{data.action === 'wait' ? t('guide.forecastWait') : t('guide.forecastBuy')}</Text>
      <Text style={{ color: palette.muted }}>
        {data.expected_drop_minor > 0
          ? t('guide.forecastDrop', { amount, percent: String(data.expected_drop_percent), event: data.next_event.name })
          : t('guide.forecastNone')}
      </Text>
      <Text style={{ color: palette.muted }}>{t('guide.confidence', { level: data.confidence })}</Text>
      <Text style={{ color: palette.muted }}>{data.disclaimer}</Text>
    </View>
  );
}

function AlternativesNote({
  query,
  currency,
  country,
  onPick,
}: {
  query: string;
  currency: string;
  country: string;
  onPick: (query: string) => void;
}) {
  const alts = useQuery({
    queryKey: ['alternatives', query, currency, country],
    enabled: query.length >= 2,
    queryFn: () => fetchAlternatives(query, currency, country),
  });
  const rows = alts.data?.data.alternatives ?? [];
  if (rows.length === 0) {
    return null;
  }
  return (
    <View style={{ gap: 6 }}>
      <Text style={{ color: palette.ivory, fontWeight: '700' }}>{t('guide.alternatives')}</Text>
      {rows.map((row) => (
        <Pressable accessibilityRole="button" key={row.query} onPress={() => onPick(row.query)}>
          <Text style={{ color: palette.goldSoft }}>{row.title} · {row.savings_percent}%</Text>
          <Text style={{ color: palette.muted }}>{row.quality_note}</Text>
        </Pressable>
      ))}
    </View>
  );
}

function minorOrNull(value: string, currency: string): number | null {
  const minor = dollarsToMinor(value, currency);
  return minor === undefined ? null : Number(minor);
}

function money(amount: number, currency: string, unit: number): string {
  return formatMoney({ amount_minor: amount, currency, minor_unit: unit });
}

function taxLine(offer: CompareOffer, unit: number): string {
  if (offer.tax_included) {
    return t('compare.taxIncluded');
  }
  if (offer.tax_known && offer.tax_minor !== null) {
    return t('compare.tax', { amount: money(offer.tax_minor, offer.currency, unit) });
  }
  return t('compare.taxUnknown');
}

function trustLine(offer: CompareOffer): string {
  if (offer.trust === 'trusted') return t('compare.trustTrusted');
  if (offer.trust === 'mixed') return t('compare.trustMixed');
  if (offer.trust === 'caution') return t('compare.trustCaution');
  return t('compare.trustUnknown');
}

function shipsLine(ships: boolean | null): string {
  if (ships === true) return t('compare.shipsYes');
  if (ships === false) return t('compare.shipsNo');
  return t('compare.shipsUnknown');
}

function problem(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.code === 'COMPARE_UNAVAILABLE') return t('compare.unavailableError');
    if (error.code === 'OFFER_EXPIRED') return t('compare.expired');
    if (error.code === 'RATE_LIMITED') return t('compare.slow');
  }
  return t('compare.failed');
}

function tryOnCategory(product: CompareProduct): 'shoes' | 'glasses' | 'hats' {
  const text = `${product.title} ${product.model} ${product.brand}`.toLowerCase();
  if (text.includes('glass')) return 'glasses';
  if (text.includes('shoe') || text.includes('sneaker') || text.includes('dunk') || text.includes('samba')) return 'shoes';
  return 'hats';
}

function sortLabel(sort: string): string {
  if (sort === 'total_desc') return t('compare.sortHigh');
  if (sort === 'price_asc') return t('compare.sortPrice');
  return t('compare.sortLow');
}

function checkedAt(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return iso;
  }
  return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(date);
}

const field = { color: palette.ivory, borderColor: palette.line, borderWidth: 1, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12 };
const button = { backgroundColor: palette.gold, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 10 };
const buttonText = { color: palette.ink, fontWeight: '700' as const };
const pill = { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.12)' };
const pillText = { color: palette.ivory, fontSize: 13, fontWeight: '700' as const };
