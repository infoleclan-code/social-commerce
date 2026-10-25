import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Platform, Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { beginPaymentMethodSetup, confirmPaymentMethodSetup, fetchSavedCards, removeSavedCard, saveTestCard } from '@/api/social-commerce';
import { WebCardForm } from '@/features/payments/web-card-form';
import { stripeBridge } from '@/features/payments/stripe-bridge';
import { newIdempotencyKey } from '@/lib/idempotency';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

export function CardsScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const queryClient = useQueryClient();
  const [note, setNote] = useState<string | null>(null);
  const cards = useQuery({
    queryKey: ['payment-methods'],
    queryFn: ({ signal }) => fetchSavedCards(signal),
  });
  const publishableKey = cards.data?.publishable_key ?? null;

  async function reload() {
    await queryClient.invalidateQueries({ queryKey: ['payment-methods'] });
  }

  async function saveTest() {
    setNote(null);
    try {
      const setup = await beginPaymentMethodSetup(newIdempotencyKey('setup'));
      const saved = await saveTestCard(setup.id, newIdempotencyKey('save'));
      setNote(saved.summary ? t('cards.saved', { last4: saved.summary.last4 }) : t('cards.empty'));
      await reload();
    } catch {
      setNote(t('cards.empty'));
    }
  }

  async function saveNative() {
    if (!publishableKey) {
      return;
    }
    setNote(null);
    try {
      const setup = await beginPaymentMethodSetup(newIdempotencyKey('setup'));
      if (!setup.client_secret) {
        return;
      }
      const outcome = await stripeBridge.presentCardSetup({
        publishableKey,
        setupIntentClientSecret: setup.client_secret,
      });
      if (outcome.status !== 'saved') {
        return;
      }
      await confirmPaymentMethodSetup(outcome.setupIntentId, newIdempotencyKey('confirm'));
      await reload();
    } catch {
      setNote(t('cards.empty'));
    }
  }

  async function remove(cardId: string) {
    setNote(null);
    await removeSavedCard(cardId);
    await reload();
  }

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, paddingTop: insets.top + 16, paddingHorizontal: 20, gap: 16 }}>
      <Pressable accessibilityRole="button" onPress={() => router.back()}>
        <Text style={{ color: palette.ivory, fontWeight: '600' }}>{t('profile.back')}</Text>
      </Pressable>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('cards.title')}</Text>
      {publishableKey && Platform.OS === 'web' ? <WebCardForm publishableKey={publishableKey} onSaved={() => void reload()} /> : null}
      {publishableKey && Platform.OS !== 'web' ? (
        <Pressable accessibilityRole="button" accessibilityLabel={t('cards.save')} onPress={() => void saveNative()} style={button}>
          <Text style={buttonText}>{t('cards.save')}</Text>
        </Pressable>
      ) : null}
      {!publishableKey ? (
        <Pressable accessibilityRole="button" accessibilityLabel={t('cards.saveTest')} onPress={() => void saveTest()} style={button}>
          <Text style={buttonText}>{t('cards.saveTest')}</Text>
        </Pressable>
      ) : null}
      {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
      {(cards.data?.cards.length ?? 0) === 0 ? <Text style={{ color: palette.muted }}>{t('cards.empty')}</Text> : null}
      {cards.data?.cards.map((card) => (
        <View key={card.id} style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
          <Text style={{ color: palette.ivory, fontSize: 16 }}>{`${card.brand} ···· ${card.last4}`}</Text>
          <Pressable accessibilityRole="button" accessibilityLabel={`${t('cards.remove')} ${card.last4}`} onPress={() => void remove(card.id)}>
            <Text style={{ color: palette.goldSoft, fontWeight: '700' }}>{t('cards.remove')}</Text>
          </Pressable>
        </View>
      ))}
    </View>
  );
}

const button = { backgroundColor: palette.gold, borderRadius: 999, paddingVertical: 12, alignItems: 'center' as const };
const buttonText = { color: palette.ink, fontWeight: '700' as const };
