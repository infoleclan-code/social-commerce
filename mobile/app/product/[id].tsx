import { useEffect, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';

import { ApiError, api, json } from '@/api/client';
import { goBackOrTabs } from '@/lib/go-back';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

type Thread = {
  can_review_order_id: string | null;
  can_answer: boolean;
  reviews: { body: string; author: { username: string }; verified_purchase: boolean }[];
  questions: { id: string; body: string; asker: string; answer: { body: string; owner: string; verified_purchase: boolean } | null }[];
};

export default function ProductScreen() {
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const productId = typeof id === 'string' ? id : '';
  const [thread, setThread] = useState<Thread | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [review, setReview] = useState('');
  const [question, setQuestion] = useState('');
  const [answer, setAnswer] = useState('');

  function load() {
    if (productId === '') {
      return;
    }
    setError(null);
    void api<{ data: Thread }>(`/products/${encodeURIComponent(productId)}/reviews`)
      .then((body) => setThread(body.data))
      .catch((caught: unknown) => {
        setError(caught instanceof ApiError ? caught.message : t('reviews.error'));
      });
  }

  useEffect(() => {
    load();
  }, [productId]);

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, padding: 20, paddingTop: 64, gap: 12 }}>
      <Pressable accessibilityLabel={t('shop.close')} accessibilityRole="button" onPress={() => goBackOrTabs(router)}>
        <Text style={{ color: palette.ivory }}>{t('shop.close')}</Text>
      </Pressable>
      <Text style={{ color: palette.ivory, fontSize: 24, fontWeight: '700' }}>{t('reviews.title')}</Text>
      {error ? (
        <View style={{ gap: 8 }}>
          <Text style={{ color: palette.goldSoft }}>{error}</Text>
          <Pressable accessibilityLabel={t('reviews.retry')} accessibilityRole="button" onPress={load} style={button}>
            <Text style={label}>{t('reviews.retry')}</Text>
          </Pressable>
        </View>
      ) : null}
      {thread && thread.reviews.length === 0 ? <Text style={{ color: palette.muted }}>{t('reviews.empty')}</Text> : null}
      {(thread?.reviews ?? []).map((item, index) => (
        <View key={`${item.author.username}-${index}`} style={{ gap: 2 }}>
          <Text style={{ color: palette.ivory }}>{item.body}</Text>
          <Text style={{ color: palette.goldSoft, fontSize: 12 }}>{t('reviews.verified')} · @{item.author.username}</Text>
        </View>
      ))}
      {thread?.can_review_order_id ? (
        <View style={{ gap: 8 }}>
          <TextInput accessibilityLabel={t('reviews.send')} value={review} onChangeText={setReview} placeholder={t('reviews.send')} placeholderTextColor={palette.muted} style={field} />
          <Pressable
            accessibilityLabel={t('reviews.send')}
            accessibilityRole="button"
            onPress={() => {
              void api(`/products/${encodeURIComponent(productId)}/reviews`, json('POST', { order_id: thread.can_review_order_id, body: review })).then(() => {
                setReview('');
                load();
              });
            }}
            style={button}
          >
            <Text style={label}>{t('reviews.send')}</Text>
          </Pressable>
        </View>
      ) : null}
      <Text style={{ color: palette.ivory, fontSize: 18, fontWeight: '700' }}>{t('reviews.ask')}</Text>
      {(thread?.questions ?? []).map((item) => (
        <View key={item.id} style={{ gap: 4 }}>
          <Text style={{ color: palette.ivory }}>{item.body}</Text>
          <Text style={{ color: palette.muted, fontSize: 12 }}>@{item.asker}</Text>
          {item.answer ? <Text style={{ color: palette.goldSoft }}>{item.answer.body} · {t('reviews.verified')}</Text> : null}
          {thread?.can_answer && item.answer === null ? (
            <View style={{ gap: 8 }}>
              <TextInput accessibilityLabel={t('reviews.answer')} value={answer} onChangeText={setAnswer} placeholder={t('reviews.answer')} placeholderTextColor={palette.muted} style={field} />
              <Pressable
                accessibilityLabel={t('reviews.answer')}
                accessibilityRole="button"
                onPress={() => {
                  void api(`/questions/${encodeURIComponent(item.id)}/answer`, json('POST', { body: answer })).then(() => {
                    setAnswer('');
                    load();
                  });
                }}
                style={button}
              >
                <Text style={label}>{t('reviews.answer')}</Text>
              </Pressable>
            </View>
          ) : null}
        </View>
      ))}
      <TextInput accessibilityLabel={t('reviews.ask')} value={question} onChangeText={setQuestion} placeholder={t('reviews.ask')} placeholderTextColor={palette.muted} style={field} />
      <Pressable
        accessibilityLabel={t('reviews.ask')}
        accessibilityRole="button"
        onPress={() => {
          void api(`/products/${encodeURIComponent(productId)}/questions`, json('POST', { body: question })).then(() => {
            setQuestion('');
            load();
          });
        }}
        style={button}
      >
        <Text style={label}>{t('reviews.ask')}</Text>
      </Pressable>
    </View>
  );
}

const field = { color: palette.ivory, borderColor: 'rgba(255,255,255,0.2)', borderWidth: 1, borderRadius: 12, padding: 10 };
const button = { alignSelf: 'flex-start' as const, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: palette.gold };
const label = { color: palette.ink, fontWeight: '700' as const };
