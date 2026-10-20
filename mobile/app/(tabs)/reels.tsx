import { useLocalSearchParams, useRouter } from 'expo-router';

import { goBackOrTabs } from '@/lib/go-back';

import { InfiniteFeedScreen } from '@/features/reels/infinite-feed-screen';

function one(value: string | string[] | undefined): string | null {
  const raw = Array.isArray(value) ? value[0] : value;
  return typeof raw === 'string' && raw.length > 0 ? raw : null;
}

export default function ReelsRoute() {
  const router = useRouter();
  const params = useLocalSearchParams<{ userId?: string; postId?: string; tag?: string }>();
  const tag = one(params.tag);
  const authorId = tag === null ? one(params.userId) : null;
  const initialPostId = one(params.postId);

  function openTag(next: string) {
    router.push({ pathname: '/reels', params: { tag: next } });
  }

  return (
    <InfiniteFeedScreen
      authorId={authorId}
      initialPostId={initialPostId}
      onCloseAuthorFeed={tag !== null || authorId !== null ? () => goBackOrTabs(router) : undefined}
      onOpenAuthor={(id) => {
        if (id.length === 0) {
          router.push('/profile');
          return;
        }
        router.push({ pathname: '/user/[id]', params: { id } });
      }}
      onOpenTag={openTag}
      onScan={() => router.push('/scan')}
      tag={tag}
    />
  );
}
