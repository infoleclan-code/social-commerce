import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { useIsFocused } from 'expo-router';
import { Clapperboard, ScanLine, Users } from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, Text, View, type FlatList, type NativeScrollEvent, type NativeSyntheticEvent, type ViewToken } from 'react-native';
import Animated, { useAnimatedScrollHandler, useSharedValue } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import type { FeedMode, FeedPost } from '@/api/contracts';
import { queryKeys } from '@/api/keys';
import { checkoutProduct, fetchActivity, fetchLiveDrop, fetchRewards, authorizeSquad, createGift, openTryOnPoll, recordWatch, renderTryOn, reserveDrop } from '@/api/social-commerce';
import { EmptyHero } from '@/components/ui/empty-hero';
import { PressableScale } from '@/components/ui/pressable-scale';
import { ReelItem } from '@/features/reels/reel-item';
import { SquadShareModal, type SquadHold } from '@/features/reels/squad-share-modal';
import { useReelPlaybackStore } from '@/features/reels/reel-playback-store';
import { completePayment, type PaymentResolution } from '@/features/payments/complete-payment';
import { expectedTotalMinor } from '@/features/payments/expected-total';
import { changedPrice, isDefinite, isDefiniteFailure, purchaseErrorCopy, resolutionCopy } from '@/features/payments/purchase-copy';
import { feedPosts, useEngagementSync, useHashtagPosts, useLikeToggle, useReelsFeed, useSharePost, useUserPosts } from '@/features/reels/use-reels';
import { playSelection } from '@/lib/feedback';
import { IntentKeys, newIdempotencyKey } from '@/lib/idempotency';
import { t, type MessageKey } from '@/lib/i18n';
import { formatMoney, type DisplayMoney } from '@/lib/money';
import { useAppActive } from '@/lib/use-app-active';
import { useCommentsSheetStore } from '@/stores/comments-sheet';
import { palette, radius } from '@/theme/tokens';

type Props = {
  /** When set, the pager plays this profile's reels instead of For You / Following. */
  authorId?: string | null;
  /** When set, the pager plays reels that carry this hashtag. */
  tag?: string | null;
  initialPostId?: string | null;
  onOpenAuthor: (authorId: string) => void;
  onOpenTag: (tag: string) => void;
  onScan: () => void;
  onCloseAuthorFeed?: () => void;
};

const MODES: readonly { mode: FeedMode; label: MessageKey }[] = [
  { mode: 'for_you', label: 'reels.forYou' },
  { mode: 'following', label: 'reels.following' },
];

/** A page counts as "the one on screen" once 60% of it is visible. */
const VIEWABILITY = { itemVisiblePercentThreshold: 60, minimumViewTime: 0 } as const;
/** Upcoming posters to warm. The next video itself is prefetched by its paused player. */
const PREFETCH_AHEAD = 3;
/** Video decoders: the snapped page plus one on either side. */
const PLAYER_RADIUS = 1;
const HEADER_SPACE = 56;
const BOTTOM_GAP = 12;
/**
 * The Reels feed: full-screen vertical paging over GET /feed (keyset cursors, For You and Following).
 *
 * Playback: the page that snaps into place autoplays. Only that page and its two neighbours
 * mount a player (the neighbours stay paused and fill the video cache). Farther cells show the
 * poster. Posters for the next few reels are prefetched. Everything pauses when the tab loses
 * focus or the app leaves the foreground.
 */
export function InfiniteFeedScreen({ authorId = null, tag = null, initialPostId = null, onOpenAuthor, onOpenTag, onScan, onCloseAuthorFeed }: Props) {
  const insets = useSafeAreaInsets();
  const queryClient = useQueryClient();
  const focused = useIsFocused();
  const appActive = useAppActive();
  const [mode, setMode] = useState<FeedMode>('for_you');
  const [pageHeight, setPageHeight] = useState(0);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [pausedId, setPausedId] = useState<string | null>(null);
  const muted = useReelPlaybackStore((state) => state.muted);
  const toggleMuted = useReelPlaybackStore((state) => state.toggleMuted);
  const openComments = useCommentsSheetStore((state) => state.open);
  const listRef = useRef<FlatList<FeedPost>>(null);
  const jumped = useRef<string | null>(null);
  const filtered = tag !== null || authorId !== null;
  const globalFeed = useReelsFeed(mode, !filtered);
  const authorFeed = useUserPosts(tag === null ? authorId : null);
  const tagFeed = useHashtagPosts(tag);
  const feed = tag !== null ? tagFeed : authorId !== null ? authorFeed : globalFeed;
  // `mutate` is stable across renders: the callbacks below keep ReelItem's memo intact.
  const { mutate: toggleLike } = useLikeToggle();
  const share = useSharePost();
  const { mutate: sharePostMutate } = share;
  const scrollY = useSharedValue(0);

  const posts = feedPosts(feed.data);
  const currentId = activeId !== null && posts.some((post) => post.id === activeId) ? activeId : (posts[0]?.id ?? null);
  const currentIndex = Math.max(0, posts.findIndex((post) => post.id === currentId));
  const live = focused && appActive;
  useEngagementSync(currentId, live);
  const sharingId = share.isPending ? (share.variables?.id ?? null) : null;

  const onScroll = useAnimatedScrollHandler((event) => {
    scrollY.set(event.contentOffset.y);
  });

  // VirtualizedList re-reports the same visible item whenever extraData changes (a like, a pause), so only
  // a real page change may clear the tap-to-pause state.
  const shownId = useRef<string | null>(null);
  const onViewableItemsChanged = useRef(({ viewableItems }: { viewableItems: ViewToken<FeedPost>[] }) => {
    const next = viewableItems.find((token) => token.isViewable)?.item;
    if (!next || next.id === shownId.current) {
      return;
    }
    shownId.current = next.id;
    setActiveId(next.id);
    setPausedId(null);
  }).current;

  const settleOnPage = useCallback(
    (offsetY: number) => {
      if (pageHeight <= 0 || posts.length === 0) {
        return;
      }
      const index = Math.min(posts.length - 1, Math.max(0, Math.round(offsetY / pageHeight)));
      const post = posts[index];
      if (!post || post.id === shownId.current) {
        return;
      }
      shownId.current = post.id;
      setActiveId(post.id);
      setPausedId(null);
    },
    [pageHeight, posts],
  );

  const onMomentumScrollEnd = useCallback(
    (event: NativeSyntheticEvent<NativeScrollEvent>) => {
      settleOnPage(event.nativeEvent.contentOffset.y);
    },
    [settleOnPage],
  );

  // A stale cursor (deploy changed its format, or the post it pointed at is gone): start the feed over.
  const cursorRejected = feed.error instanceof ApiError && feed.error.code === 'INVALID_CURSOR';
  useEffect(() => {
    if (cursorRejected) {
      void queryClient.resetQueries({ queryKey: queryKeys.reels.feed(mode) });
    }
  }, [cursorRejected, mode, queryClient]);

  // Posters for the next reels. The next video file is prefetched by the paused neighbour player.
  const upcoming = posts
    .slice(currentIndex, currentIndex + 1 + PREFETCH_AHEAD)
    .flatMap((post) => [post.media?.thumbnail_url, post.media?.type === 'image' ? post.media.url : null])
    .filter((uri): uri is string => typeof uri === 'string' && uri.length > 0);
  const upcomingKey = upcoming.join('|');
  useEffect(() => {
    if (upcomingKey) {
      void Image.prefetch(upcomingKey.split('|'), 'memory-disk').catch(() => false);
    }
  }, [upcomingKey]);

  const onLike = useCallback((post: FeedPost, nextLiked: boolean) => toggleLike({ postId: post.id, like: nextLiked }), [toggleLike]);
  const onComments = useCallback((post: FeedPost) => openComments(post.id), [openComments]);
  const onShare = useCallback((post: FeedPost) => sharePostMutate(post), [sharePostMutate]);
  const onTogglePause = useCallback((postId: string) => setPausedId((current) => (current === postId ? null : postId)), []);

  useEffect(() => {
    if (authorId === null || initialPostId === null || pageHeight === 0) {
      return;
    }
    if (posts.some((post) => post.id === initialPostId)) {
      if (jumped.current === initialPostId) {
        return;
      }
      const index = posts.findIndex((post) => post.id === initialPostId);
      jumped.current = initialPostId;
      setActiveId(initialPostId);
      listRef.current?.scrollToIndex({ index, animated: false });
      return;
    }
    if (feed.hasNextPage && !feed.isFetchingNextPage) {
      void feed.fetchNextPage();
    }
  }, [authorId, feed, initialPostId, pageHeight, posts]);

  const [notice, setNotice] = useState<string | null>(null);
  const [squadHold, setSquadHold] = useState<SquadHold | null>(null);
  const [giftLink, setGiftLink] = useState<string | null>(null);
  // One Idempotency-Key per open intent (per post): reused while the outcome is unknown, dropped once definite.
  const buyKeys = useRef(new IntentKeys('buy'));
  const squadKeys = useRef(new IntentKeys('squad'));
  const giftKeys = useRef(new IntentKeys('gift'));
  /** A 409 PRICE_CHANGED the buyer has seen: the next "Buy" on that post confirms this total. */
  const [priceConfirm, setPriceConfirm] = useState<{ postId: string; total: DisplayMoney } | null>(null);
  const [tryOnUrl, setTryOnUrl] = useState<string | null>(null);
  const [shopBusyId, setShopBusyId] = useState<string | null>(null);
  const postsRef = useRef(posts);
  postsRef.current = posts;
  const rewards = useQuery({
    queryKey: queryKeys.rewards.me,
    queryFn: ({ signal }) => fetchRewards(signal),
    enabled: live,
    staleTime: 15_000,
  });
  const coupon = rewards.data?.coupons[0] ?? null;
  const activity = useQuery({
    queryKey: queryKeys.activity.live,
    queryFn: ({ signal }) => fetchActivity(signal),
    enabled: live && !filtered,
    refetchInterval: live ? 8_000 : false,
  });
  const latestActivity = activity.data?.[0] ?? null;
  const [hiddenActivityId, setHiddenActivityId] = useState<string | null>(null);
  const proof =
    latestActivity && latestActivity.id !== hiddenActivityId
      ? t('shop.proof', { name: latestActivity.username, product: latestActivity.product_title })
      : null;

  useEffect(() => {
    if (!latestActivity || latestActivity.id === hiddenActivityId) {
      return;
    }
    const id = latestActivity.id;
    const timer = setTimeout(() => setHiddenActivityId(id), 5_000);
    return () => clearTimeout(timer);
  }, [hiddenActivityId, latestActivity]);

  // Read-only: a live flash drop refreshes the feed's offers. Drops are scheduled by the server, never
  // started by a client (the old POST let any viewer create one).
  const liveDrop = useQuery({
    queryKey: ['drops', 'live'],
    queryFn: ({ signal }) => fetchLiveDrop(signal),
    enabled: live && !filtered,
    staleTime: 30_000,
  });
  const liveDropId = liveDrop.data?.drop?.id ?? null;
  useEffect(() => {
    if (liveDropId !== null) {
      void queryClient.invalidateQueries({ queryKey: queryKeys.reels.all });
    }
  }, [liveDropId, queryClient]);

  useEffect(() => {
    if (!live || currentId === null) {
      return;
    }
    const timer = setTimeout(() => {
      const post = postsRef.current.find((item) => item.id === currentId);
      if (!post) {
        return;
      }
      const duration = Math.max(1, post.media?.duration_ms ?? 4_000);
      void recordWatch(post.id, duration, duration).catch(() => undefined);
    }, 4_000);
    return () => clearTimeout(timer);
  }, [currentId, live]);

  /** Ends a card purchase after the API answered: 3-D Secure, sync, then one line of copy. */
  const finishPayment = useCallback(
    async (orderId: string, step: Parameters<typeof completePayment>[1], total: DisplayMoney | null): Promise<PaymentResolution> => {
      const resolution = await completePayment(orderId, step);
      setNotice(resolutionCopy(resolution, total));
      void queryClient.invalidateQueries({ queryKey: queryKeys.orders.all });
      return resolution;
    },
    [queryClient],
  );

  const onBuy = useCallback(
    async (post: FeedPost) => {
      const offer = post.shop;
      const product = offer?.product;
      if (!offer || !product || shopBusyId !== null) {
        return;
      }
      setShopBusyId(post.id);
      setTryOnUrl(null);
      const confirmed = priceConfirm?.postId === post.id ? priceConfirm.total : null;
      const expected = confirmed ? confirmed.amount_minor : expectedTotalMinor(offer, coupon?.discount_bps ?? 0, 1);
      try {
        const order = await checkoutProduct({
          productId: product.id,
          postId: post.id,
          squadId: null,
          couponId: coupon?.id ?? null,
          quantity: 1,
          expectedTotalMinor: expected,
          idempotencyKey: buyKeys.current.for(post.id),
        });
        setPriceConfirm(null);
        const resolution = await finishPayment(order.id, order, order.total);
        if (isDefinite(resolution)) {
          buyKeys.current.settle(post.id);
        }
        void queryClient.invalidateQueries({ queryKey: queryKeys.reels.all });
        void queryClient.invalidateQueries({ queryKey: queryKeys.rewards.me });
      } catch (error) {
        const total = changedPrice(error);
        if (total) {
          // Nothing was charged: show the new total and let the next tap confirm it (a new request).
          setPriceConfirm({ postId: post.id, total });
          setNotice(t('purchase.priceChanged', { total: formatMoney(total) }));
        } else {
          setNotice(purchaseErrorCopy(error));
        }
        if (isDefiniteFailure(error)) {
          buyKeys.current.settle(post.id);
        }
        if (error instanceof ApiError && error.code === 'COUPON_INVALID') {
          void queryClient.invalidateQueries({ queryKey: queryKeys.rewards.me });
        }
      } finally {
        setShopBusyId(null);
      }
    },
    [coupon?.discount_bps, coupon?.id, finishPayment, priceConfirm, queryClient, shopBusyId],
  );

  const onSquad = useCallback(
    async (post: FeedPost) => {
      const product = post.shop?.product;
      if (!product || shopBusyId !== null) {
        return;
      }
      setShopBusyId(post.id);
      try {
        const held = await authorizeSquad(product.id, post.id, squadKeys.current.for(post.id));
        const resolution = await completePayment(held.order_id, held);
        if (isDefinite(resolution)) {
          squadKeys.current.settle(post.id);
        }
        if (resolution.kind === 'held' || resolution.kind === 'paid') {
          setSquadHold({
            post,
            solo: held.solo,
            squad: held.squad,
            expiresAt: held.expires_at ?? new Date(Date.now() + 24 * 3_600_000).toISOString(),
            members: held.members,
          });
        } else {
          setNotice(resolutionCopy(resolution, held.authorized));
        }
      } catch (error) {
        setNotice(purchaseErrorCopy(error));
        if (isDefiniteFailure(error)) {
          squadKeys.current.settle(post.id);
        }
      } finally {
        setShopBusyId(null);
      }
    },
    [shopBusyId],
  );

  const onHold = useCallback(
    async (post: FeedPost) => {
      const dropId = post.shop?.drop_hour?.id;
      if (!dropId || shopBusyId !== null) {
        return;
      }
      setShopBusyId(post.id);
      try {
        // The server keeps one live reservation per buyer and drop, so a repeat answers with the same hold.
        await reserveDrop(dropId, newIdempotencyKey('hold'));
        setNotice(t('shop.dropHeld'));
        void queryClient.invalidateQueries({ queryKey: queryKeys.reels.all });
      } catch (error) {
        setNotice(purchaseErrorCopy(error));
      } finally {
        setShopBusyId(null);
      }
    },
    [queryClient, shopBusyId],
  );

  const onTryOn = useCallback(async (post: FeedPost) => {
    const product = post.shop?.product;
    if (!product) {
      return;
    }
    try {
      const plate = await renderTryOn(product.id);
      const poll = await openTryOnPoll(plate.id);
      setTryOnUrl(plate.image_url);
      setNotice(`${t('tryon.ready')} ${poll.url}`);
    } catch (error) {
      setNotice(purchaseErrorCopy(error));
    }
  }, []);

  const onGift = useCallback(
    async (post: FeedPost) => {
      const product = post.shop?.product;
      if (!product || shopBusyId !== null) {
        return;
      }
      setShopBusyId(post.id);
      try {
        const gift = await createGift(product.id, post.id, giftKeys.current.for(post.id));
        const resolution = await completePayment(gift.order_id, gift);
        if (isDefinite(resolution)) {
          giftKeys.current.settle(post.id);
        }
        if (resolution.kind === 'held' || resolution.kind === 'paid') {
          // The link is claimable once the payment is captured; sharing it now is safe.
          setGiftLink(gift.url);
          setNotice(t('purchase.giftReady'));
        } else {
          setNotice(resolutionCopy(resolution, null));
        }
      } catch (error) {
        setNotice(purchaseErrorCopy(error));
        if (isDefiniteFailure(error)) {
          giftKeys.current.settle(post.id);
        }
      } finally {
        setShopBusyId(null);
      }
    },
    [shopBusyId],
  );

  function switchMode(next: FeedMode) {
    if (next === mode) {
      return;
    }
    playSelection();
    shownId.current = null;
    setMode(next);
    setActiveId(null);
    setPausedId(null);
    scrollY.set(0);
  }

  const refreshing = feed.isRefetching && !feed.isFetchingNextPage;
  const emptyKey: MessageKey = tag !== null ? 'explore.empty' : authorId !== null ? 'profile.emptyTheirs' : mode === 'for_you' ? 'reels.emptyForYou' : 'reels.emptyFollowing';

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink }}>
      <View
        onLayout={(event) => {
          const next = Math.round(event.nativeEvent.layout.height);
          setPageHeight((current) => (current === next ? current : next));
        }}
        style={{ flex: 1 }}
      >
        {pageHeight > 0 && posts.length > 0 ? (
          <Animated.FlatList
            ref={listRef}
            data={posts}
            decelerationRate="fast"
            disableIntervalMomentum
            extraData={`${currentId}|${currentIndex}|${pausedId}|${muted}|${live}|${sharingId}`}
            getItemLayout={(_data, index) => ({ length: pageHeight, offset: pageHeight * index, index })}
            initialNumToRender={3}
            key={tag ?? authorId ?? mode}
            keyExtractor={(item) => item.id}
            maxToRenderPerBatch={3}
            onEndReached={() => {
              if (feed.hasNextPage && !feed.isFetchingNextPage) {
                void feed.fetchNextPage();
              }
            }}
            onEndReachedThreshold={2}
            onMomentumScrollEnd={onMomentumScrollEnd}
            onScroll={onScroll}
            onViewableItemsChanged={onViewableItemsChanged}
            pagingEnabled
            refreshControl={
              <RefreshControl
                colors={[palette.gold]}
                onRefresh={() => {
                  void feed.refetch();
                }}
                progressViewOffset={insets.top + HEADER_SPACE}
                refreshing={refreshing}
                tintColor={palette.gold}
              />
            }
            renderItem={({ item, index }) => (
              <ReelItem
                active={item.id === currentId}
                bottomInset={BOTTOM_GAP}
                height={pageHeight}
                index={index}
                live={live}
                loadPlayer={Math.abs(index - currentIndex) <= PLAYER_RADIUS}
                muted={muted}
                onComments={onComments}
                onLike={onLike}
                onOpenAuthor={() => onOpenAuthor(item.viewer.is_author ? '' : item.author.id)}
                onOpenTag={onOpenTag}
                onBuy={onBuy}
                onGift={onGift}
                onHold={onHold}
                onSquad={onSquad}
                onTryOn={onTryOn}
                shopBusy={item.id === shopBusyId}
                couponLabel={coupon?.label ?? null}
                onShare={onShare}
                onToggleMute={toggleMuted}
                onTogglePause={onTogglePause}
                paused={item.id === pausedId}
                post={item}
                scrollY={scrollY}
                sharing={item.id === sharingId}
              />
            )}
            scrollEventThrottle={16}
            showsVerticalScrollIndicator={false}
            snapToAlignment="start"
            snapToInterval={pageHeight}
            viewabilityConfig={VIEWABILITY}
            windowSize={5}
          />
        ) : null}

        {pageHeight > 0 && posts.length === 0 ? (
          <FeedState
            emptyBody={t(emptyKey)}
            error={feed.error}
            loading={feed.isPending || cursorRejected}
            onRetry={() => {
              void feed.refetch();
            }}
            topInset={insets.top + HEADER_SPACE + 24}
          />
        ) : null}

        {feed.isFetchingNextPage ? (
          <View style={{ position: 'absolute', left: 0, right: 0, bottom: 18, alignItems: 'center', pointerEvents: 'none' }}>
            <ActivityIndicator color={palette.goldSoft} />
          </View>
        ) : null}
      </View>

      <View style={{ position: 'absolute', top: insets.top + 6, left: 12, right: 12, height: 44, flexDirection: 'row', alignItems: 'center' }}>
        {filtered && onCloseAuthorFeed ? (
          <Pressable accessibilityLabel={tag !== null ? t('explore.back') : t('reels.backToProfile')} hitSlop={8} onPress={onCloseAuthorFeed} style={{ width: 44, height: 44, alignItems: 'center', justifyContent: 'center' }}>
            <Text style={{ color: palette.ivory, fontSize: 22, fontWeight: '600' }}>‹</Text>
          </Pressable>
        ) : (
          <View style={{ width: 44 }} />
        )}
        <View accessibilityRole="tablist" style={{ flex: 1, flexDirection: 'row', justifyContent: 'center', gap: 22 }}>
          {tag !== null ? (
            <Text style={{ color: palette.ivory, fontSize: 16, fontWeight: '700' }}>{t('reels.tag', { tag })}</Text>
          ) : authorId === null ? (
            MODES.map((item) => {
                const selected = item.mode === mode;
                return (
                  <Pressable
                    accessibilityLabel={t('reels.modeA11y', { mode: t(item.label) })}
                    accessibilityRole="tab"
                    accessibilityState={{ selected }}
                    hitSlop={8}
                    key={item.mode}
                    onPress={() => switchMode(item.mode)}
                  >
                    <View style={{ alignItems: 'center', gap: 4 }}>
                      <Text
                        style={{
                          color: selected ? palette.ivory : palette.muted,
                          fontSize: 16,
                          fontWeight: selected ? '700' : '600',
                          textShadowColor: 'rgba(0, 0, 0, 0.55)',
                          textShadowRadius: 6,
                        }}
                      >
                        {t(item.label)}
                      </Text>
                      <View style={{ width: 22, height: 2.5, borderRadius: 2, backgroundColor: selected ? palette.goldSoft : 'transparent' }} />
                    </View>
                  </Pressable>
                );
              })
          ) : (
            <Text style={{ color: palette.ivory, fontSize: 16, fontWeight: '700' }}>{t('profile.posts')}</Text>
          )}
        </View>
        <Pressable accessibilityLabel={t('shop.scan')} hitSlop={8} onPress={onScan} style={{ width: 44, height: 44, alignItems: 'center', justifyContent: 'center' }}>
          <ScanLine color={palette.ivory} size={22} strokeWidth={1.75} />
        </Pressable>
      </View>
      {proof ? (
        <View style={{ position: 'absolute', top: insets.top + 56, alignSelf: 'center', maxWidth: 320, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: 'rgba(12, 10, 8, 0.82)' }}>
          <Text style={{ color: palette.ivory, fontSize: 13, fontWeight: '600' }}>{proof}</Text>
        </View>
      ) : null}
      {notice ? (
        <Pressable
          onPress={() => {
            setNotice(null);
            setTryOnUrl(null);
          }}
          style={{ position: 'absolute', left: 16, right: 16, bottom: insets.bottom + 24, padding: 12, borderRadius: 16, backgroundColor: 'rgba(12, 10, 8, 0.9)', flexDirection: 'row', gap: 10, alignItems: 'center' }}
        >
          {tryOnUrl ? <Image contentFit="cover" source={{ uri: tryOnUrl }} style={{ width: 48, height: 64, borderRadius: 8 }} /> : null}
          <Text style={{ color: palette.ivory, fontSize: 14, flex: 1 }}>{notice}</Text>
        </Pressable>
      ) : null}
      {squadHold ? <SquadShareModal hold={squadHold} onClose={() => setSquadHold(null)} /> : null}
      {giftLink ? (
        <View style={{ position: 'absolute', left: 12, right: 12, bottom: insets.bottom + 24, zIndex: 30, padding: 16, gap: 8, borderRadius: 16, backgroundColor: '#141210' }}>
          <Text style={{ color: palette.ivory, fontSize: 16, fontWeight: '700' }}>{t('gift.title')}</Text>
          <Text style={{ color: palette.muted, fontSize: 13 }}>{t('gift.share')}</Text>
          <Text style={{ color: palette.goldSoft, fontSize: 13 }}>{giftLink}</Text>
          <Pressable onPress={() => setGiftLink(null)}><Text style={{ color: palette.muted }}>{t('shop.close')}</Text></Pressable>
        </View>
      ) : null}
    </View>
  );
}

function FeedState({
  loading,
  error,
  emptyBody,
  topInset,
  onRetry,
}: {
  loading: boolean;
  error: unknown;
  emptyBody: string;
  topInset: number;
  onRetry: () => void;
}) {
  if (loading) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={palette.goldSoft} size="large" />
      </View>
    );
  }

  const signedOut = error instanceof ApiError && error.status === 401;
  const rateLimited = error instanceof ApiError && error.status === 429;
  const failed = error !== null && error !== undefined;
  const title = failed ? (signedOut ? t('reels.signedOutTitle') : t('reels.errorTitle')) : t('reels.emptyTitle');
  const body = failed ? (signedOut ? t('reels.signedOutBody') : rateLimited ? t('reels.rateLimitedBody') : t('reels.errorBody')) : emptyBody;

  return (
    <View style={{ flex: 1, paddingHorizontal: 20, paddingTop: topInset, gap: 16 }}>
      <EmptyHero body={body} eyebrow={t('reels.emptyEyebrow')} icon={failed ? Users : Clapperboard} title={title} />
      {failed && !signedOut ? (
        <PressableScale accessibilityLabel={t('reels.retry')} block onPress={onRetry}>
          <View style={{ minHeight: 48, borderRadius: radius.pill, backgroundColor: palette.ivory, alignItems: 'center', justifyContent: 'center' }}>
            <Text style={{ color: palette.ink, fontSize: 15, fontWeight: '700' }}>{t('reels.retry')}</Text>
          </View>
        </PressableScale>
      ) : null}
    </View>
  );
}
