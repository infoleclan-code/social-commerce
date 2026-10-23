import { useQuery } from '@tanstack/react-query';
import { X } from 'lucide-react-native';
import { useState } from 'react';
import { ActivityIndicator, Modal, Pressable, Text, View } from 'react-native';

import { fetchProductOffers, saveCartItem } from '@/api/social-commerce';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { useProductSheetStore } from '@/stores/product-sheet';
import { palette } from '@/theme/tokens';

/** Live seller offers for one product. Drop ids from the old fixture feed close without sample copy. */
export function ProductSheet() {
  const target = useProductSheetStore((state) => state.target);
  const close = useProductSheetStore((state) => state.close);
  const [note, setNote] = useState<string | null>(null);
  const productId = target?.kind === 'product' ? target.id : null;
  const offers = useQuery({
    queryKey: ['product-offers', productId],
    queryFn: ({ signal }) => fetchProductOffers(productId ?? '', null, signal),
    enabled: productId !== null,
  });

  if (productId === null) {
    return null;
  }
  const offer = offers.data?.offers[0];

  return (
    <Modal animationType="slide" onRequestClose={close} transparent visible>
      <View style={{ flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(0,0,0,0.55)' }}>
        <View style={{ backgroundColor: palette.ink, padding: 20, paddingBottom: 36, gap: 12, borderTopLeftRadius: 20, borderTopRightRadius: 20 }}>
          <Pressable accessibilityLabel={t('feed.closeA11y')} accessibilityRole="button" onPress={close} style={{ alignSelf: 'flex-end' }}>
            <X color={palette.ivory} size={22} />
          </Pressable>
          {offers.isPending ? <ActivityIndicator color={palette.goldSoft} /> : null}
          {offers.isError ? <Text style={{ color: palette.goldSoft }}>{t('reviews.error')}</Text> : null}
          {offer ? (
            <>
              <Text style={{ color: palette.ivory, fontSize: 22, fontWeight: '700' }}>{offer.title}</Text>
              <Text style={{ color: palette.goldSoft, fontSize: 18 }}>{formatMoney(offer.landed.total)}</Text>
              <Text style={{ color: palette.muted }}>{offer.in_stock ? t('shop.paySolo') : t('purchase.soldOut')}</Text>
              <Pressable
                accessibilityRole="button"
                disabled={!offer.in_stock}
                onPress={() => {
                  void saveCartItem(offer.product_id, 1).then(() => setNote(t('cart.done'))).catch(() => setNote(t('cart.failed')));
                }}
                style={{ alignSelf: 'flex-start', paddingHorizontal: 14, paddingVertical: 10, borderRadius: 999, backgroundColor: palette.gold, opacity: offer.in_stock ? 1 : 0.5 }}
              >
                <Text style={{ color: palette.ink, fontWeight: '700' }}>{t('shop.addCart')}</Text>
              </Pressable>
            </>
          ) : null}
          {offers.isSuccess && offers.data.offers.length === 0 ? <Text style={{ color: palette.muted }}>{t('feed.empty')}</Text> : null}
          {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
        </View>
      </View>
    </Modal>
  );
}
