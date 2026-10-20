import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';

import { goBackOrTabs } from '@/lib/go-back';
import { Pressable, ScrollView, Text, View } from 'react-native';

import { api, json } from '@/api/client';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

type Activity = { id: string; kind: string; title: string; body: string; ends_at: string };
type Show = { id: string; title: string; ends_at: string };
type Place = { id: string; name: string; members: number; goal: number; unlocked: boolean };
type Trust = { monthly_spent_minor: number; night_hold: boolean; streaks_opt_in: boolean; paused: boolean };
type Season = { season: { name: string; ends_on: string; paid: boolean } | null; quests: { id: string; title: string; progress: number; target: number; reward: string }[] };

async function get<T>(path: string, signal?: AbortSignal): Promise<T> {
  return (await api<{ data: T }>(path, { signal })).data;
}

export default function GrowScreen() {
  const router = useRouter();
  const client = useQueryClient();
  const live = useQuery({ queryKey: ['live'], queryFn: ({ signal }) => get<Activity[]>('/live', signal) });
  const shows = useQuery({ queryKey: ['shows'], queryFn: ({ signal }) => get<Show[]>('/shows', signal) });
  const places = useQuery({ queryKey: ['places'], queryFn: ({ signal }) => get<Place[]>('/places', signal) });
  const trust = useQuery({ queryKey: ['trust'], queryFn: ({ signal }) => get<Trust>('/trust', signal) });
  const season = useQuery({ queryKey: ['season'], queryFn: ({ signal }) => get<Season>('/season', signal) });
  const refresh = () => {
    void client.invalidateQueries({ queryKey: ['live'] });
    void client.invalidateQueries({ queryKey: ['trust'] });
    void client.invalidateQueries({ queryKey: ['places'] });
  };
  const run = useMutation({
    mutationFn: async (job: () => Promise<unknown>) => job(),
    onSuccess: refresh,
  });

  return (
    <ScrollView style={{ flex: 1, backgroundColor: palette.ink }} contentContainerStyle={{ padding: 20, paddingTop: 64, gap: 18 }}>
      <Pressable onPress={() => goBackOrTabs(router)}><Text style={{ color: palette.ivory }}>{t('shop.close')}</Text></Pressable>
      <Text style={{ color: palette.ivory, fontSize: 24, fontWeight: '700' }}>{t('grow.title')}</Text>
      <Text style={{ color: palette.muted }}>{(live.data ?? []).length === 0 ? 'No open events. Only things you start show up here.' : live.data?.map((item) => item.title).join('\n')}</Text>
      {(shows.data ?? []).map((show) => (
        <View key={show.id} style={{ gap: 6 }}>
          <Text style={{ color: palette.ivory }}>{show.title}</Text>
          <Text style={{ color: palette.muted }}>Ends {show.ends_at}</Text>
          <Pressable accessibilityRole="button" onPress={() => run.mutate(() => api('/shows/' + show.id + '/remind', json('POST')))} style={chip}>
            <Text style={chipText}>{t('grow.remind')}</Text>
          </Pressable>
        </View>
      ))}
      <Pressable accessibilityRole="button" onPress={() => router.push('/taste')} style={chip}>
        <Text style={chipText}>{t('taste.title')}</Text>
      </Pressable>
      <Pressable accessibilityRole="button" onPress={() => router.push('/sell')} style={chip}>
        <Text style={chipText}>{t('sell.title')}</Text>
      </Pressable>
      <Pressable accessibilityRole="button" onPress={() => run.mutate(() => api('/rooms', json('POST', { visibility: 'private' })))} style={chip}>
        <Text style={chipText}>{t('grow.room')}</Text>
      </Pressable>
      <Pressable accessibilityRole="button" onPress={() => run.mutate(() => api('/agent', json('PUT', { budget_minor: 20000, monthly_cap_minor: 50000, styles: 'streetwear', alerts_per_day: 3 })))} style={chip}>
        <Text style={chipText}>{t('grow.agent')}</Text>
      </Pressable>
      <Pressable accessibilityRole="button" onPress={() => run.mutate(() => api('/agent/propose', json('POST')))} style={chip}>
        <Text style={chipText}>{t('grow.find')}</Text>
      </Pressable>
      {(places.data ?? []).map((place) => (
        <Pressable key={place.id} accessibilityRole="button" onPress={() => run.mutate(() => api('/places/' + place.id + '/join', json('POST')))} style={chip}>
          <Text style={chipText}>{t('grow.joinCity')} · {place.name} {place.members}/{place.goal}{place.unlocked ? ' · open' : ''}</Text>
        </Pressable>
      ))}
      {season.data?.season ? <Text style={{ color: palette.ivory }}>{season.data.season.name} · free · ends {season.data.season.ends_on}</Text> : null}
      {(season.data?.quests ?? []).map((quest) => (
        <Pressable key={quest.id} onPress={() => run.mutate(() => api('/season/quests/' + quest.id, json('POST')))}>
          <Text style={{ color: palette.goldSoft }}>{quest.title} {quest.progress}/{quest.target} · {quest.reward}</Text>
        </Pressable>
      ))}
      <Text style={{ color: palette.muted }}>Spent this month {trust.data?.monthly_spent_minor ?? 0}. Night hold {trust.data?.night_hold ? 'on' : 'off'}.</Text>
      <Pressable accessibilityRole="button" onPress={() => run.mutate(() => api('/trust/limit', json('PUT', { monthly_limit_minor: 100000, night_hold: true })))} style={chip}>
        <Text style={chipText}>{t('grow.limit')}</Text>
      </Pressable>
      <Pressable accessibilityRole="button" onPress={() => run.mutate(() => api('/trust/streaks', json('PUT', { on: !trust.data?.streaks_opt_in })))} style={chip}>
        <Text style={chipText}>{trust.data?.streaks_opt_in ? 'Turn streaks off' : t('grow.streaks')}</Text>
      </Pressable>
      <Pressable accessibilityRole="button" onPress={() => run.mutate(() => api('/trust/mute', json('POST', { channel: 'show' })))} style={chip}>
        <Text style={chipText}>Mute show reminders</Text>
      </Pressable>
      <Pressable accessibilityRole="button" onPress={() => run.mutate(() => api('/trust/export', json('POST')))} style={chip}>
        <Text style={chipText}>{t('grow.export')}</Text>
      </Pressable>
      <Pressable accessibilityRole="button" onPress={() => run.mutate(() => api('/trust/pause', json('POST')))} style={chip}>
        <Text style={chipText}>{t('grow.pause')}</Text>
      </Pressable>
      <Pressable accessibilityRole="button" onPress={() => run.mutate(() => api('/trust/delete', json('POST')))} style={chip}>
        <Text style={chipText}>{t('grow.leave')}</Text>
      </Pressable>
    </ScrollView>
  );
}

const chip = { alignSelf: 'flex-start' as const, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: palette.gold };
const chipText = { color: palette.ink, fontWeight: '700' as const };
