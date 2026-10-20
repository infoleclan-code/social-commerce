import { useMutation, useQuery } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';

import { goBackOrTabs } from '@/lib/go-back';

import { fetchBlindBox, fetchLiveAuction, placeBid, pullBlindBox } from '@/api/social-commerce';
import { completePayment } from '@/features/payments/complete-payment';
import { isDefinite, isDefiniteFailure, purchaseErrorCopy, resolutionCopy } from '@/features/payments/purchase-copy';
import { IntentKeys } from '@/lib/idempotency';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette } from '@/theme/tokens';

export default function PlayScreen() {
  const router = useRouter();
  const auction = useQuery({ queryKey: ['auction', 'live'], queryFn: ({ signal }) => fetchLiveAuction(signal), refetchInterval: 3000 });
  const box = useQuery({ queryKey: ['blind-box'], queryFn: ({ signal }) => fetchBlindBox(signal) });
  const [pulled, setPulled] = useState<string | null>(null);
  // One key per pull intent: a lost answer is retried with it (the server replays, never charges twice).
  const pullKeys = useRef(new IntentKeys('pull'));
  const bid = useMutation({
    mutationFn: () => {
      const live = auction.data;
      if (!live) {
        throw new Error('closed');
      }
      return placeBid(live.id, live.next_minor);
    },
    onSuccess: () => {
      void auction.refetch();
    },
  });
  const pull = useMutation({
    mutationFn: async () => {
      const boxId = box.data?.id;
      if (!boxId) {
        throw new Error('closed');
      }
      try {
        const result = await pullBlindBox(boxId, pullKeys.current.for(boxId));
        const resolution = await completePayment(result.order_id, result);
        if (isDefinite(resolution)) {
          pullKeys.current.settle(boxId);
        }
        if (resolution.kind === 'paid' && result.product) {
          return result.product.title;
        }
        // Revealed only once the payment is captured (after the undo window).
        return resolution.kind === 'held' || resolution.kind === 'paid' ? t('purchase.revealPending') : resolutionCopy(resolution, box.data?.price ?? null);
      } catch (error) {
        if (isDefiniteFailure(error)) {
          pullKeys.current.settle(boxId);
        }
        throw error;
      }
    },
    onSuccess: (message) => setPulled(message),
    onError: (error) => setPulled(purchaseErrorCopy(error)),
  });

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, padding: 20, paddingTop: 64, gap: 16 }}>
      <Pressable onPress={() => goBackOrTabs(router)}><Text style={{ color: palette.ivory }}>{t('shop.close')}</Text></Pressable>
      {auction.isPending ? <ActivityIndicator color={palette.goldSoft} /> : null}
      {auction.data ? (
        <View style={{ gap: 8 }}>
          <Text style={{ color: palette.ivory, fontSize: 20, fontWeight: '700' }}>{auction.data.title}</Text>
          <Text style={{ color: palette.goldSoft }}>{formatMoney(auction.data.current)}{auction.data.leader ? ` · @${auction.data.leader}` : ''}</Text>
          <Pressable accessibilityRole="button" onPress={() => bid.mutate()} style={{ alignSelf: 'flex-start', paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: palette.gold }}>
            <Text style={{ color: palette.ink, fontWeight: '700' }}>{t('play.bid', { price: formatMoney({ ...auction.data.current, amount_minor: auction.data.next_minor }) })}</Text>
          </Pressable>
        </View>
      ) : null}
      {box.data ? (
        <View style={{ gap: 8 }}>
          <Text style={{ color: palette.ivory, fontSize: 20, fontWeight: '700' }}>{box.data.title}</Text>
          <Text style={{ color: palette.muted }}>{t('play.odds')}</Text>
          {box.data.odds.map((odd) => (
            <Text key={odd.label} style={{ color: palette.ivory }}>{odd.label} · {odd.percent}%</Text>
          ))}
          <Pressable accessibilityRole="button" disabled={pull.isPending} onPress={() => pull.mutate()} style={{ alignSelf: 'flex-start', paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: palette.gold }}>
            <Text style={{ color: palette.ink, fontWeight: '700' }}>{t('play.pull')} · {formatMoney(box.data.price)}</Text>
          </Pressable>
          {pulled ? <Text style={{ color: palette.goldSoft }}>{pulled}</Text> : null}
        </View>
      ) : null}
    </View>
  );
}
