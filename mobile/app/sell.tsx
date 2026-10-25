import { useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { ApiError, api, json } from '@/api/client';
import { goBackOrTabs } from '@/lib/go-back';
import { t } from '@/lib/i18n';
import { PurchasesGate } from '@/lib/purchases-gate';
import { palette } from '@/theme/tokens';

export default function SellScreen() {
  const router = useRouter();
  const [legalName, setLegalName] = useState('');
  const [country, setCountry] = useState('');
  const [registration, setRegistration] = useState('');
  const [status, setStatus] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function register() {
    setBusy(true);
    setNote(null);
    try {
      const body = await api<{ data: { status: string; onboarding_url?: string } }>('/seller', json('POST', {
        legal_name: legalName.trim(),
        country: country.trim().toUpperCase(),
        registration_number: registration.trim(),
      }));
      setStatus(body.data.status);
      setNote(body.data.onboarding_url ?? null);
    } catch (error) {
      setNote(error instanceof ApiError ? error.message : t('sell.failed'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <PurchasesGate>
    <View style={{ flex: 1, backgroundColor: palette.ink, padding: 20, paddingTop: 64, gap: 12 }}>
      <Pressable accessibilityRole="button" onPress={() => goBackOrTabs(router)}><Text style={{ color: palette.ivory }}>{t('shop.close')}</Text></Pressable>
      <Text style={{ color: palette.ivory, fontSize: 24, fontWeight: '700' }}>{t('sell.title')}</Text>
      <Text style={{ color: palette.muted }}>{status ?? t('sell.body')}</Text>
      <TextInput accessibilityLabel={t('sell.legalName')} onChangeText={setLegalName} placeholder={t('sell.legalName')} placeholderTextColor={palette.muted} style={field} value={legalName} />
      <TextInput accessibilityLabel={t('sell.country')} autoCapitalize="characters" maxLength={2} onChangeText={setCountry} placeholder={t('sell.country')} placeholderTextColor={palette.muted} style={field} value={country} />
      <TextInput accessibilityLabel={t('sell.registration')} autoCapitalize="characters" onChangeText={setRegistration} placeholder={t('sell.registration')} placeholderTextColor={palette.muted} style={field} value={registration} />
      <Pressable accessibilityRole="button" disabled={busy} onPress={() => void register()} style={button}>
        <Text style={label}>{busy ? t('auth.working') : t('sell.register')}</Text>
      </Pressable>
      <Pressable
        accessibilityRole="button"
        onPress={() => {
          void api<{ data: { status: string } }>('/seller/refresh', json('POST')).then((body) => setStatus(body.data.status)).catch((error: unknown) => {
            setNote(error instanceof ApiError ? error.message : t('sell.failed'));
          });
        }}
        style={button}
      >
        <Text style={label}>{t('sell.refresh')}</Text>
      </Pressable>
      {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
    </View>
    </PurchasesGate>
  );
}

const field = { color: palette.ivory, borderColor: 'rgba(255,255,255,0.2)', borderWidth: 1, borderRadius: 12, padding: 10 };
const button = { alignSelf: 'flex-start' as const, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: palette.gold };
const label = { color: palette.ink, fontWeight: '700' as const };
