import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import type { OrderSummary } from '@/api/contracts';
import { fetchOrders, withdrawOrder } from '@/api/social-commerce';
import { goBackOrTabs } from '@/lib/go-back';
import { newIdempotencyKey } from '@/lib/idempotency';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette } from '@/theme/tokens';

export default function OrdersScreen() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const orders = useQuery({ queryKey: ['orders'], queryFn: ({ signal }) => fetchOrders(signal) });
  const [note, setNote] = useState<string | null>(null);

  return (
    <ScrollView contentContainerStyle={{ padding: 20, paddingTop: 64, gap: 14, paddingBottom: 48 }} style={{ flex: 1, backgroundColor: palette.ink }}>
      <Pressable accessibilityLabel={t('orders.back')} accessibilityRole="button" onPress={() => goBackOrTabs(router)}>
        <Text style={{ color: palette.ivory }}>{t('orders.back')}</Text>
      </Pressable>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('orders.title')}</Text>
      {orders.isPending ? <ActivityIndicator color={palette.goldSoft} /> : null}
      {orders.isError ? <Text style={{ color: palette.goldSoft }}>{t('orders.error')}</Text> : null}
      {(orders.data ?? []).length === 0 && orders.isSuccess ? <Text style={{ color: palette.muted }}>{t('orders.empty')}</Text> : null}
      {(orders.data ?? []).map((order) => (
        <OrderRow
          key={order.id}
          order={order}
          onReturn={async () => {
            setNote(null);
            try {
              await withdrawOrder(order.id, newIdempotencyKey('return'));
              setNote(t('orders.returned'));
              await queryClient.invalidateQueries({ queryKey: ['orders'] });
            } catch (error) {
              setNote(error instanceof ApiError ? error.message : t('orders.error'));
            }
          }}
        />
      ))}
      {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
    </ScrollView>
  );
}

function OrderRow({ order, onReturn }: { order: OrderSummary; onReturn: () => Promise<void> }) {
  const fulfillment = order.fulfillment ?? 'preparing';
  const fulfillmentLabel =
    fulfillment === 'delivered' ? t('orders.fulfillment.delivered') : fulfillment === 'shipped' ? t('orders.fulfillment.shipped') : t('orders.fulfillment.preparing');
  return (
    <View style={{ gap: 4, paddingVertical: 8 }}>
      <Text style={{ color: palette.ivory, fontSize: 16, fontWeight: '700' }}>{order.item?.title ?? order.id}</Text>
      <Text style={{ color: palette.muted }}>{fulfillmentLabel}{order.total ? ` · ${formatMoney(order.total)}` : ''}</Text>
      {order.carrier && order.tracking_number ? (
        <Text style={{ color: palette.goldSoft }}>{t('orders.tracking', { carrier: order.carrier, number: order.tracking_number })}</Text>
      ) : null}
      <Pressable accessibilityRole="button" onPress={() => void onReturn()} style={{ alignSelf: 'flex-start', marginTop: 4 }}>
        <Text style={{ color: palette.ivory, fontWeight: '600' }}>{t('orders.return')}</Text>
      </Pressable>
    </View>
  );
}
