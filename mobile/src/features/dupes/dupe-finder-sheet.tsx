import { useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { ActivityIndicator, Modal, Pressable, Text, View } from 'react-native';

import { fetchRelatedProducts } from '@/api/social-commerce';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { useDupeScanStore } from '@/stores/dupe-scan';
import { palette } from '@/theme/tokens';

/** Related catalog rows for a scanned product. There is no fixture catalog behind this sheet. */
export function DupeFinderSheet() {
  const phase = useDupeScanStore((state) => state.phase);
  const productId = useDupeScanStore((state) => state.productId);
  const close = useDupeScanStore((state) => state.close);
  const open = phase === 'results' && productId !== null;
  const related = useQuery({
    queryKey: ['related', productId],
    queryFn: ({ signal }) => fetchRelatedProducts(productId ?? '', signal),
    enabled: open,
  });

  if (!open || productId === null) {
    return null;
  }

  return (
    <Modal animationType="slide" onRequestClose={close} transparent visible>
      <View style={{ flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(0,0,0,0.55)' }}>
        <View style={{ maxHeight: '80%', backgroundColor: palette.ink, padding: 20, paddingBottom: 36, gap: 12, borderTopLeftRadius: 20, borderTopRightRadius: 20 }}>
          <Pressable accessibilityLabel={t('feed.closeA11y')} accessibilityRole="button" onPress={close}>
            <Text style={{ color: palette.ivory }}>{t('shop.close')}</Text>
          </Pressable>
          <Text style={{ color: palette.ivory, fontSize: 22, fontWeight: '700' }}>{t('feed.searchTitle')}</Text>
          {related.isPending ? <ActivityIndicator color={palette.goldSoft} /> : null}
          {related.isError ? <Text style={{ color: palette.goldSoft }}>{t('explore.error')}</Text> : null}
          {(related.data?.edges ?? []).map((edge) => (
            <View key={`${edge.kind}-${edge.product_id}`} style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
              <Image accessibilityIgnoresInvertColors contentFit="cover" source={{ uri: edge.image_url }} style={{ width: 56, height: 72, borderRadius: 8 }} />
              <View style={{ flex: 1, gap: 2 }}>
                <Text style={{ color: palette.ivory, fontWeight: '700' }}>{edge.title}</Text>
                <Text style={{ color: palette.goldSoft }}>{formatMoney(edge.price)}</Text>
              </View>
            </View>
          ))}
          {related.isSuccess && related.data.edges.length === 0 ? <Text style={{ color: palette.muted }}>{t('feed.empty')}</Text> : null}
        </View>
      </View>
    </Modal>
  );
}
