export const queryKeys = {
  products: {
    all: ['products'] as const,
  },
  cart: {
    me: ['me', 'cart'] as const,
  },
  drops: {
    all: ['drops'] as const,
    detail: (id: string) => ['drops', id] as const,
  },
  closet: {
    list: (filters: Record<string, string> = {}) => ['me', 'closet', filters] as const,
  },
  listings: {
    all: ['listings'] as const,
  },
  feed: {
    all: ['feed'] as const,
  },
  wallet: {
    me: ['me', 'wallet'] as const,
  },
  orders: {
    all: ['me', 'orders'] as const,
  },
  hashtags: {
    trending: ['hashtags', 'trending'] as const,
    search: (query: string) => ['hashtags', 'search', query] as const,
  },
  profile: {
    me: ['me', 'profile'] as const,
    user: (userId: string) => ['profile', userId] as const,
  },
  vision: {
    match: (imageUrl: string) => ['vision', 'matches', imageUrl] as const,
    /** Keyed by a hash of the image, never the URI itself: web picker URIs are multi-megabyte data URLs. */
    offers: (imageKey: string, kind: string) => ['vision', 'offers', imageKey, kind] as const,
  },
  reels: {
    /** Prefix of every reels feed query: optimistic like/share patches walk all of them. */
    all: ['reels', 'feed'] as const,
    feed: (mode: string) => ['reels', 'feed', mode] as const,
    byUser: (userId: string) => ['reels', 'feed', 'user', userId] as const,
    byTag: (tag: string) => ['reels', 'feed', 'tag', tag] as const,
    /** Every comment query of a post (top level and each reply thread). */
    commentsOf: (postId: string) => ['reels', 'comments', postId] as const,
    comments: (postId: string, parentId: string | null) => ['reels', 'comments', postId, parentId ?? 'top'] as const,
    engagement: (postId: string) => ['reels', 'engagement', postId] as const,
  },
  rewards: {
    me: ['me', 'rewards'] as const,
  },
  store: {
    user: (userId: string) => ['store', userId] as const,
  },
  activity: {
    live: ['activity', 'live'] as const,
  },
  oneTap: {
    order: (orderId: string) => ['me', 'orders', orderId] as const,
    paymentMethod: ['me', 'payment-method'] as const,
  },
} as const;
