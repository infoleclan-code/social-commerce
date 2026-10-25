import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { useRouter } from 'expo-router';
import { Clapperboard, Play, UserRound } from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, Text, TextInput, View, useWindowDimensions } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, api, json } from '@/api/client';
import type { FeedPost, StoreProduct, UserProfile } from '@/api/contracts';
import { queryKeys } from '@/api/keys';
import { acceptFriend, blockUser, declineFriend, fetchCloset, fetchLedger, fetchProfile, fetchRewards, fetchRitual, fetchStore, fetchUserPosts, followUser, logoutAccount, requestFriend, unfollowUser, checkIn, completeMission, listClosetItem, openLootBox, spinPrize, type ClosetRow, type Ritual } from '@/api/social-commerce';
import { useSession } from '@/stores/session';
import { EmptyHero } from '@/components/ui/empty-hero';
import { PressableScale } from '@/components/ui/pressable-scale';
import { feedPosts } from '@/features/reels/use-reels';
import { purchasesEnabled } from '@/lib/flags';
import { goBackOrTabs } from '@/lib/go-back';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { formatCompact } from '@/lib/relative-time';
import { palette } from '@/theme/tokens';

const GAP = 2;
const COLUMNS = 3;

type Props = {
  /** Omit for the signed-in profile. */
  userId?: string;
};

export function ProfileScreen({ userId }: Props) {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const width = useWindowDimensions().width;
  const tile = Math.floor((width - GAP * (COLUMNS - 1)) / COLUMNS);
  const storeTile = Math.floor((width - GAP) / 2);
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<'reels' | 'store' | 'closet'>('reels');
  const [lootNote, setLootNote] = useState<string | null>(null);
  const profile = useQuery({
    queryKey: userId ? queryKeys.profile.user(userId) : queryKeys.profile.me,
    queryFn: ({ signal }) => fetchProfile(userId ?? null, signal),
  });
  const posts = useInfiniteQuery({
    queryKey: queryKeys.reels.byUser(profile.data?.id ?? userId ?? 'me'),
    queryFn: ({ pageParam, signal }) => fetchUserPosts(profile.data?.id ?? userId ?? '', pageParam, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.next_cursor,
    enabled: profile.data !== undefined,
  });
  const store = useQuery({
    queryKey: queryKeys.store.user(profile.data?.id ?? 'pending'),
    queryFn: ({ signal }) => fetchStore(profile.data?.id ?? '', signal),
    enabled: profile.data !== undefined && tab === 'store',
  });
  const rewards = useQuery({
    queryKey: queryKeys.rewards.me,
    queryFn: ({ signal }) => fetchRewards(signal),
    enabled: profile.data?.viewer.is_self === true,
  });
  const closet = useQuery({
    queryKey: ['me', 'closet'] as const,
    queryFn: ({ signal }) => fetchCloset(signal),
    enabled: profile.data?.viewer.is_self === true && tab === 'closet',
  });
  const ritual = useQuery({
    queryKey: ['me', 'ritual'] as const,
    queryFn: ({ signal }) => fetchRitual(signal),
    enabled: profile.data?.viewer.is_self === true,
  });
  const ledger = useQuery({
    queryKey: ['me', 'ledger'] as const,
    queryFn: ({ signal }) => fetchLedger(signal),
    enabled: profile.data?.viewer.is_self === true,
  });
  const loot = useMutation({
    mutationFn: openLootBox,
    onSuccess: (result) => {
      setLootNote(t('profile.lootWon', { label: result.coupon.label }));
      void queryClient.invalidateQueries({ queryKey: queryKeys.rewards.me });
    },
    onError: (error) => {
      setLootNote(error instanceof ApiError ? error.message : t('profile.lootNeed'));
    },
  });
  const reels = feedPosts(posts.data);
  const signOut = useSession((state) => state.signOut);
  const [friendNote, setFriendNote] = useState<string | null>(null);

  async function leave() {
    try {
      await logoutAccount();
    } catch {
      // The device still drops its token when the server is unreachable.
    }
    await signOut();
  }

  async function changeFriend(action: 'request' | 'accept' | 'decline' | 'block') {
    if (!profile.data) {
      return;
    }
    setFriendNote(null);
    try {
      if (action === 'request') {
        await requestFriend(profile.data.id);
      } else if (action === 'accept') {
        await acceptFriend(profile.data.id);
      } else if (action === 'decline') {
        await declineFriend(profile.data.id);
      } else {
        await blockUser(profile.data.id);
      }
      await profile.refetch();
    } catch (error) {
      setFriendNote(error instanceof ApiError ? error.message : t('profile.errorTitle'));
    }
  }

  async function changeFollow(already: boolean) {
    if (!profile.data) {
      return;
    }
    setFriendNote(null);
    try {
      if (already) {
        await unfollowUser(profile.data.id);
      } else {
        await followUser(profile.data.id);
      }
      await profile.refetch();
    } catch (error) {
      setFriendNote(error instanceof ApiError ? error.message : t('profile.errorTitle'));
    }
  }

  const openReel = useCallback(
    (post: FeedPost) => {
      const author = profile.data?.id;
      if (!author) {
        return;
      }
      router.push({ pathname: '/reels', params: { userId: author, postId: post.id } });
    },
    [profile.data?.id, router],
  );

  if (profile.isPending) {
    return (
      <View style={{ flex: 1, backgroundColor: palette.ink, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={palette.goldSoft} />
      </View>
    );
  }

  if (profile.isError || profile.data === undefined) {
    const missing = profile.error instanceof ApiError && profile.error.status === 404;
    return (
      <View style={{ flex: 1, backgroundColor: palette.ink, paddingTop: insets.top + 24, paddingHorizontal: 20 }}>
        {userId ? (
          <Pressable accessibilityLabel={t('profile.back')} onPress={() => goBackOrTabs(router)} style={{ marginBottom: 16 }}>
            <Text style={{ color: palette.ivory, fontSize: 16, fontWeight: '600' }}>{t('profile.back')}</Text>
          </Pressable>
        ) : null}
        <EmptyHero
          body={missing ? t('profile.emptyTheirs') : t('reels.errorBody')}
          eyebrow={t('profile.eyebrow')}
          icon={UserRound}
          title={t('profile.errorTitle')}
        />
      </View>
    );
  }

  const person = profile.data;
  const header = (
    <ProfileHeader
      cashLabel={rewards.data ? formatMoney(rewards.data.cash) : null}
      coins={rewards.data?.coins ?? null}
      insetsTop={insets.top}
      lootNote={lootNote}
      ledgerLabel={ledger.data ? t('profile.ledger', { clicks: String(ledger.data.clicks), earned: formatMoney(ledger.data.earned) }) : null}
      lootPending={loot.isPending}
      onBack={userId ? () => goBackOrTabs(router) : undefined}
      onLoot={() => loot.mutate()}
      onTab={setTab}
      person={person}
      ritual={ritual.data}
      onCheckIn={() => {
        void checkIn().then(() => ritual.refetch());
      }}
      onMission={(kind) => {
        void completeMission(kind).then(() => ritual.refetch()).catch((error: unknown) => {
          setLootNote(error instanceof ApiError ? error.message : t('profile.lootNeed'));
        });
      }}
      onSpin={() => {
        void spinPrize().then((result) => {
          setLootNote(result.result.label);
          void ritual.refetch();
          void rewards.refetch();
        });
      }}
      onPlay={() => router.push('/play')}
      onGrow={() => router.push('/grow')}
      onOrders={() => router.push('/orders')}
      onCart={() => router.push('/cart')}
      onCards={() => router.push('/cards')}
      onAdmin={() => router.push('/admin')}
      onCompare={() => router.push('/compare')}
      onLegal={() => router.push('/legal')}
      onDelete={async (password) => {
        await api('/trust/delete', json('POST', { password }));
        await leave();
      }}
      onLogout={() => void leave()}
      onFriend={(action) => void changeFriend(action)}
      onFollow={(already) => void changeFollow(already)}
      friendNote={friendNote}
      tab={tab}
      xp={rewards.data?.xp ?? null}
    />
  );

  if (tab === 'store') {
    return (
      <FlatList
        columnWrapperStyle={{ gap: GAP }}
        contentContainerStyle={{ paddingBottom: insets.bottom + 120, backgroundColor: palette.ink }}
        data={store.data ?? []}
        key="store"
        keyExtractor={(item) => item.id}
        ListEmptyComponent={
          store.isPending ? (
            <ActivityIndicator color={palette.goldSoft} style={{ marginTop: 32 }} />
          ) : (
            <View style={{ paddingHorizontal: 20, paddingTop: 12 }}>
              <EmptyHero body={t('profile.storeEmpty')} eyebrow={t('profile.store')} icon={Clapperboard} title={t('profile.store')} />
            </View>
          )
        }
        ListHeaderComponent={header}
        numColumns={2}
        refreshing={profile.isRefetching}
        onRefresh={() => {
          void profile.refetch();
          void store.refetch();
          void rewards.refetch();
        }}
        renderItem={({ item }) => <StoreTile product={item} size={storeTile} />}
        style={{ flex: 1, backgroundColor: palette.ink }}
      />
    );
  }

  if (tab === 'closet') {
    return (
      <FlatList
        contentContainerStyle={{ paddingBottom: insets.bottom + 120, backgroundColor: palette.ink }}
        data={closet.data ?? []}
        key="closet"
        keyExtractor={(item) => item.id}
        ListEmptyComponent={closet.isPending ? <ActivityIndicator color={palette.goldSoft} style={{ marginTop: 32 }} /> : <View style={{ padding: 20 }}><Text style={{ color: palette.muted }}>{t('profile.closetEmpty')}</Text></View>}
        ListHeaderComponent={header}
        onRefresh={() => {
          void closet.refetch();
        }}
        refreshing={closet.isRefetching}
        renderItem={({ item }) => <ClosetRowView item={item} />}
        style={{ flex: 1, backgroundColor: palette.ink }}
      />
    );
  }

  return (
    <FlatList
      columnWrapperStyle={{ gap: GAP }}
      contentContainerStyle={{ paddingBottom: insets.bottom + 120, backgroundColor: palette.ink }}
      data={reels}
      keyExtractor={(item) => item.id}
      ListEmptyComponent={
        posts.isPending ? (
          <ActivityIndicator color={palette.goldSoft} style={{ marginTop: 32 }} />
        ) : (
          <View style={{ paddingHorizontal: 20, paddingTop: 12 }}>
            <EmptyHero
              body={person.viewer.is_self ? t('profile.emptyBody') : t('profile.emptyTheirs')}
              eyebrow={t('profile.eyebrow')}
              icon={Clapperboard}
              title={t('profile.emptyTitle')}
            />
          </View>
        )
      }
      ListHeaderComponent={header}
      numColumns={COLUMNS}
      onEndReached={() => {
        if (posts.hasNextPage && !posts.isFetchingNextPage) {
          void posts.fetchNextPage();
        }
      }}
      refreshing={profile.isRefetching}
      onRefresh={() => {
        void profile.refetch();
        void posts.refetch();
        void rewards.refetch();
      }}
      renderItem={({ item }) => <ReelTile onPress={() => openReel(item)} post={item} size={tile} />}
      style={{ flex: 1, backgroundColor: palette.ink }}
    />
  );
}

function ProfileHeader({
  person,
  insetsTop,
  onBack,
  tab,
  onTab,
  coins,
  xp,
  cashLabel,
  onLoot,
  lootPending,
  lootNote,
  ledgerLabel,
  ritual,
  onCheckIn,
  onMission,
  onSpin,
  onPlay,
  onGrow,
  onOrders,
  onCart,
  onCards,
  onAdmin,
  onCompare,
  onLegal,
  onDelete,
  onLogout,
  onFriend,
  onFollow,
  friendNote,
}: {
  person: UserProfile;
  insetsTop: number;
  onBack?: () => void;
  tab: 'reels' | 'store' | 'closet';
  onTab: (tab: 'reels' | 'store' | 'closet') => void;
  coins: number | null;
  xp: number | null;
  cashLabel: string | null;
  onLoot: () => void;
  lootPending: boolean;
  lootNote: string | null;
  ledgerLabel: string | null;
  ritual?: Ritual;
  onCheckIn: () => void;
  onMission: (kind: string) => void;
  onSpin: () => void;
  onPlay: () => void;
  onGrow: () => void;
  onOrders: () => void;
  onCart: () => void;
  onCards: () => void;
  onAdmin: () => void;
  onCompare: () => void;
  onLegal: () => void;
  onDelete: (password: string) => Promise<void>;
  onLogout: () => void;
  onFriend: (action: 'request' | 'accept' | 'decline' | 'block') => void;
  onFollow: (alreadyFollowing: boolean) => void;
  friendNote: string | null;
}) {
  const initial = (person.display_name ?? person.username).slice(0, 1).toUpperCase();
  return (
    <View style={{ paddingTop: insetsTop + 16, paddingHorizontal: 20, paddingBottom: 18, gap: 16 }}>
      {onBack ? (
        <Pressable accessibilityLabel={t('profile.back')} onPress={onBack}>
          <Text style={{ color: palette.ivory, fontSize: 16, fontWeight: '600' }}>{t('profile.back')}</Text>
        </Pressable>
      ) : null}
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 16 }}>
        <View
          style={{
            width: 86,
            height: 86,
            borderRadius: 43,
            overflow: 'hidden',
            alignItems: 'center',
            justifyContent: 'center',
            backgroundColor: palette.overlay,
            borderWidth: 1,
            borderColor: palette.goldRing,
          }}
        >
          {person.avatar_url ? (
            <Image accessibilityIgnoresInvertColors contentFit="cover" source={{ uri: person.avatar_url }} style={{ width: '100%', height: '100%' }} />
          ) : (
            <Text style={{ color: palette.goldSoft, fontSize: 28, fontWeight: '700' }}>{initial}</Text>
          )}
        </View>
        <View style={{ flex: 1, flexDirection: 'row' }}>
          <Count label={t('profile.posts')} value={person.counts.posts} />
          <Count label={t('profile.followers')} value={person.counts.followers} />
          <Count label={t('profile.following')} value={person.counts.following} />
        </View>
      </View>
      <View style={{ gap: 4 }}>
        <Text style={{ color: palette.ivory, fontSize: 20, fontWeight: '700' }}>{person.display_name ?? person.username}</Text>
        <Text style={{ color: palette.muted, fontSize: 14 }}>{t('reels.author', { handle: person.username })}</Text>
        {person.bio ? <Text style={{ color: palette.ivory, fontSize: 15, lineHeight: 21, marginTop: 4 }}>{person.bio}</Text> : null}
        {person.viewer.is_friend ? (
          <Text style={{ color: palette.goldSoft, fontSize: 13, fontWeight: '600', marginTop: 4 }}>{t('profile.friend')}</Text>
        ) : null}
        <FriendActions friendNote={friendNote} onFollow={onFollow} onFriend={onFriend} person={person} />
        {person.viewer.is_self ? (
          <AccountActions onAdmin={onAdmin} onCards={onCards} onCart={onCart} onCompare={onCompare} onDelete={onDelete} onLegal={onLegal} onLogout={onLogout} onOrders={onOrders} />
        ) : null}
      </View>
      <View style={{ flexDirection: 'row', gap: 16 }}>
        <TabLabel active={tab === 'reels'} label={t('profile.reelsTab')} onPress={() => onTab('reels')} />
        {purchasesEnabled ? <TabLabel active={tab === 'store'} label={t('profile.store')} onPress={() => onTab('store')} /> : null}
        {purchasesEnabled && person.viewer.is_self ? <TabLabel active={tab === 'closet'} label={t('profile.closetTab')} onPress={() => onTab('closet')} /> : null}
      </View>
      {purchasesEnabled && person.viewer.is_self && ritual ? (
        <View style={{ gap: 8 }}>
          <Text style={{ color: palette.ivory, fontSize: 14 }}>{t('ritual.streak', { count: String(ritual.streak) })}</Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
            <Pressable accessibilityRole="button" disabled={ritual.checked_in} onPress={onCheckIn} style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, backgroundColor: palette.gold, opacity: ritual.checked_in ? 0.5 : 1 }}>
              <Text style={{ color: palette.ink, fontSize: 12, fontWeight: '700' }}>{ritual.checked_in ? t('ritual.checked') : t('ritual.checkIn')}</Text>
            </Pressable>
            {ritual.missions.map((mission) => (
              <Pressable accessibilityRole="button" disabled={mission.done} key={mission.kind} onPress={() => onMission(mission.kind)} style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.12)', opacity: mission.done ? 0.5 : 1 }}>
                <Text style={{ color: palette.ivory, fontSize: 12, fontWeight: '700' }}>{mission.kind}</Text>
              </Pressable>
            ))}
            <Pressable accessibilityRole="button" disabled={!ritual.spin.available} onPress={onSpin} style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, backgroundColor: palette.gold }}>
              <Text style={{ color: palette.ink, fontSize: 12, fontWeight: '700' }}>{ritual.spin.result ? ritual.spin.result.label : t('ritual.spin')}</Text>
            </Pressable>
            <Pressable accessibilityRole="button" onPress={onPlay} style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.12)' }}>
              <Text style={{ color: palette.ivory, fontSize: 12, fontWeight: '700' }}>{t('ritual.play')}</Text>
            </Pressable>
            <Pressable accessibilityRole="button" onPress={onGrow} style={{ paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.12)' }}>
              <Text style={{ color: palette.ivory, fontSize: 12, fontWeight: '700' }}>{t('grow.open')}</Text>
            </Pressable>
          </View>
          <Text style={{ color: palette.muted, fontSize: 12 }}>{ritual.spin.odds.map((odd) => `${odd.label} ${odd.percent}%`).join(' · ')}</Text>
        </View>
      ) : null}
      {purchasesEnabled && person.viewer.is_self && coins !== null && xp !== null && cashLabel !== null ? (
        <View style={{ gap: 8 }}>
          <Text style={{ color: palette.ivory, fontSize: 14 }}>
            {t('profile.coins', { count: String(coins) })} · {t('profile.xp', { count: String(xp) })} · {cashLabel}
          </Text>
          <Text style={{ color: palette.muted, fontSize: 12 }}>{t('profile.lootNeed')}</Text>
          <Pressable accessibilityRole="button" disabled={lootPending} onPress={onLoot} style={{ alignSelf: 'flex-start', paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: palette.gold, opacity: lootPending ? 0.6 : 1 }}>
            <Text style={{ color: palette.ink, fontSize: 13, fontWeight: '700' }}>{t('profile.loot')}</Text>
          </Pressable>
          {lootNote ? <Text style={{ color: palette.goldSoft, fontSize: 13 }}>{lootNote}</Text> : null}
          {ledgerLabel ? <Text style={{ color: palette.ivory, fontSize: 13 }}>{ledgerLabel}</Text> : null}
        </View>
      ) : null}
    </View>
  );
}

function AccountActions({
  onAdmin,
  onCards,
  onCart,
  onCompare,
  onDelete,
  onLegal,
  onLogout,
  onOrders,
}: {
  onAdmin: () => void;
  onCards: () => void;
  onCart: () => void;
  onCompare: () => void;
  onDelete: (password: string) => Promise<void>;
  onLegal: () => void;
  onLogout: () => void;
  onOrders: () => void;
}) {
  const [logoutArmed, setLogoutArmed] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [password, setPassword] = useState('');
  const [note, setNote] = useState<string | null>(null);

  return (
    <View style={{ gap: 8, marginTop: 8 }}>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        <Pressable accessibilityRole="button" onPress={onCompare} style={pill}>
          <Text style={pillText}>{t('compare.prices')}</Text>
        </Pressable>
        {purchasesEnabled ? (
          <>
            <Pressable accessibilityRole="button" onPress={onOrders} style={pill}>
              <Text style={pillText}>{t('profile.orders')}</Text>
            </Pressable>
            <Pressable accessibilityRole="button" onPress={onCart} style={pill}>
              <Text style={pillText}>{t('profile.cart')}</Text>
            </Pressable>
            <Pressable accessibilityRole="button" accessibilityLabel={t('profile.cards')} onPress={onCards} style={pill}>
              <Text style={pillText}>{t('profile.cards')}</Text>
            </Pressable>
          </>
        ) : null}
        <Pressable accessibilityRole="button" accessibilityLabel={t('profile.admin')} onPress={onAdmin} style={pill}>
          <Text style={pillText}>{t('profile.admin')}</Text>
        </Pressable>
        <Pressable accessibilityRole="button" onPress={onLegal} style={pill}>
          <Text style={pillText}>{t('profile.legal')}</Text>
        </Pressable>
        {logoutArmed ? (
          <>
            <Pressable accessibilityRole="button" onPress={onLogout} style={pill}>
              <Text style={pillText}>{t('profile.confirmLogoutYes')}</Text>
            </Pressable>
            <Pressable accessibilityRole="button" onPress={() => setLogoutArmed(false)} style={pill}>
              <Text style={pillText}>{t('profile.cancel')}</Text>
            </Pressable>
          </>
        ) : (
          <Pressable accessibilityRole="button" onPress={() => setLogoutArmed(true)} style={pill}>
            <Text style={pillText}>{t('profile.logout')}</Text>
          </Pressable>
        )}
        <Pressable accessibilityRole="button" onPress={() => setDeleteOpen((open) => !open)} style={pill}>
          <Text style={pillText}>{t('profile.delete')}</Text>
        </Pressable>
      </View>
      {deleteOpen ? (
        <View style={{ gap: 8 }}>
          <Text style={{ color: palette.muted }}>{t('profile.deleteBody')}</Text>
          <TextInput accessibilityLabel={t('auth.password')} onChangeText={setPassword} placeholder={t('auth.password')} placeholderTextColor={palette.faint} secureTextEntry style={{ color: palette.ivory, borderColor: palette.line, borderWidth: 1, borderRadius: 12, paddingHorizontal: 12, paddingVertical: 10 }} value={password} />
          <Pressable accessibilityRole="button" onPress={() => void onDelete(password).catch((error: unknown) => setNote(error instanceof ApiError ? t('profile.delete') : t('profile.errorTitle')))} style={pill}>
            <Text style={pillText}>{t('profile.deleteConfirm')}</Text>
          </Pressable>
        </View>
      ) : null}
      {logoutArmed ? <Text style={{ color: palette.muted }}>{t('profile.confirmLogout')}</Text> : null}
      {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
    </View>
  );
}

function FriendActions({
  person,
  onFriend,
  onFollow,
  friendNote,
}: {
  person: UserProfile;
  onFriend: (action: 'request' | 'accept' | 'decline' | 'block') => void;
  onFollow: (alreadyFollowing: boolean) => void;
  friendNote: string | null;
}) {
  const connection = person.viewer.connection ?? (person.viewer.is_self ? 'self' : person.viewer.is_friend ? 'friends' : 'none');
  if (connection === 'self') {
    return null;
  }
  return (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 8 }}>
      {connection === 'none' ? (
        <Pressable accessibilityRole="button" onPress={() => onFriend('request')} style={pill}>
          <Text style={pillText}>{t('profile.friendRequest')}</Text>
        </Pressable>
      ) : null}
      {connection === 'pending_out' ? <Text style={{ color: palette.muted }}>{t('profile.friendPending')}</Text> : null}
      {connection === 'pending_in' ? (
        <>
          <Pressable accessibilityRole="button" onPress={() => onFriend('accept')} style={pill}>
            <Text style={pillText}>{t('profile.friendAccept')}</Text>
          </Pressable>
          <Pressable accessibilityRole="button" onPress={() => onFriend('decline')} style={pill}>
            <Text style={pillText}>{t('profile.friendDecline')}</Text>
          </Pressable>
        </>
      ) : null}
      {connection === 'blocked' ? (
        <Text style={{ color: palette.muted }}>{t('profile.blocked')}</Text>
      ) : (
        <>
          <Pressable accessibilityRole="button" onPress={() => onFollow(person.viewer.following === true)} style={pill}>
            <Text style={pillText}>{person.viewer.following ? t('profile.unfollow') : t('profile.follow')}</Text>
          </Pressable>
          <Pressable accessibilityRole="button" onPress={() => onFriend('block')} style={pill}>
            <Text style={pillText}>{t('profile.block')}</Text>
          </Pressable>
        </>
      )}
      {friendNote ? <Text style={{ color: palette.goldSoft }}>{friendNote}</Text> : null}
    </View>
  );
}

const pill = { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.12)' as const };
const pillText = { color: palette.ivory, fontSize: 13, fontWeight: '700' as const };

function ClosetRowView({ item }: { item: ClosetRow }) {
  const [listed, setListed] = useState(item.status === 'listed');
  const [price, setPrice] = useState(item.listing_price ? formatMoney(item.listing_price) : formatMoney(item.estimate));
  return (
    <View style={{ flexDirection: 'row', gap: 12, paddingHorizontal: 16, paddingVertical: 10, alignItems: 'center' }}>
      <Image accessibilityIgnoresInvertColors contentFit="cover" source={{ uri: item.image_url }} style={{ width: 64, height: 80, borderRadius: 8 }} />
      <View style={{ flex: 1, gap: 4 }}>
        <Text style={{ color: palette.ivory, fontSize: 15, fontWeight: '700' }}>{item.title}</Text>
        <Text style={{ color: palette.goldSoft, fontSize: 13 }}>{t('closet.estimate', { price })}</Text>
        {listed ? (
          <Text style={{ color: palette.muted, fontSize: 12 }}>{t('closet.listed')}</Text>
        ) : (
          <Pressable
            accessibilityRole="button"
            onPress={() => {
              void listClosetItem(item.id).then((result) => {
                setListed(true);
                setPrice(formatMoney(result.listing_price));
              });
            }}
            style={{ alignSelf: 'flex-start', paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, backgroundColor: palette.gold }}
          >
            <Text style={{ color: palette.ink, fontSize: 12, fontWeight: '700' }}>{t('closet.list')}</Text>
          </Pressable>
        )}
      </View>
    </View>
  );
}

function TabLabel({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="tab" accessibilityState={{ selected: active }} onPress={onPress}>
      <Text style={{ color: active ? palette.ivory : palette.muted, fontSize: 15, fontWeight: '700' }}>{label}</Text>
    </Pressable>
  );
}

function StoreTile({ product, size }: { product: StoreProduct; size: number }) {
  return (
    <View style={{ width: size, marginBottom: GAP, backgroundColor: palette.raised }}>
      <Image accessibilityIgnoresInvertColors contentFit="cover" source={{ uri: product.image_url }} style={{ width: size, height: size }} />
      <View style={{ padding: 8, gap: 2 }}>
        <Text numberOfLines={1} style={{ color: palette.ivory, fontSize: 13, fontWeight: '700' }}>{product.title}</Text>
        <Text numberOfLines={1} style={{ color: palette.muted, fontSize: 12 }}>{product.brand}</Text>
        <Text style={{ color: palette.goldSoft, fontSize: 12 }}>{formatMoney(product.price)}</Text>
      </View>
    </View>
  );
}

function Count({ label, value }: { label: string; value: number }) {
  return (
    <View style={{ flex: 1, alignItems: 'center', gap: 2 }}>
      <Text style={{ color: palette.ivory, fontSize: 18, fontWeight: '700' }}>{formatCompact(value)}</Text>
      <Text style={{ color: palette.muted, fontSize: 12 }}>{label}</Text>
    </View>
  );
}

function ReelTile({ post, size, onPress }: { post: FeedPost; size: number; onPress: () => void }) {
  const uri = post.media?.thumbnail_url ?? (post.media?.type === 'image' ? post.media.url : null);
  return (
    <PressableScale accessibilityLabel={t('profile.openReel')} onPress={onPress}>
      <View style={{ width: size, height: size * 1.35, backgroundColor: palette.raised, marginBottom: GAP }}>
        {uri ? (
          <Image accessibilityIgnoresInvertColors contentFit="cover" source={{ uri }} style={{ width: '100%', height: '100%' }} />
        ) : null}
        {post.media?.type === 'video' ? (
          <View style={{ position: 'absolute', top: 8, right: 8 }}>
            <Play color={palette.ivory} fill={palette.ivory} size={14} />
          </View>
        ) : null}
      </View>
    </PressableScale>
  );
}
