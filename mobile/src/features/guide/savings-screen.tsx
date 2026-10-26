import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { fetchSavings } from '@/api/discover';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette } from '@/theme/tokens';

export function SavingsScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const savings = useQuery({ queryKey: ['savings'], queryFn: fetchSavings });
  const data = savings.data?.data;
  const amount = data ? formatMoney({ amount_minor: data.saved_minor, currency: data.currency, minor_unit: data.minor_unit }) : '';
  const month = data ? formatMoney({ amount_minor: data.month_saved_minor, currency: data.currency, minor_unit: data.minor_unit }) : '';

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, paddingTop: insets.top + 16, paddingHorizontal: 20, gap: 14 }}>
      <Pressable accessibilityRole="button" onPress={() => router.back()}>
        <Text style={{ color: palette.ivory, fontWeight: '600' }}>{t('profile.back')}</Text>
      </Pressable>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('guide.savingsTitle')}</Text>
      {data ? <Text style={{ color: palette.ivory }}>{t('guide.savedTotal', { amount, clicks: String(data.clicks) })}</Text> : null}
      {data ? <Text style={{ color: palette.muted }}>{t('guide.month', { amount: month })}</Text> : null}
      {data ? <Text style={{ color: palette.muted }}>{data.note}</Text> : null}
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {(data?.badges ?? []).map((badge) => (
          <View key={badge.id} style={{ paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: palette.gold }}>
            <Text style={{ color: palette.ink, fontWeight: '700' }}>{badge.label}</Text>
          </View>
        ))}
      </View>
    </View>
  );
}
