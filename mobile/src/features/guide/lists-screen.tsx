import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { dollarsToMinor, fetchShop } from '@/api/compare';
import { addListItem, createList, fetchLists, joinList, pledgeList, voteItem } from '@/api/discover';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette } from '@/theme/tokens';

export function ListsScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const queryClient = useQueryClient();
  const shop = useQuery({ queryKey: ['shop'], queryFn: fetchShop });
  const lists = useQuery({ queryKey: ['shared-lists'], queryFn: fetchLists });
  const [title, setTitle] = useState('');
  const [code, setCode] = useState('');
  const [item, setItem] = useState('');
  const [pledge, setPledge] = useState('');
  const currency = shop.data?.data.currency ?? 'USD';

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['shared-lists'] });
  const create = useMutation({ mutationFn: () => createList(title.trim(), currency), onSuccess: async () => { setTitle(''); await refresh(); } });
  const join = useMutation({ mutationFn: () => joinList(code.trim().toLowerCase()), onSuccess: async () => { setCode(''); await refresh(); } });

  return (
    <ScrollView contentContainerStyle={{ paddingTop: insets.top + 16, paddingHorizontal: 20, paddingBottom: insets.bottom + 40, gap: 14 }} style={{ flex: 1, backgroundColor: palette.ink }}>
      <Pressable accessibilityRole="button" onPress={() => router.back()}>
        <Text style={{ color: palette.ivory, fontWeight: '600' }}>{t('profile.back')}</Text>
      </Pressable>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('guide.lists')}</Text>
      <Text style={{ color: palette.muted }}>{t('guide.pledgeNote')}</Text>
      <TextInput accessibilityLabel={t('guide.listTitle')} onChangeText={setTitle} placeholder={t('guide.listTitle')} placeholderTextColor={palette.faint} style={field} value={title} />
      <Pressable accessibilityRole="button" onPress={() => create.mutate()} style={button}>
        <Text style={buttonText}>{t('guide.listCreate')}</Text>
      </Pressable>
      <TextInput accessibilityLabel={t('guide.listJoin')} autoCapitalize="none" onChangeText={setCode} placeholder={t('guide.listJoin')} placeholderTextColor={palette.faint} style={field} value={code} />
      <Pressable accessibilityRole="button" onPress={() => join.mutate()} style={button}>
        <Text style={buttonText}>{t('guide.listJoinAction')}</Text>
      </Pressable>
      {(lists.data?.data ?? []).map((list) => (
        <View key={list.id} style={{ gap: 8, padding: 12, borderRadius: 16, backgroundColor: 'rgba(255,255,255,0.06)' }}>
          <Text style={{ color: palette.ivory, fontWeight: '700' }}>{list.title}</Text>
          <Text style={{ color: palette.muted }}>{list.token}</Text>
          <Text style={{ color: palette.goldSoft }}>{t('guide.pledgeTotal', { amount: formatMoney({ amount_minor: list.pledge_total_minor, currency: list.currency, minor_unit: list.minor_unit ?? 2 }) })}</Text>
          {list.items.map((row) => (
            <View key={row.id} style={{ gap: 4 }}>
              <Text style={{ color: palette.ivory }}>{row.query} · {row.votes_up} / {row.votes_down}</Text>
              <View style={{ flexDirection: 'row', gap: 8 }}>
                <Pressable accessibilityRole="button" onPress={() => void voteItem(list.id, row.id, 'up').then(refresh)} style={pill}>
                  <Text style={pillText}>{t('guide.voteUp')}</Text>
                </Pressable>
                <Pressable accessibilityRole="button" onPress={() => void voteItem(list.id, row.id, 'down').then(refresh)} style={pill}>
                  <Text style={pillText}>{t('guide.voteDown')}</Text>
                </Pressable>
              </View>
            </View>
          ))}
          <TextInput accessibilityLabel={t('guide.listItem')} onChangeText={setItem} placeholder={t('guide.listItem')} placeholderTextColor={palette.faint} style={field} value={item} />
          <Pressable accessibilityRole="button" onPress={() => void addListItem(list.id, item.trim()).then(() => { setItem(''); return refresh(); })} style={pill}>
            <Text style={pillText}>{t('guide.listAdd')}</Text>
          </Pressable>
          <TextInput accessibilityLabel={t('guide.pledge')} keyboardType="decimal-pad" onChangeText={setPledge} placeholder={t('guide.pledge')} placeholderTextColor={palette.faint} style={field} value={pledge} />
          <Pressable accessibilityRole="button" onPress={() => {
            const minor = dollarsToMinor(pledge, list.currency);
            if (!minor) return;
            void pledgeList(list.id, Number(minor)).then(() => { setPledge(''); return refresh(); });
          }} style={pill}>
            <Text style={pillText}>{t('guide.pledgeAction')}</Text>
          </Pressable>
        </View>
      ))}
    </ScrollView>
  );
}

const field = { color: palette.ivory, borderColor: palette.line, borderWidth: 1, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12 };
const button = { backgroundColor: palette.gold, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 10, alignSelf: 'flex-start' as const };
const buttonText = { color: palette.ink, fontWeight: '700' as const };
const pill = { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.12)', alignSelf: 'flex-start' as const };
const pillText = { color: palette.ivory, fontSize: 13, fontWeight: '700' as const };
