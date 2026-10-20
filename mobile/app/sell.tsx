import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';

import { api, json } from '@/api/client';
import { goBackOrTabs } from '@/lib/go-back';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

export default function SellScreen() {
  const router = useRouter();
  const [status, setStatus] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, padding: 20, paddingTop: 64, gap: 12 }}>
      <Pressable onPress={() => goBackOrTabs(router)}><Text style={{ color: palette.ivory }}>{t('shop.close')}</Text></Pressable>
      <Text style={{ color: palette.ivory, fontSize: 24, fontWeight: '700' }}>{t('sell.title')}</Text>
      <Text style={{ color: palette.muted }}>{status ?? 'Business details are checked with Stripe Connect before a catalogue can sync.'}</Text>
      <Pressable
        accessibilityRole="button"
        onPress={() => {
          void api<{ data: { status: string; onboarding_url?: string } }>('/seller', json('POST', {
            legal_name: 'Preview Studio LLC',
            country: 'US',
            registration_number: 'EIN-00-0000000',
          })).then((body) => {
            setStatus(body.data.status);
            setNote(body.data.onboarding_url ?? null);
          });
        }}
        style={button}
      >
        <Text style={label}>{t('sell.register')}</Text>
      </Pressable>
      <Pressable
        accessibilityRole="button"
        onPress={() => {
          void api<{ data: { status: string } }>('/seller/refresh', json('POST')).then((body) => setStatus(body.data.status));
        }}
        style={button}
      >
        <Text style={label}>{t('sell.refresh')}</Text>
      </Pressable>
      <Pressable
        accessibilityRole="button"
        onPress={() => {
          void api('/seller/catalog', json('POST', {
            products: [{
              sku: 'jacket-1',
              title: 'Night jacket',
              brand: 'Atelier',
              image_url: 'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?auto=format&fit=crop&w=800&q=80',
              price_minor: 12800,
              stock: 4,
              ships_from: 'US',
            }],
          })).then(() => setNote('Catalogue synced'));
        }}
        style={button}
      >
        <Text style={label}>{t('sell.sync')}</Text>
      </Pressable>
      {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
    </View>
  );
}

const button = { alignSelf: 'flex-start' as const, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: palette.gold };
const label = { color: palette.ink, fontWeight: '700' as const };
