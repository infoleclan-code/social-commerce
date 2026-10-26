import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { fetchShop } from '@/api/compare';
import { askAssistant, type AssistantReply } from '@/api/discover';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette } from '@/theme/tokens';

export function AssistantScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const shop = useQuery({ queryKey: ['shop'], queryFn: fetchShop });
  const [message, setMessage] = useState('');
  const [reply, setReply] = useState<AssistantReply | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function send() {
    setBusy(true);
    setNote(null);
    try {
      const result = await askAssistant(message.trim(), shop.data?.data.currency, shop.data?.data.country);
      setReply(result.data);
    } catch (error) {
      setNote(error instanceof ApiError && error.code === 'RATE_LIMITED' ? t('compare.slow') : t('guide.assistantFailed'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={{ paddingTop: insets.top + 16, paddingHorizontal: 20, paddingBottom: insets.bottom + 40, gap: 14 }} style={{ flex: 1, backgroundColor: palette.ink }}>
      <Pressable accessibilityRole="button" onPress={() => router.back()}>
        <Text style={{ color: palette.ivory, fontWeight: '600' }}>{t('profile.back')}</Text>
      </Pressable>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('guide.assistantTitle')}</Text>
      <Text style={{ color: palette.muted }}>{t('guide.assistantBody')}</Text>
      <TextInput accessibilityLabel={t('guide.message')} onChangeText={setMessage} placeholder={t('guide.message')} placeholderTextColor={palette.faint} style={field} value={message} />
      <Pressable accessibilityRole="button" disabled={busy} onPress={() => void send()} style={button}>
        <Text style={buttonText}>{t('guide.send')}</Text>
      </Pressable>
      {reply ? <Text style={{ color: palette.ivory }}>{reply.reply}</Text> : null}
      {reply?.products.map((product) => {
        const offer = product.compare.products[0]?.offers[0];
        const unit = product.compare.minor_unit ?? 2;
        return (
          <View key={product.query} style={{ gap: 6, padding: 12, borderRadius: 16, backgroundColor: 'rgba(255,255,255,0.06)' }}>
            <Text style={{ color: palette.goldSoft, fontWeight: '700' }}>{product.summary}</Text>
            <Text style={{ color: palette.muted }}>{t('guide.pros')}: {product.pros.join(', ')}</Text>
            <Text style={{ color: palette.muted }}>{t('guide.cons')}: {product.cons.join(', ')}</Text>
            {offer ? (
              <Text style={{ color: palette.ivory }}>{offer.store} · {formatMoney({ amount_minor: offer.total_minor, currency: offer.currency, minor_unit: unit })}</Text>
            ) : null}
          </View>
        );
      })}
      {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
    </ScrollView>
  );
}

const field = { color: palette.ivory, borderColor: palette.line, borderWidth: 1, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12 };
const button = { backgroundColor: palette.gold, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 10, alignSelf: 'flex-start' as const };
const buttonText = { color: palette.ink, fontWeight: '700' as const };
