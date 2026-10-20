import { useInfiniteQuery, useMutation, useQuery, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { useEffect } from 'react';
import { Platform, Share } from 'react-native';

import { ApiError, NetworkError } from '@/api/client';
import type { CommentPage, Engagement, FeedComment, FeedMode, FeedPage, FeedPost } from '@/api/contracts';
import { queryKeys } from '@/api/keys';
import { createComment, deleteComment, fetchComments, fetchEngagement, fetchFeedPage, fetchHashtagPosts, fetchUserPosts, likePost, openReferral, sharePost, unlikePost } from '@/api/social-commerce';
import { lastEngagementWrite, noteEngagementWrite, patchPost } from '@/features/reels/feed-cache';
import { t } from '@/lib/i18n';

/** Transport trouble and server errors are worth one more try; a 4xx answer will not change. */
function shouldRetry(failureCount: number, error: unknown): boolean {
  const transient = error instanceof NetworkError || (error instanceof ApiError && error.status >= 500);
  return transient && failureCount < 2;
}

// ---------------------------------------------------------------------------------------------- feed

export function useReelsFeed(mode: FeedMode, enabled = true) {
  return useInfiniteQuery({
    queryKey: queryKeys.reels.feed(mode),
    queryFn: ({ pageParam, signal }) => fetchFeedPage(mode, pageParam, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage: FeedPage) => lastPage.meta.next_cursor,
    enabled,
    staleTime: 60_000,
    retry: shouldRetry,
  });
}

/** Reels that carry one hashtag, ranked like For You. Same cache prefix so likes stay in sync. */
export function useHashtagPosts(tag: string | null) {
  return useInfiniteQuery({
    queryKey: queryKeys.reels.byTag(tag ?? 'none'),
    queryFn: ({ pageParam, signal }) => fetchHashtagPosts(tag ?? '', pageParam, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage: FeedPage) => lastPage.meta.next_cursor,
    enabled: tag !== null && tag.length > 0,
    staleTime: 60_000,
    retry: shouldRetry,
  });
}

/** The reels on one profile, newest first. Same cache prefix as the main feed so likes stay in sync. */
export function useUserPosts(userId: string | null) {
  return useInfiniteQuery({
    queryKey: queryKeys.reels.byUser(userId ?? 'none'),
    queryFn: ({ pageParam, signal }) => fetchUserPosts(userId ?? '', pageParam, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage: FeedPage) => lastPage.meta.next_cursor,
    enabled: userId !== null && userId.length > 0,
    staleTime: 60_000,
    retry: shouldRetry,
  });
}

/** Flattens the pages, dropping repeats (a post can move between pages while its rank changes). */
export function feedPosts(data: InfiniteData<FeedPage> | undefined): FeedPost[] {
  if (!data) {
    return [];
  }
  const seen = new Set<string>();
  const posts: FeedPost[] = [];
  for (const page of data.pages) {
    for (const post of page.data) {
      if (!seen.has(post.id)) {
        seen.add(post.id);
        posts.push(post);
      }
    }
  }
  return posts;
}

type LikeVariables = { postId: string; like: boolean };

function withLike(post: FeedPost, like: boolean): FeedPost {
  if (post.viewer.liked === like) {
    return post;
  }
  return {
    ...post,
    viewer: { ...post.viewer, liked: like },
    stats: { ...post.stats, likes: Math.max(0, post.stats.likes + (like ? 1 : -1)) },
  };
}

/**
 * Optimistic like / unlike. The heart flips at once; the server's count replaces the guess when it
 * answers, unless a newer tap already flipped it again. Taps run one at a time (mutation scope), so the
 * last tap is the state the server ends in.
 */
export function useLikeToggle() {
  const queryClient = useQueryClient();
  return useMutation({
    scope: { id: 'reels-like' },
    mutationFn: ({ postId, like }: LikeVariables) => (like ? likePost(postId) : unlikePost(postId)),
    onMutate: ({ postId, like }) => {
      noteEngagementWrite(postId);
      patchPost(queryClient, postId, (post) => withLike(post, like));
    },
    onError: (_error, { postId, like }) => {
      noteEngagementWrite(postId);
      patchPost(queryClient, postId, (post) => (post.viewer.liked === like ? withLike(post, !like) : post));
    },
    onSuccess: (state, { postId }) => {
      noteEngagementWrite(postId);
      patchPost(queryClient, postId, (post) =>
        post.viewer.liked === state.liked ? { ...post, stats: { ...post.stats, likes: state.likes_count } } : post,
      );
    },
  });
}

/** Mints the share link (a tracked /s/{token} link for shoppable posts), counts it, opens the share sheet. */
export function useSharePost() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (post: FeedPost) => {
      noteEngagementWrite(post.id);
      const result = await sharePost(post.id, 'other');
      if (result.token) {
        await openReferral(result.token);
      }
      noteEngagementWrite(post.id);
      patchPost(queryClient, post.id, (current) => ({ ...current, stats: { ...current.stats, shares: result.shares_count } }));
      const caption = post.caption?.trim() || post.product?.title || t('reels.shareFallback');
      try {
        await Share.share(
          Platform.OS === 'ios' ? { message: caption, url: result.url } : { message: t('reels.shareMessage', { caption, url: result.url }) },
        );
      } catch {
        // The person closed the sheet, or the platform has none (some browsers): the link was still counted.
      }
      return result;
    },
  });
}

// ------------------------------------------------------------------------------------------ comments

export function useComments(postId: string | null, parentId: string | null = null, enabled = true) {
  return useInfiniteQuery({
    queryKey: queryKeys.reels.comments(postId ?? 'none', parentId),
    queryFn: ({ pageParam, signal }) => fetchComments(postId ?? '', pageParam, parentId, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage: CommentPage) => lastPage.meta.next_cursor,
    enabled: enabled && postId !== null,
    staleTime: 15_000,
    refetchInterval: enabled && postId !== null ? 8_000 : false,
    retry: shouldRetry,
  });
}

export function flattenComments(data: InfiniteData<CommentPage> | undefined): FeedComment[] {
  if (!data) {
    return [];
  }
  const seen = new Set<string>();
  const comments: FeedComment[] = [];
  for (const page of data.pages) {
    for (const comment of page.data) {
      if (!seen.has(comment.id)) {
        seen.add(comment.id);
        comments.push(comment);
      }
    }
  }
  return comments;
}

function editThread(
  queryClient: ReturnType<typeof useQueryClient>,
  postId: string,
  parentId: string | null,
  edit: (comments: FeedComment[], pageIndex: number, pageCount: number) => FeedComment[],
): void {
  queryClient.setQueryData<InfiniteData<CommentPage>>(queryKeys.reels.comments(postId, parentId), (data) =>
    data
      ? { ...data, pages: data.pages.map((page, index) => ({ ...page, data: edit(page.data, index, data.pages.length) })) }
      : data,
  );
}

function bumpReplies(queryClient: ReturnType<typeof useQueryClient>, postId: string, parentId: string, delta: number): void {
  editThread(queryClient, postId, null, (comments) =>
    comments.map((comment) => (comment.id === parentId ? { ...comment, replies_count: Math.max(0, comment.replies_count + delta) } : comment)),
  );
}

function setCommentCount(queryClient: ReturnType<typeof useQueryClient>, postId: string, commentsCount: number): void {
  noteEngagementWrite(postId);
  patchPost(queryClient, postId, (post) => ({ ...post, stats: { ...post.stats, comments: commentsCount } }));
}

type NewComment = { body: string; parentId: string | null };

/** The new row is appended (the list is oldest-first). The reel's comment count becomes the database count. */
export function useAddComment(postId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ body, parentId }: NewComment) => createComment(postId, body, parentId),
    onSuccess: ({ comment, commentsCount }) => {
      editThread(queryClient, postId, comment.parent_id, (comments, pageIndex, pageCount) =>
        pageIndex === pageCount - 1 ? [...comments, comment] : comments,
      );
      if (comment.parent_id) {
        bumpReplies(queryClient, postId, comment.parent_id, 1);
      }
      setCommentCount(queryClient, postId, commentsCount);
    },
  });
}

export function useDeleteComment(postId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (comment: FeedComment) => deleteComment(comment.id),
    onSuccess: (commentsCount, comment) => {
      editThread(queryClient, postId, comment.parent_id, (comments) => comments.filter((item) => item.id !== comment.id));
      if (comment.parent_id) {
        bumpReplies(queryClient, postId, comment.parent_id, -1);
      }
      setCommentCount(queryClient, postId, commentsCount);
    },
  });
}

type TimedEngagement = Engagement & { started: number };

/** Polls the trigger-maintained counts for the reel on screen or the open comments sheet. */
export function useEngagementSync(postId: string | null, enabled: boolean): void {
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: queryKeys.reels.engagement(postId ?? 'none'),
    queryFn: async ({ signal }): Promise<TimedEngagement> => {
      const started = Date.now();
      const engagement = await fetchEngagement(postId ?? '', signal);
      return { ...engagement, started };
    },
    enabled: enabled && postId !== null && postId.length > 0,
    refetchInterval: 4_000,
    refetchIntervalInBackground: false,
  });

  useEffect(() => {
    const data = query.data;
    if (!data || postId === null || data.started <= lastEngagementWrite(postId)) {
      return;
    }
    patchPost(queryClient, postId, (post) => ({
      ...post,
      viewer: { ...post.viewer, liked: data.liked },
      stats: { likes: data.likes_count, comments: data.comments_count, shares: data.shares_count },
    }));
  }, [query.data, postId, queryClient]);
}
