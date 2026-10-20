import { useIsFocused } from 'expo-router';
import { Search, ShoppingBag } from 'lucide-react-native';
import { useMemo, useRef, useState, type ReactNode } from 'react';
import { FlatList, View, type ViewToken } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { EmptyHero } from '@/components/ui/empty-hero';
import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { DropSlide } from '@/features/feed/drop-hero';
import { DupeSearchBar } from '@/features/feed/dupe-search-bar';
import {
  dropMatches,
  feedDrops,
  feedProducts,
  productMatches,
  type FeedDrop,
  type FeedProduct,
} from '@/features/feed/fixtures';
import { ProductCard } from '@/features/feed/product-card';
import { useNow } from '@/features/feed/use-now';
import { t } from '@/lib/i18n';
import { useDupeSearchStore } from '@/stores/dupe-search';
import { palette, radius } from '@/theme/tokens';

type Props = {
  onOpenCart: () => void;
  header?: ReactNode;
};

type FeedEntry =
  | { kind: 'drop'; id: string; drop: FeedDrop }
  | { kind: 'product'; id: string; product: FeedProduct };

export function FeedScreen({ onOpenCart, header }: Props) {
  const insets = useSafeAreaInsets();
  const focused = useIsFocused();
  const query = useDupeSearchStore((state) => state.query);
  const category = useDupeSearchStore((state) => state.category);
  const now = useNow(focused);
  const [pageHeight, setPageHeight] = useState(0);

  const entries = useMemo(() => {
    const products = feedProducts.filter((product) => productMatches(product, query, category));
    const drops = feedDrops.filter((drop) => dropMatches(drop, query, category));

    return [
      ...drops.map((drop) => ({ kind: 'drop' as const, id: `drop-${drop.id}`, drop })),
      ...products.map((product) => ({ kind: 'product' as const, id: product.id, product })),
    ];
  }, [category, query]);

  const [activeId, setActiveId] = useState(entries[0]?.id ?? '');

  const onViewableItemsChanged = useRef((info: { viewableItems: ViewToken[] }) => {
    const next = info.viewableItems.find((token) => token.isViewable)?.item as FeedEntry | undefined;
    if (next) {
      setActiveId(next.id);
    }
  }).current;
  const viewabilityConfig = useRef({ itemVisiblePercentThreshold: 80 }).current;

  return (
    <View className="flex-1 bg-ink">
      <View className="px-5 pb-3" style={{ paddingTop: insets.top + 10 }}>
        <View className="flex-row items-start gap-3">
          <View className="flex-1">
            <DupeSearchBar />
          </View>
          <GlassSurface radius={radius.pill}>
            <PressableScale
              accessibilityLabel={t('feed.cartA11y')}
              onPress={onOpenCart}
              className="h-12 w-12 items-center justify-center"
            >
              <ShoppingBag color={palette.ivory} size={18} strokeWidth={1.5} />
            </PressableScale>
          </GlassSurface>
        </View>
        {header}
      </View>

      <View
        className="flex-1"
        onLayout={(event) => {
          const next = Math.round(event.nativeEvent.layout.height);
          setPageHeight((current) => (current === next ? current : next));
        }}
      >
        {pageHeight > 0 ? (
          <FlatList
            data={entries}
            decelerationRate="fast"
            disableIntervalMomentum
            extraData={activeId}
            getItemLayout={(_, index) => ({
              length: pageHeight,
              offset: pageHeight * index,
              index,
            })}
            initialNumToRender={1}
            keyExtractor={(item) => item.id}
            keyboardShouldPersistTaps="handled"
            maxToRenderPerBatch={2}
            onViewableItemsChanged={onViewableItemsChanged}
            showsVerticalScrollIndicator={false}
            snapToAlignment="start"
            snapToInterval={pageHeight}
            viewabilityConfig={viewabilityConfig}
            windowSize={3}
            ListEmptyComponent={
              <View className="px-5 pt-4" style={{ height: pageHeight }}>
                <EmptyHero
                  body={t('feed.empty')}
                  eyebrow={t('feed.heroEyebrow')}
                  icon={Search}
                  title={t('feed.searchPlaceholder')}
                />
              </View>
            }
            renderItem={({ item }) => (
              <View style={{ height: pageHeight, paddingHorizontal: 16, paddingBottom: 96 }}>
                <FeedPage active={item.id === activeId} entry={item} now={now} />
              </View>
            )}
          />
        ) : null}
      </View>
    </View>
  );
}

function FeedPage({ entry, now, active }: { entry: FeedEntry; now: number; active: boolean }) {
  if (entry.kind === 'drop') {
    return <DropSlide drop={entry.drop} now={now} playing={active} />;
  }
  return <ProductCard product={entry.product} />;
}
