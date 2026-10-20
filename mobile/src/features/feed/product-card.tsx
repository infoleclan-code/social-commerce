import { Pressable, Text, View } from 'react-native';

import { GlassSurface } from '@/components/ui/glass-surface';
import { MediaCard } from '@/components/ui/media-card';
import type { FeedProduct } from '@/features/feed/fixtures';
import { formatExpectedDate } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { useProductSheetStore } from '@/stores/product-sheet';
import { palette, radius } from '@/theme/tokens';

type Props = {
  product: FeedProduct;
};

export function ProductCard({ product }: Props) {
  const openProduct = useProductSheetStore((state) => state.openProduct);
  const pending = t('cashback.pending', { date: formatExpectedDate(product.expectedOn) });

  return (
    <MediaCard image={product.image} style={{ flex: 1 }}>
      <Pressable
        accessibilityLabel={product.name}
        accessibilityRole="button"
        onPress={() => openProduct(product.id)}
        style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }}
      />
      <View style={{ paddingHorizontal: 14, paddingBottom: 14, pointerEvents: 'none' }}>
        <GlassSurface radius={radius.media}>
          <View className="px-4 py-4">
            <Text className="text-[10px] font-semibold uppercase tracking-[1.5px] text-muted" numberOfLines={1}>
              {product.brand}
            </Text>
            <Text className="mt-1 text-[22px] font-medium text-ivory" numberOfLines={1}>
              {product.name}
            </Text>
            {product.showPrice && product.price ? (
              <Text className="mt-1 text-[15px] font-medium text-ivory">{formatMoney(product.price)}</Text>
            ) : null}
            <View
              accessibilityLabel={pending}
              style={{
                alignSelf: 'flex-start',
                marginTop: 10,
                borderRadius: radius.pill,
                borderWidth: 1,
                borderColor: palette.gold,
                backgroundColor: palette.overlay,
                paddingHorizontal: 8,
                paddingVertical: 4,
              }}
            >
              <Text style={{ color: palette.goldSoft, fontSize: 10, fontWeight: '600', letterSpacing: 0.6 }}>
                {product.cashbackBadge}
              </Text>
            </View>
          </View>
        </GlassSurface>
      </View>
    </MediaCard>
  );
}
