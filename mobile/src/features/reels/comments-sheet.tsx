import { useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { X } from 'lucide-react-native';
import { useState, useSyncExternalStore } from 'react';
import { ActivityIndicator, FlatList, Platform, Pressable, Text, TextInput, View, useWindowDimensions } from 'react-native';
import { Gesture, GestureDetector } from 'react-native-gesture-handler';
import Animated, { FadeIn, FadeOut, SlideInDown, SlideOutDown, useAnimatedStyle, useSharedValue, withSpring, withTiming } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { scheduleOnRN } from 'react-native-worklets';

import { ApiError } from '@/api/client';
import type { FeedComment, FeedPost } from '@/api/contracts';
import { PressableScale } from '@/components/ui/pressable-scale';
import { findPost } from '@/features/reels/feed-cache';
import { flattenComments, useAddComment, useComments, useDeleteComment, useEngagementSync } from '@/features/reels/use-reels';
import { playError, playSelection, playSuccess } from '@/lib/feedback';
import { t, type MessageKey } from '@/lib/i18n';
import { formatCompact, timeAgo } from '@/lib/relative-time';
import { useKeyboardHeight } from '@/lib/use-keyboard-height';
import { useCommentsSheetStore } from '@/stores/comments-sheet';
import { palette, radius } from '@/theme/tokens';

const FILL = { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 } as const;
const MAX_LENGTH = 1_000;
const DISMISS_DISTANCE = 120;
const DISMISS_VELOCITY = 900;

/** The post as the feed cache currently has it (live comment count), without refetching. */
function useCachedPost(postId: string | null): FeedPost | null {
  const queryClient = useQueryClient();
  const cache = queryClient.getQueryCache();
  return useSyncExternalStore(
    (onChange) => cache.subscribe(onChange),
    () => (postId ? findPost(queryClient, postId) : null),
    () => null,
  );
}

function commentError(error: unknown): MessageKey {
  if (error instanceof ApiError) {
    if (error.status === 401) {
      return 'comments.signedOut';
    }
    if (error.code === 'SOCIAL_OPT_IN_REQUIRED') {
      return 'comments.optIn';
    }
  }
  return 'comments.failed';
}

/**
 * Comments of one reel, as a bottom sheet over everything (rendered at the root, so it also covers the
 * tab bar). Drag the handle down or tap outside to close. One level of replies, like the API.
 */
export function CommentsSheet() {
  const postId = useCommentsSheetStore((state) => state.postId);
  return postId ? <Sheet key={postId} postId={postId} /> : null;
}

function Sheet({ postId }: { postId: string }) {
  const insets = useSafeAreaInsets();
  const { height: windowHeight } = useWindowDimensions();
  const close = useCommentsSheetStore((state) => state.close);
  const post = useCachedPost(postId);
  useEngagementSync(postId, true);
  const keyboard = useKeyboardHeight();
  const drag = useSharedValue(0);
  const sheetHeight = Math.round(windowHeight * 0.68);
  const [replyTo, setReplyTo] = useState<FeedComment | null>(null);
  const [openThreads, setOpenThreads] = useState<ReadonlySet<string>>(() => new Set());

  function finishReply(parentId: string | null) {
    if (parentId) {
      setOpenThreads((current) => new Set(current).add(parentId));
    }
    setReplyTo(null);
  }

  const pan = Gesture.Pan()
    .activeOffsetY(8)
    .onUpdate((event) => {
      'worklet';
      drag.set(Math.max(0, event.translationY));
    })
    .onEnd((event) => {
      'worklet';
      if (event.translationY > DISMISS_DISTANCE || event.velocityY > DISMISS_VELOCITY) {
        drag.set(withTiming(sheetHeight, { duration: 180 }, (finished) => {
          if (finished) {
            scheduleOnRN(close);
          }
        }));
        return;
      }
      drag.set(withSpring(0, { damping: 20, stiffness: 260 }));
    });

  const sheetStyle = useAnimatedStyle(() => {
    // The composer keeps the home-indicator inset as padding, so the keyboard covers that part of it.
    const lift = Math.max(0, keyboard.get() - insets.bottom);
    return {
      bottom: lift,
      height: Math.min(sheetHeight, windowHeight - insets.top - 12 - lift),
      transform: [{ translateY: drag.get() }],
    };
  });

  const count = post?.stats.comments ?? null;

  return (
    <View style={[FILL, { zIndex: 1500 }]}>
      <Animated.View entering={FadeIn.duration(180)} exiting={FadeOut.duration(160)} style={[FILL, { backgroundColor: palette.scrim }]}>
        <Pressable accessibilityLabel={t('comments.closeA11y')} accessibilityRole="button" onPress={close} style={FILL} />
      </Animated.View>

      <Animated.View
        accessibilityViewIsModal
        entering={SlideInDown.springify().damping(22).stiffness(220)}
        exiting={SlideOutDown.duration(200)}
        onAccessibilityEscape={close}
        style={[
          {
            position: 'absolute',
            left: 0,
            right: 0,
            borderTopLeftRadius: radius.sheet,
            borderTopRightRadius: radius.sheet,
            backgroundColor: '#0E0E0E',
            borderWidth: 1,
            borderColor: palette.line,
            overflow: 'hidden',
          },
          sheetStyle,
        ]}
      >
        <GestureDetector gesture={pan}>
          <View style={{ paddingTop: 8, paddingBottom: 10, paddingHorizontal: 16 }}>
            <View style={{ alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: palette.lineStrong }} />
            <View style={{ marginTop: 10, flexDirection: 'row', alignItems: 'center' }}>
              <Text accessibilityRole="header" style={{ flex: 1, color: palette.ivory, fontSize: 16, fontWeight: '700' }}>
                {count !== null ? t('comments.count', { count: formatCompact(count) }) : t('comments.title')}
              </Text>
              <Pressable accessibilityLabel={t('comments.closeA11y')} accessibilityRole="button" hitSlop={8} onPress={close}>
                <View style={{ width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center', backgroundColor: palette.overlay }}>
                  <X color={palette.ivory} size={16} strokeWidth={2} />
                </View>
              </Pressable>
            </View>
          </View>
        </GestureDetector>

        <CommentList onReply={setReplyTo} openThreads={openThreads} postId={postId} toggleThread={(id) => setOpenThreads((current) => {
          const next = new Set(current);
          if (next.has(id)) {
            next.delete(id);
          } else {
            next.add(id);
          }
          return next;
        })} />
        <Composer bottomInset={insets.bottom} onCancelReply={() => setReplyTo(null)} onPosted={finishReply} postId={postId} replyTo={replyTo} />
      </Animated.View>
    </View>
  );
}

function CommentList({
  postId,
  onReply,
  openThreads,
  toggleThread,
}: {
  postId: string;
  onReply: (comment: FeedComment) => void;
  openThreads: ReadonlySet<string>;
  toggleThread: (id: string) => void;
}) {
  const comments = useComments(postId);
  const items = flattenComments(comments.data);

  return (
    <FlatList
      ListEmptyComponent={
        <View style={{ paddingVertical: 48, paddingHorizontal: 24, alignItems: 'center', gap: 12 }}>
          {comments.isPending ? (
            <ActivityIndicator color={palette.goldSoft} />
          ) : comments.isError ? (
            <>
              <Text style={{ color: palette.muted, fontSize: 14 }}>{t('comments.loadFailed')}</Text>
              <PressableScale accessibilityLabel={t('reels.retry')} onPress={() => void comments.refetch()}>
                <Text style={{ color: palette.goldSoft, fontSize: 14, fontWeight: '700' }}>{t('reels.retry')}</Text>
              </PressableScale>
            </>
          ) : (
            <Text style={{ color: palette.muted, fontSize: 14, textAlign: 'center' }}>{t('comments.empty')}</Text>
          )}
        </View>
      }
      ListFooterComponent={comments.isFetchingNextPage ? <ActivityIndicator color={palette.goldSoft} style={{ paddingVertical: 16 }} /> : null}
      contentContainerStyle={{ paddingHorizontal: 16, paddingBottom: 12 }}
      data={items}
      keyExtractor={(item) => item.id}
      keyboardDismissMode={Platform.OS === 'ios' ? 'interactive' : 'on-drag'}
      keyboardShouldPersistTaps="handled"
      onEndReached={() => {
        if (comments.hasNextPage && !comments.isFetchingNextPage) {
          void comments.fetchNextPage();
        }
      }}
      onEndReachedThreshold={0.6}
      renderItem={({ item }) => (
        <CommentRow comment={item} onReply={onReply} open={openThreads.has(item.id)} postId={postId} toggleThread={() => toggleThread(item.id)} />
      )}
      style={{ flex: 1 }}
    />
  );
}

function CommentRow({
  comment,
  postId,
  onReply,
  open = false,
  toggleThread,
}: {
  comment: FeedComment;
  postId: string;
  onReply?: (comment: FeedComment) => void;
  open?: boolean;
  toggleThread?: () => void;
}) {
  const remove = useDeleteComment(postId);
  const isReply = comment.parent_id !== null;
  const name = comment.author.display_name ?? comment.author.username;

  return (
    <View style={{ paddingVertical: 10, paddingLeft: isReply ? 44 : 0 }}>
      <View style={{ flexDirection: 'row', gap: 12 }}>
        <Avatar size={isReply ? 26 : 34} uri={comment.author.avatar_url} initial={name.slice(0, 1).toUpperCase()} />
        <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
          <Text numberOfLines={1} style={{ color: palette.muted, fontSize: 12, fontWeight: '600' }}>
            {t('reels.author', { handle: comment.author.username })}
            <Text style={{ color: palette.faint, fontWeight: '400' }}>{`  ${timeAgo(comment.created_at)}`}</Text>
          </Text>
          <Text style={{ color: palette.ivory, fontSize: 14, lineHeight: 20 }}>{comment.body}</Text>
          <View style={{ flexDirection: 'row', gap: 18, marginTop: 2 }}>
            {!isReply && onReply ? (
              <Pressable accessibilityRole="button" hitSlop={8} onPress={() => onReply(comment)}>
                <Text style={{ color: palette.faint, fontSize: 12, fontWeight: '700' }}>{t('comments.reply')}</Text>
              </Pressable>
            ) : null}
            {comment.is_mine ? (
              <Pressable
                accessibilityLabel={t('comments.deleteA11y')}
                accessibilityRole="button"
                disabled={remove.isPending}
                hitSlop={8}
                onPress={() => remove.mutate(comment, { onError: () => playError() })}
              >
                <Text style={{ color: palette.faint, fontSize: 12, fontWeight: '700', opacity: remove.isPending ? 0.5 : 1 }}>{t('comments.delete')}</Text>
              </Pressable>
            ) : null}
          </View>
          {!isReply && comment.replies_count > 0 && toggleThread ? (
            <Pressable accessibilityRole="button" accessibilityState={{ expanded: open }} hitSlop={6} onPress={toggleThread}>
              <Text style={{ color: palette.goldSoft, fontSize: 12, fontWeight: '700', marginTop: 6 }}>
                {open
                  ? t('comments.hideReplies')
                  : comment.replies_count === 1
                    ? t('comments.viewReply')
                    : t('comments.viewReplies', { count: String(comment.replies_count) })}
              </Text>
            </Pressable>
          ) : null}
        </View>
      </View>
      {open ? <Replies parentId={comment.id} postId={postId} /> : null}
    </View>
  );
}

function Replies({ postId, parentId }: { postId: string; parentId: string }) {
  const replies = useComments(postId, parentId);
  const items = flattenComments(replies.data);

  if (replies.isPending) {
    return <ActivityIndicator color={palette.goldSoft} style={{ marginTop: 8, alignSelf: 'flex-start', marginLeft: 46 }} />;
  }
  return (
    <View>
      {items.map((reply) => (
        <CommentRow comment={reply} key={reply.id} postId={postId} />
      ))}
      {replies.hasNextPage ? (
        <Pressable accessibilityRole="button" disabled={replies.isFetchingNextPage} onPress={() => void replies.fetchNextPage()} style={{ marginLeft: 46, paddingVertical: 6 }}>
          <Text style={{ color: palette.goldSoft, fontSize: 12, fontWeight: '700' }}>{t('reels.more')}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

function Avatar({ uri, initial, size }: { uri: string | null; initial: string; size: number }) {
  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: size / 2,
        overflow: 'hidden',
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: palette.overlay,
        borderWidth: 1,
        borderColor: palette.line,
      }}
    >
      {uri ? (
        <Image accessibilityIgnoresInvertColors cachePolicy="memory-disk" contentFit="cover" source={{ uri }} style={{ width: '100%', height: '100%' }} />
      ) : (
        <Text style={{ color: palette.goldSoft, fontSize: size * 0.42, fontWeight: '700' }}>{initial}</Text>
      )}
    </View>
  );
}

function Composer({
  postId,
  replyTo,
  bottomInset,
  onPosted,
  onCancelReply,
}: {
  postId: string;
  replyTo: FeedComment | null;
  bottomInset: number;
  onPosted: (parentId: string | null) => void;
  onCancelReply: () => void;
}) {
  const [draft, setDraft] = useState('');
  const [problem, setProblem] = useState<MessageKey | null>(null);
  const add = useAddComment(postId);
  const body = draft.trim();
  const canSend = body.length > 0 && !add.isPending;

  function send() {
    if (!canSend) {
      return;
    }
    setProblem(null);
    add.mutate(
      { body, parentId: replyTo?.id ?? null },
      {
        onSuccess: () => {
          playSuccess();
          const parentId = replyTo?.id ?? null;
          setDraft('');
          onPosted(parentId);
        },
        onError: (error) => {
          playError();
          setProblem(commentError(error));
        },
      },
    );
  }

  return (
    <View style={{ borderTopWidth: 1, borderTopColor: palette.line, paddingHorizontal: 12, paddingTop: 10, paddingBottom: Math.max(bottomInset, 10), gap: 6 }}>
      {replyTo ? (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
          <Text numberOfLines={1} style={{ flex: 1, color: palette.muted, fontSize: 12 }}>
            {t('comments.replyPlaceholder', { handle: replyTo.author.username })}
          </Text>
          <Pressable accessibilityLabel={t('comments.cancelReply')} accessibilityRole="button" hitSlop={8} onPress={onCancelReply}>
            <X color={palette.faint} size={14} strokeWidth={2} />
          </Pressable>
        </View>
      ) : null}
      {problem ? <Text style={{ color: '#E8A0A0', fontSize: 12 }}>{t(problem)}</Text> : null}
      <View style={{ flexDirection: 'row', alignItems: 'flex-end', gap: 10 }}>
        <TextInput
          accessibilityLabel={replyTo ? t('comments.replyPlaceholder', { handle: replyTo.author.username }) : t('comments.placeholder')}
          maxLength={MAX_LENGTH}
          multiline
          onChangeText={(value) => {
            setDraft(value);
            if (problem) {
              setProblem(null);
            }
          }}
          placeholder={replyTo ? t('comments.replyPlaceholder', { handle: replyTo.author.username }) : t('comments.placeholder')}
          placeholderTextColor={palette.faint}
          style={{
            flex: 1,
            maxHeight: 110,
            minHeight: 44,
            color: palette.ivory,
            fontSize: 15,
            borderWidth: 1,
            borderColor: palette.line,
            borderRadius: 22,
            paddingHorizontal: 16,
            paddingTop: 12,
            paddingBottom: 12,
            backgroundColor: palette.overlay,
          }}
          value={draft}
        />
        <PressableScale
          accessibilityLabel={t('comments.send')}
          accessibilityState={{ disabled: !canSend, busy: add.isPending }}
          disabled={!canSend}
          onPress={() => {
            playSelection();
            send();
          }}
        >
          <View
            style={{
              height: 44,
              paddingHorizontal: 16,
              borderRadius: radius.pill,
              alignItems: 'center',
              justifyContent: 'center',
              backgroundColor: canSend ? palette.gold : palette.overlay,
            }}
          >
            {add.isPending ? (
              <ActivityIndicator color={palette.ink} size="small" />
            ) : (
              <Text style={{ color: canSend ? palette.ink : palette.faint, fontSize: 14, fontWeight: '700' }}>{t('comments.send')}</Text>
            )}
          </View>
        </PressableScale>
      </View>
    </View>
  );
}
