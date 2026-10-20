import { useEffect, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';

import { api, json } from '@/api/client';
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
  const [thread, setThread] = useState<Thread | null>(null);
  const [review, setReview] = useState('');
  const [question, setQuestion] = useState('');
  const [answer, setAnswer] = useState('');

  function load() {
    void api<{ data: Thread }>(`/products/${id}/reviews`).then((body) => setThread(body.data));
  }

  useEffect(() => {
    load();
  }, [id]);

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, padding: 20, paddingTop: 64, gap: 12 }}>
      <Pressable onPress={() => goBackOrTabs(router)}><Text style={{ color: palette.ivory }}>{t('shop.close')}</Text></Pressable>
      <Text style={{ color: palette.ivory, fontSize: 24, fontWeight: '700' }}>{t('reviews.title')}</Text>
      <Text style={{ color: palette.muted }}>{t('reviews.empty')}</Text>
      {(thread?.reviews ?? []).map((item) => (
        <View key={item.body} style={{ gap: 2 }}>
          <Text style={{ color: palette.ivory }}>{item.body}</Text>
          <Text style={{ color: palette.goldSoft, fontSize: 12 }}>{t('reviews.verified')} · @{item.author.username}</Text>
        </View>
      ))}
      {thread?.can_review_order_id ? (
        <View style={{ gap: 8 }}>
          <TextInput value={review} onChangeText={setReview} placeholder={t('reviews.send')} placeholderTextColor={palette.muted} style={field} />
          <Pressable
            accessibilityRole="button"
            onPress={() => {
              void api(`/products/${id}/reviews`, json('POST', { order_id: thread.can_review_order_id, body: review })).then(() => {
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
              <TextInput value={answer} onChangeText={setAnswer} placeholder={t('reviews.answer')} placeholderTextColor={palette.muted} style={field} />
              <Pressable
                accessibilityRole="button"
                onPress={() => {
                  void api(`/questions/${item.id}/answer`, json('POST', { body: answer })).then(() => {
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
      <TextInput value={question} onChangeText={setQuestion} placeholder={t('reviews.ask')} placeholderTextColor={palette.muted} style={field} />
      <Pressable
        accessibilityRole="button"
        onPress={() => {
          void api(`/products/${id}/questions`, json('POST', { body: question })).then(() => {
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
