import { useIsFocused } from 'expo-router';
import { Search } from 'lucide-react-native';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, Text, TextInput, View, type ViewToken } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { EmptyHero } from '@/components/ui/empty-hero';
import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { DupeSearchBar } from '@/features/feed/dupe-search-bar';
import { feedProducts, productMatches, type FeedProduct } from '@/features/feed/fixtures';
import { useNow } from '@/features/feed/use-now';
import { ReelPage } from '@/features/drops/reel-page';
import { t } from '@/lib/i18n';
import { useDupeScanStore } from '@/stores/dupe-scan';
import { useDupeSearchStore } from '@/stores/dupe-search';
import { useReelSocialStore } from '@/stores/reel-social';
import { palette, radius } from '@/theme/tokens';

const EMPTY_COMMENTS: string[] = [];
const VIEWABILITY = { itemVisiblePercentThreshold: 80 };

export function DropsScreen() {
  const insets = useSafeAreaInsets();
  const focused = useIsFocused();
  const now = useNow(focused);
  const [pageHeight, setPageHeight] = useState(0);
  const [activeId, setActiveId] = useState(feedProducts[0]?.id ?? '');
  const [commentFor, setCommentFor] = useState<string | null>(null);
  const [draft, setDraft] = useState('');
  const comments = useReelSocialStore((state) =>
    commentFor ? (state.comments[commentFor] ?? EMPTY_COMMENTS) : EMPTY_COMMENTS,
  );
  const addComment = useReelSocialStore((state) => state.addComment);
  const query = useDupeSearchStore((state) => state.query);
  const category = useDupeSearchStore((state) => state.category);
  const products = useMemo(
    () => feedProducts.filter((product) => productMatches(product, query, category)),
    [category, query],
  );
  const bottomInset = insets.bottom + 108;

  useEffect(() => {
    if (focused) {
      return;
    }
    useDupeScanStore.getState().close();
  }, [focused]);

  const resolvedId = products.some((product) => product.id === activeId) ? activeId : (products[0]?.id ?? '');
  const onViewableItemsChanged = useCallback((info: { viewableItems: ViewToken[] }) => {
    const next = info.viewableItems.find((token) => token.isViewable)?.item as FeedProduct | undefined;
    if (next) {
      setActiveId(next.id);
    }
  }, []);

  return (
    <View className="flex-1 bg-ink">
      <View
        className="flex-1"
        onLayout={(event) => {
          const next = Math.round(event.nativeEvent.layout.height);
          setPageHeight((current) => (current === next ? current : next));
        }}
      >
        {pageHeight > 0 ? (
          <FlatList
            data={products}
            decelerationRate="fast"
            disableIntervalMomentum
            extraData={resolvedId}
            getItemLayout={(_, index) => ({
              length: pageHeight,
              offset: pageHeight * index,
              index,
            })}
            initialNumToRender={1}
            keyExtractor={(item) => item.id}
            maxToRenderPerBatch={1}
            onViewableItemsChanged={onViewableItemsChanged}
            viewabilityConfig={VIEWABILITY}
            pagingEnabled
            renderItem={({ item }) => (
              <View style={{ height: pageHeight }}>
                <ReelPage
                  active={item.id === resolvedId}
                  bottomInset={bottomInset}
                  now={now}
                  onComment={setCommentFor}
                  product={item}
                  topInset={insets.top + 68}
                />
              </View>
            )}
            showsVerticalScrollIndicator={false}
            snapToAlignment="start"
            snapToInterval={pageHeight}
            windowSize={3}
            ListEmptyComponent={
              <View className="px-5 pt-24" style={{ height: pageHeight }}>
                <EmptyHero
                  body={t('feed.empty')}
                  eyebrow={t('drops.eyebrow')}
                  icon={Search}
                  title={t('feed.searchPlaceholder')}
                />
              </View>
            }
          />
        ) : null}
      </View>

      <View style={{ position: 'absolute', top: insets.top + 8, left: 16, right: 16 }}>
        <DupeSearchBar />
      </View>

      {commentFor ? (
        <View style={{ position: 'absolute', left: 12, right: 12, bottom: insets.bottom + 88 }}>
          <GlassSurface radius={radius.panel}>
            <View className="px-4 py-4">
              <View className="flex-row items-center justify-between">
                <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">
                  {t('drops.commentTitle')}
                </Text>
                <PressableScale accessibilityLabel={t('feed.closeA11y')} onPress={() => setCommentFor(null)}>
                  <Text className="text-[13px] text-ivory">{t('feed.closeA11y')}</Text>
                </PressableScale>
              </View>
              {comments.length === 0 ? (
                <Text className="mt-3 text-[14px] text-muted">{t('drops.commentEmpty')}</Text>
              ) : (
                comments.map((comment, index) => (
                  <Text className="mt-3 text-[14px] leading-5 text-ivory" key={`${index}-${comment}`}>
                    {comment}
                  </Text>
                ))
              )}
              <View className="mt-4 flex-row items-center gap-3">
                <TextInput
                  accessibilityLabel={t('drops.commentPlaceholder')}
                  onChangeText={setDraft}
                  placeholder={t('drops.commentPlaceholder')}
                  placeholderTextColor={palette.faint}
                  style={{
                    flex: 1,
                    color: palette.ivory,
                    borderWidth: 1,
                    borderColor: palette.line,
                    borderRadius: radius.pill,
                    paddingHorizontal: 14,
                    paddingVertical: 10,
                    backgroundColor: palette.overlay,
                  }}
                  value={draft}
                />
                <PressableScale
                  accessibilityLabel={t('drops.commentSend')}
                  onPress={() => {
                    addComment(commentFor, draft);
                    setDraft('');
                  }}
                >
                  <Text className="text-[14px] font-semibold text-gold-soft">{t('drops.commentSend')}</Text>
                </PressableScale>
              </View>
            </View>
          </GlassSurface>
        </View>
      ) : null}
    </View>
  );
}
