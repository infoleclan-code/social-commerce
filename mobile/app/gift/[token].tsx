import { useMutation, useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { ActivityIndicator, Pressable, Text, TextInput, View, type TextInputProps } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';

import { goBackOrTabs } from '@/lib/go-back';

import { ApiError } from '@/api/client';
import { claimGift, fetchGift } from '@/api/social-commerce';
import { purchaseErrorCopy } from '@/features/payments/purchase-copy';
import { t } from '@/lib/i18n';
import { PurchasesGate } from '@/lib/purchases-gate';
import { palette } from '@/theme/tokens';

/** Gift links carry a 24-character random token. Anything else is not sent to the API at all. */
const GIFT_TOKEN = /^[A-Za-z0-9]{16,64}$/;

/** The same bounds the API enforces (HabitScaleController::claimGift), checked before sending. */
function addressProblem(address: { line: string; locality: string; postal_code: string; country: string }): boolean {
  return (
    address.line.length < 4 ||
    address.line.length > 120 ||
    address.locality.length < 2 ||
    address.locality.length > 80 ||
    address.postal_code.length < 2 ||
    address.postal_code.length > 16 ||
    !/^[A-Z]{2}$/.test(address.country)
  );
}

export default function GiftClaimScreen() {
  const params = useLocalSearchParams<{ token: string }>();
  const token = typeof params.token === 'string' && GIFT_TOKEN.test(params.token) ? params.token : null;
  const router = useRouter();
  const gift = useQuery({
    queryKey: ['gift', token],
    queryFn: ({ signal }) => fetchGift(token ?? '', signal),
    enabled: token !== null,
  });
  const [line, setLine] = useState('');
  const [locality, setLocality] = useState('');
  const [postal, setPostal] = useState('');
  const [country, setCountry] = useState('');
  const [note, setNote] = useState<string | null>(null);
  const address = { line: line.trim(), locality: locality.trim(), postal_code: postal.trim(), country: country.trim().toUpperCase() };
  const invalid = addressProblem(address);
  const claim = useMutation({
    mutationFn: () => claimGift(token ?? '', address),
    onSuccess: () => setNote(t('gift.saved')),
    onError: (error: unknown) =>
      setNote(error instanceof ApiError && error.code === 'GIFT_CLOSED' ? t('gift.closed') : purchaseErrorCopy(error)),
  });

  return (
    <PurchasesGate>
    <View style={{ flex: 1, backgroundColor: palette.ink, padding: 20, gap: 12, paddingTop: 64 }}>
      <Pressable onPress={() => goBackOrTabs(router)}><Text style={{ color: palette.ivory }}>{t('shop.close')}</Text></Pressable>
      {token === null ? <Text style={{ color: palette.goldSoft }}>{t('gift.closed')}</Text> : null}
      {gift.isPending && token !== null ? <ActivityIndicator color={palette.goldSoft} /> : null}
      {gift.data ? <Text style={{ color: palette.ivory, fontSize: 22, fontWeight: '700' }}>{gift.data.product.title}</Text> : null}
      {gift.data?.can_claim ? (
        <View style={{ gap: 8 }}>
          <Field autoComplete="street-address" label={t('gift.line')} maxLength={120} value={line} onChange={setLine} />
          <Field autoComplete="address-line2" label={t('gift.city')} maxLength={80} value={locality} onChange={setLocality} />
          <Field autoComplete="postal-code" label={t('gift.postal')} maxLength={16} value={postal} onChange={setPostal} />
          <Field autoCapitalize="characters" autoComplete="country" label={t('gift.country')} maxLength={2} value={country} onChange={setCountry} />
          <Pressable
            accessibilityRole="button"
            accessibilityState={{ disabled: invalid || claim.isPending }}
            disabled={invalid || claim.isPending}
            onPress={() => claim.mutate()}
            style={{ padding: 12, borderRadius: 999, backgroundColor: palette.gold, opacity: invalid ? 0.5 : 1 }}
          >
            <Text style={{ color: palette.ink, fontWeight: '700', textAlign: 'center' }}>{t('gift.claim')}</Text>
          </Pressable>
        </View>
      ) : null}
      {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
    </View>
    </PurchasesGate>
  );
}

function Field({
  label,
  value,
  onChange,
  maxLength,
  autoComplete,
  autoCapitalize = 'none',
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  maxLength: number;
  autoComplete: TextInputProps['autoComplete'];
  autoCapitalize?: TextInputProps['autoCapitalize'];
}) {
  return (
    <TextInput
      accessibilityLabel={label}
      autoCapitalize={autoCapitalize}
      autoComplete={autoComplete}
      autoCorrect={false}
      maxLength={maxLength}
      onChangeText={onChange}
      placeholder={label}
      placeholderTextColor={palette.muted}
      style={{ color: palette.ivory, borderWidth: 1, borderColor: 'rgba(255,255,255,0.2)', borderRadius: 12, padding: 12 }}
      value={value}
    />
  );
}
