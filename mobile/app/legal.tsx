import { useRouter } from 'expo-router';
import { Pressable, ScrollView, Text } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

export default function LegalScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();

  return (
    <ScrollView contentContainerStyle={{ paddingTop: insets.top + 16, paddingHorizontal: 20, paddingBottom: insets.bottom + 40, gap: 16 }} style={{ flex: 1, backgroundColor: palette.ink }}>
      <Pressable accessibilityRole="button" onPress={() => router.back()}>
        <Text style={{ color: palette.ivory, fontWeight: '600' }}>{t('profile.back')}</Text>
      </Pressable>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('legal.termsTitle')}</Text>
      <Text style={{ color: palette.ivory, lineHeight: 22 }}>{t('legal.terms')}</Text>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('legal.privacyTitle')}</Text>
      <Text style={{ color: palette.ivory, lineHeight: 22 }}>{t('legal.privacy')}</Text>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('legal.affiliateTitle')}</Text>
      <Text style={{ color: palette.ivory, lineHeight: 22 }}>{t('legal.affiliate')}</Text>
    </ScrollView>
  );
}
