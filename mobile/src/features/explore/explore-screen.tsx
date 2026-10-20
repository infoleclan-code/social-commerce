import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { Hash } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { HashtagTopic } from '@/api/contracts';
import { queryKeys } from '@/api/keys';
import { fetchTrendingHashtags, searchHashtags } from '@/api/social-commerce';
import { t } from '@/lib/i18n';
import { formatCompact } from '@/lib/relative-time';
import { palette, radius } from '@/theme/tokens';

export function ExploreScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const [draft, setDraft] = useState('');
  const [query, setQuery] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setQuery(draft.trim()), 250);
    return () => clearTimeout(timer);
  }, [draft]);

  const searching = query.length > 0;
  const trending = useQuery({
    queryKey: queryKeys.hashtags.trending,
    queryFn: ({ signal }) => fetchTrendingHashtags(signal),
    enabled: !searching,
  });
  const results = useQuery({
    queryKey: queryKeys.hashtags.search(query),
    queryFn: ({ signal }) => searchHashtags(query, signal),
    enabled: searching,
  });
  const list = searching ? results : trending;
  const topics = list.data ?? [];

  function openTag(tag: string) {
    router.push({ pathname: '/reels', params: { tag } });
  }

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, paddingTop: insets.top + 12 }}>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700', paddingHorizontal: 20 }}>{t('explore.title')}</Text>
      <TextInput
        accessibilityLabel={t('explore.search')}
        autoCapitalize="none"
        autoCorrect={false}
        onChangeText={setDraft}
        placeholder={t('explore.search')}
        placeholderTextColor={palette.faint}
        style={{
          margin: 20,
          minHeight: 48,
          borderRadius: radius.pill,
          borderWidth: 1,
          borderColor: palette.line,
          backgroundColor: palette.overlay,
          color: palette.ivory,
          fontSize: 16,
          paddingHorizontal: 18,
        }}
        value={draft}
      />
      <FlatList
        contentContainerStyle={{ paddingHorizontal: 12, paddingBottom: insets.bottom + 120 }}
        data={topics}
        keyExtractor={(item) => item.tag}
        ListEmptyComponent={
          list.isPending ? (
            <ActivityIndicator color={palette.goldSoft} style={{ marginTop: 32 }} />
          ) : (
            <Text style={{ color: palette.muted, fontSize: 15, textAlign: 'center', marginTop: 32 }}>
              {searching ? t('explore.noMatch') : t('explore.quiet')}
            </Text>
          )
        }
        renderItem={({ item }) => <TopicRow onPress={() => openTag(item.tag)} topic={item} />}
      />
    </View>
  );
}

function TopicRow({ topic, onPress }: { topic: HashtagTopic; onPress: () => void }) {
  return (
    <Pressable accessibilityLabel={t('explore.open', { tag: topic.tag })} accessibilityRole="button" onPress={onPress}>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 14, paddingHorizontal: 8, paddingVertical: 14 }}>
        <View
          style={{
            width: 44,
            height: 44,
            borderRadius: 22,
            alignItems: 'center',
            justifyContent: 'center',
            backgroundColor: palette.overlay,
            borderWidth: 1,
            borderColor: palette.goldRing,
          }}
        >
          <Hash color={palette.goldSoft} size={18} strokeWidth={2} />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={{ color: palette.ivory, fontSize: 16, fontWeight: '700' }}>{t('reels.tag', { tag: topic.tag })}</Text>
          <Text style={{ color: palette.muted, fontSize: 13 }}>{t('explore.posts', { count: formatCompact(topic.posts) })}</Text>
        </View>
      </View>
    </Pressable>
  );
}
