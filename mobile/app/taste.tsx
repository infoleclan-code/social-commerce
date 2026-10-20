import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';

import { api, json } from '@/api/client';
import { goBackOrTabs } from '@/lib/go-back';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

const STYLES = ['streetwear', 'sneakers', 'beauty', 'travel', 'home', 'studio'] as const;

export default function TasteScreen() {
  const router = useRouter();
  const [chosen, setChosen] = useState<string[]>([]);
  const [saved, setSaved] = useState(false);

  function toggle(style: string) {
    setChosen((current) => (current.includes(style) ? current.filter((item) => item !== style) : [...current, style]));
  }

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, padding: 20, paddingTop: 64, gap: 12 }}>
      <Pressable onPress={() => goBackOrTabs(router)}><Text style={{ color: palette.ivory }}>{t('shop.close')}</Text></Pressable>
      <Text style={{ color: palette.ivory, fontSize: 24, fontWeight: '700' }}>{t('taste.title')}</Text>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {STYLES.map((style) => {
          const on = chosen.includes(style);
          return (
            <Pressable key={style} accessibilityRole="button" accessibilityState={{ selected: on }} onPress={() => toggle(style)} style={{ paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: on ? palette.gold : 'rgba(255,255,255,0.12)' }}>
              <Text style={{ color: on ? palette.ink : palette.ivory, fontWeight: '700' }}>{style}</Text>
            </Pressable>
          );
        })}
      </View>
      <Pressable
        accessibilityRole="button"
        disabled={chosen.length === 0}
        onPress={() => {
          void api('/taste', json('PUT', { styles: chosen })).then(() => setSaved(true));
        }}
        style={{ alignSelf: 'flex-start', paddingHorizontal: 14, paddingVertical: 10, borderRadius: 999, backgroundColor: palette.gold, opacity: chosen.length === 0 ? 0.5 : 1 }}
      >
        <Text style={{ color: palette.ink, fontWeight: '700' }}>{saved ? t('taste.save') : t('taste.save')}</Text>
      </Pressable>
    </View>
  );
}
