import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { Heart, Play } from 'lucide-react-native';
import { memo, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View, type AccessibilityActionEvent } from 'react-native';
import { Gesture, GestureDetector } from 'react-native-gesture-handler';
import Animated, {
  cancelAnimation,
  Easing,
  interpolate,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withRepeat,
  withSequence,
  withTiming,
  type SharedValue,
} from 'react-native-reanimated';

import type { FeedPost } from '@/api/contracts';
import { api, json } from '@/api/client';
import { ShopCard } from '@/features/reels/shop-card';
import { OneTapBuyCard } from '@/features/one-tap/one-tap-buy-card';
import { ActionRail } from '@/features/reels/action-rail';
import { ReelVideo } from '@/features/reels/reel-video';
import { createTapClassifier } from '@/features/reels/tap-classifier';
import { t } from '@/lib/i18n';
import { palette, scrim } from '@/theme/tokens';

type Props = {
  post: FeedPost;
  index: number;
  height: number;
  /** The page snapped on screen. Only this page autoplays. */
  active: boolean;
  /** Current page and its neighbours mount a player. Farther pages stay on the poster. */
  loadPlayer: boolean;
  /** The screen is focused and the app is in the foreground. */
  live: boolean;
  /** Paused by a tap (only the active page can be). */
  paused: boolean;
  muted: boolean;
  sharing: boolean;
  scrollY: SharedValue<number>;
  bottomInset: number;
  onLike: (post: FeedPost, like: boolean) => void;
  onComments: (post: FeedPost) => void;
  onOpenAuthor: () => void;
  onOpenTag: (tag: string) => void;
  onBuy: (post: FeedPost) => void;
  onSquad: (post: FeedPost) => void;
  onHold: (post: FeedPost) => void;
  onGift: (post: FeedPost) => void;
  onTryOn: (post: FeedPost) => void;
  shopBusy: boolean;
  couponLabel: string | null;
  onShare: (post: FeedPost) => void;
  onTogglePause: (postId: string) => void;
  onToggleMute: () => void;
};

const FILL = { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 } as const;
const BURST_SIZE = 112;
/** Two taps closer than this are a double tap. */
const DOUBLE_TAP_MS = 260;

/*
 * 'box-none' lets taps on the empty parts of the overlay reach the media (pause, double-tap like).
 * It must come from StyleSheet.create: react-native-web can only express box-none as a CSS class.
 */
const styles = StyleSheet.create({
  passThrough: { pointerEvents: 'box-none' },
});

/**
 * One full-screen page of the feed: media, double-tap like, tap to pause, the action rail, the caption
 * and the persistent 1-Tap Buy card. Overlays fade and drift with the scroll position on the UI thread,
 * so a swipe never waits on React.
 */
export const ReelItem = memo(function ReelItem({
  post,
  index,
  height,
  active,
  loadPlayer,
  live,
  paused,
  muted,
  sharing,
  scrollY,
  bottomInset,
  onLike,
  onComments,
  onOpenAuthor,
  onOpenTag,
  onBuy,
  onSquad,
  onHold,
  onGift,
  onTryOn,
  shopBusy,
  couponLabel,
  onShare,
  onTogglePause,
  onToggleMute,
}: Props) {
  const progress = useSharedValue(0);
  const burst = useSharedValue(0);
  const burstX = useSharedValue(0);
  const burstY = useSharedValue(0);
  const reduceMotion = useReducedMotion();
  const [expanded, setExpanded] = useState(false);
  const [published, setPublished] = useState<{ postId: string; label: string } | null>(null);
  const syntheticLabel = published?.postId === post.id ? published.label : (post.synthetic?.label ?? null);
  const [tapClassifier] = useState(() => createTapClassifier(DOUBLE_TAP_MS));

  function publishTrack(kind: 'dub' | 'subtitle') {
    void api<{ data: { label: string } }>(`/feed/${post.id}/synthetic`, json('POST', { language: 'en', kind })).then((body) => {
      setPublished({ postId: post.id, label: body.data.label });
    });
  }

  const media = post.media;
  const isVideo = media?.type === 'video';
  const playing = active && live && !paused;

  function likeFromDoubleTap() {
    // A double tap only ever likes, like every short-video app; un-liking is the heart button.
    if (!post.viewer.liked) {
      onLike(post, true);
    }
  }

  function togglePause() {
    if (isVideo) {
      onTogglePause(post.id);
    }
  }

  function doubleTapLike(x: number, y: number) {
    burstX.set(x);
    burstY.set(y);
    burst.set(0);
    burst.set(
      reduceMotion
        ? withSequence(withTiming(1, { duration: 90 }), withTiming(0, { duration: 160 }))
        : withTiming(1, { duration: 720, easing: Easing.out(Easing.cubic) }),
    );
    likeFromDoubleTap();
  }

  useEffect(() => {
    return () => {
      tapClassifier.dispose();
    };
  }, [tapClassifier]);

  // One tap recognizer; createTapClassifier decides single (pause / play) versus double (like).
  const taps = Gesture.Tap()
    .maxDuration(280)
    .maxDistance(18)
    .runOnJS(true)
    .onEnd((event, success) => {
      if (success) {
        tapClassifier.tap(event.x, event.y, { single: togglePause, double: doubleTapLike });
      }
    });

  const overlayStyle = useAnimatedStyle(() => {
    const offset = scrollY.get() - index * height;
    const distance = Math.abs(offset) / Math.max(1, height);
    return {
      opacity: interpolate(distance, [0, 0.55], [1, 0], 'clamp'),
      transform: [{ translateY: offset * 0.22 }],
    };
  });

  const burstStyle = useAnimatedStyle(() => {
    const value = burst.get();
    return {
      opacity: value === 0 ? 0 : interpolate(value, [0, 0.12, 0.7, 1], [0, 1, 1, 0]),
      transform: [
        { translateX: burstX.get() - BURST_SIZE / 2 },
        { translateY: burstY.get() - BURST_SIZE / 2 - value * 46 },
        { scale: interpolate(value, [0, 0.14, 0.28, 1], [0.35, 1.22, 1, 1.12]) },
        { rotate: `${interpolate(value, [0, 1], [-14, -6])}deg` },
      ],
    };
  });

  const progressStyle = useAnimatedStyle(() => ({ transform: [{ scaleX: progress.get() }] }));

  function onAccessibilityAction(event: AccessibilityActionEvent) {
    if (event.nativeEvent.actionName === 'activate') {
      togglePause();
    } else if (event.nativeEvent.actionName === 'like') {
      onLike(post, !post.viewer.liked);
    }
  }

  const author = post.author.display_name ?? post.author.username;

  return (
    <View style={{ height, backgroundColor: palette.ink, overflow: 'hidden' }}>
      {media && isVideo && loadPlayer ? (
        <ReelVideo active={active} muted={muted} playing={playing} posterUri={media.thumbnail_url} progress={progress} uri={media.url} />
      ) : (
        <ReelStill animate={active && live && !isVideo} uri={isVideo ? (media?.thumbnail_url ?? null) : (media?.url ?? post.product?.image_url ?? null)} />
      )}

      <LinearGradient colors={scrim.reel} locations={[0, 0.42, 1]} style={[FILL, { pointerEvents: 'none' }]} />

      <GestureDetector gesture={taps}>
        <View
          accessibilityActions={[
            { name: 'activate', label: playing ? t('reels.pauseA11y') : t('reels.playA11y') },
            { name: 'like', label: post.viewer.liked ? t('reels.unlike') : t('reels.like') },
          ]}
          accessibilityHint={t('reels.mediaA11y')}
          accessibilityLabel={post.caption ?? t('reels.mediaHint', { name: author })}
          accessible
          onAccessibilityAction={onAccessibilityAction}
          style={FILL}
        />
      </GestureDetector>

      <Animated.View
        style={[{ position: 'absolute', left: 0, top: 0, width: BURST_SIZE, height: BURST_SIZE, alignItems: 'center', justifyContent: 'center', pointerEvents: 'none' }, burstStyle]}
      >
        <Heart color={palette.goldSoft} fill={palette.goldSoft} size={92} strokeWidth={1.2} />
      </Animated.View>

      {isVideo && active && paused ? (
        <View style={[FILL, { alignItems: 'center', justifyContent: 'center', pointerEvents: 'none' }]}>
          <View
            accessibilityLabel={t('reels.paused')}
            style={{ width: 76, height: 76, borderRadius: 38, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(0, 0, 0, 0.38)' }}
          >
            <Play color={palette.ivory} fill={palette.ivory} size={32} strokeWidth={1.5} />
          </View>
        </View>
      ) : null}

      <Animated.View
        // The prop as well as the style: react-native-web drops a style-only box-none on animated views.
        pointerEvents="box-none"
        style={[styles.passThrough, { position: 'absolute', left: 12, right: 8, bottom: bottomInset, gap: 12 }, overlayStyle]}
      >
        <View style={[styles.passThrough, { flexDirection: 'row', alignItems: 'flex-end', gap: 10 }]}>
          <View style={{ flex: 1, minWidth: 0, gap: 6, paddingBottom: 6 }}>
            <Pressable accessibilityLabel={t('reels.openProfile', { handle: post.author.username })} accessibilityRole="button" onPress={onOpenAuthor}>
              <Text numberOfLines={1} selectable={false} style={{ color: palette.ivory, fontSize: 15, fontWeight: '700', textShadowColor: 'rgba(0,0,0,0.6)', textShadowRadius: 6 }}>
                {t('reels.author', { handle: post.author.username })}
              </Text>
            </Pressable>
            {syntheticLabel ? (
              <Text accessibilityRole="text" style={{ alignSelf: 'flex-start', overflow: 'hidden', color: palette.ink, backgroundColor: palette.gold, fontSize: 11, fontWeight: '700', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 999 }}>
                {syntheticLabel}
              </Text>
            ) : null}
            {post.viewer.is_author ? (
              <View style={{ flexDirection: 'row', gap: 8 }}>
                <Pressable accessibilityRole="button" onPress={() => { void publishTrack('subtitle'); }}>
                  <Text style={{ color: palette.goldSoft, fontSize: 12, fontWeight: '700' }}>{t('synthetic.caption')}</Text>
                </Pressable>
                <Pressable accessibilityRole="button" onPress={() => { void publishTrack('dub'); }}>
                  <Text style={{ color: palette.goldSoft, fontSize: 12, fontWeight: '700' }}>{t('synthetic.dub')}</Text>
                </Pressable>
              </View>
            ) : null}
            {post.caption ? (
              <Pressable accessibilityRole="button" accessibilityState={{ expanded }} onPress={() => setExpanded((value) => !value)}>
                <Text
                  numberOfLines={expanded ? 8 : 2}
                  selectable={false}
                  style={{ color: palette.ivory, fontSize: 14, lineHeight: 19, textShadowColor: 'rgba(0,0,0,0.6)', textShadowRadius: 6 }}
                >
                  {post.caption}
                </Text>
              </Pressable>
            ) : null}
            {post.tags.length > 0 ? (
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
                {post.tags.map((tag) => (
                  <Pressable accessibilityRole="button" hitSlop={6} key={tag} onPress={() => onOpenTag(tag)}>
                    <Text style={{ color: palette.goldSoft, fontSize: 13, fontWeight: '600' }}>{t('reels.tag', { tag })}</Text>
                  </Pressable>
                ))}
              </View>
            ) : null}
          </View>
          <ActionRail
            hasVideo={isVideo}
            muted={muted}
            onComments={() => onComments(post)}
            onLike={() => onLike(post, !post.viewer.liked)}
            onOpenAuthor={onOpenAuthor}
            onShare={() => onShare(post)}
            onToggleMute={onToggleMute}
            post={post}
            sharing={sharing}
          />
        </View>
        {post.shop ? (
          <ShopCard
            busy={shopBusy}
            couponLabel={couponLabel}
            offer={post.shop}
            onBuy={() => onBuy(post)}
            onGift={() => onGift(post)}
            onHold={() => onHold(post)}
            onSquad={() => onSquad(post)}
            onTryOn={() => onTryOn(post)}
          />
        ) : post.product ? (
          <OneTapBuyCard product={post.product} />
        ) : null}
      </Animated.View>

      {isVideo ? (
        <View style={{ position: 'absolute', left: 0, right: 0, bottom: 0, height: 2, backgroundColor: 'rgba(255, 255, 255, 0.14)', pointerEvents: 'none' }}>
          <Animated.View style={[{ height: 2, width: '100%', backgroundColor: palette.goldSoft, transformOrigin: 'left' }, progressStyle]} />
        </View>
      ) : null}
    </View>
  );
});

/** Image posts (and posts without media, shown with their product photo): a slow drift while on screen. */
function ReelStill({ uri, animate }: { uri: string | null; animate: boolean }) {
  const reduceMotion = useReducedMotion();
  const zoom = useSharedValue(1);

  useEffect(() => {
    if (!animate || reduceMotion) {
      cancelAnimation(zoom);
      zoom.set(withTiming(1, { duration: 300 }));
      return;
    }
    zoom.set(withRepeat(withTiming(1.08, { duration: 7_000, easing: Easing.inOut(Easing.quad) }), -1, true));
    return () => {
      cancelAnimation(zoom);
    };
  }, [animate, reduceMotion, zoom]);

  const zoomStyle = useAnimatedStyle(() => ({ transform: [{ scale: zoom.get() }] }));

  if (!uri) {
    return <View style={[FILL, { backgroundColor: palette.raised }]} />;
  }
  return (
    <Animated.View style={[FILL, zoomStyle]}>
      <Image accessibilityIgnoresInvertColors cachePolicy="memory-disk" contentFit="cover" recyclingKey={uri} source={{ uri }} style={FILL} transition={180} />
    </Animated.View>
  );
}
