import type { InfiniteData, QueryClient, QueryKey } from '@tanstack/react-query';

import type { FeedPage, FeedPost, ProductCard } from '@/api/contracts';
import { queryKeys } from '@/api/keys';

/**
 * In-place edits of every cached reels page (For You and Following), for optimistic likes and shares and
 * for purchase state learned elsewhere (a tap that found the item sold out). The server stays the source
 * of truth: the next refetch replaces all of it.
 */

export type FeedSnapshot = readonly [QueryKey, InfiniteData<FeedPage> | undefined][];

export function snapshotFeeds(queryClient: QueryClient): FeedSnapshot {
  return queryClient.getQueriesData<InfiniteData<FeedPage>>({ queryKey: queryKeys.reels.all });
}

export function restoreFeeds(queryClient: QueryClient, snapshot: FeedSnapshot): void {
  for (const [key, data] of snapshot) {
    queryClient.setQueryData(key, data);
  }
}

function mapPosts(queryClient: QueryClient, transform: (post: FeedPost) => FeedPost): void {
  queryClient.setQueriesData<InfiniteData<FeedPage>>({ queryKey: queryKeys.reels.all }, (data) => {
    if (!data) {
      return data;
    }
    let changed = false;
    const pages = data.pages.map((page) => {
      let pageChanged = false;
      const posts = page.data.map((post) => {
        const next = transform(post);
        if (next !== post) {
          pageChanged = true;
        }
        return next;
      });
      if (!pageChanged) {
        return page;
      }
      changed = true;
      return { ...page, data: posts };
    });
    return changed ? { ...data, pages } : data;
  });
}

export function patchPost(queryClient: QueryClient, postId: string, patch: (post: FeedPost) => FeedPost): void {
  mapPosts(queryClient, (post) => (post.id === postId ? patch(post) : post));
}

/** Ignores an engagement poll that started before this write, so a slow response cannot rewind a like or comment. */
const engagementWrittenAt = new Map<string, number>();

export function noteEngagementWrite(postId: string): void {
  engagementWrittenAt.set(postId, Date.now());
}

export function lastEngagementWrite(postId: string): number {
  return engagementWrittenAt.get(postId) ?? 0;
}

export function patchProduct(queryClient: QueryClient, productId: string, patch: (product: ProductCard) => ProductCard): void {
  mapPosts(queryClient, (post) => (post.product?.id === productId ? { ...post, product: patch(post.product) } : post));
}

export function findPost(queryClient: QueryClient, postId: string): FeedPost | null {
  for (const [, data] of snapshotFeeds(queryClient)) {
    for (const page of data?.pages ?? []) {
      const match = page.data.find((post) => post.id === postId);
      if (match) {
        return match;
      }
    }
  }
  return null;
}
