import { CardElement, Elements, useElements, useStripe } from '@stripe/react-stripe-js';
import { loadStripe, type Stripe } from '@stripe/stripe-js';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { beginPaymentMethodSetup, confirmPaymentMethodSetup } from '@/api/social-commerce';
import { newIdempotencyKey } from '@/lib/idempotency';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

const stripes = new Map<string, Promise<Stripe | null>>();

function stripeFor(publishableKey: string): Promise<Stripe | null> {
  const existing = stripes.get(publishableKey);
  if (existing) {
    return existing;
  }
  const created = loadStripe(publishableKey);
  stripes.set(publishableKey, created);
  return created;
}

function CardFields({ onSaved }: { onSaved: () => void }) {
  const stripe = useStripe();
  const elements = useElements();
  const [note, setNote] = useState<string | null>(null);

  async function save() {
    const card = elements?.getElement(CardElement);
    if (!stripe || !card) {
      return;
    }
    setNote(null);
    try {
      const setup = await beginPaymentMethodSetup(newIdempotencyKey('setup'));
      if (!setup.client_secret) {
        setNote(t('cards.empty'));
        return;
      }
      const result = await stripe.confirmCardSetup(setup.client_secret, { payment_method: { card } });
      if (result.error || !result.setupIntent) {
        setNote(result.error?.message ?? t('cards.empty'));
        return;
      }
      await confirmPaymentMethodSetup(result.setupIntent.id, newIdempotencyKey('confirm'));
      onSaved();
    } catch {
      setNote(t('cards.empty'));
    }
  }

  return (
    <View style={{ gap: 12 }}>
      <View style={{ backgroundColor: palette.overlay, borderRadius: 12, padding: 12 }}>
        <CardElement options={{ style: { base: { color: '#FFFFFF', fontSize: '16px' } } }} />
      </View>
      <Pressable accessibilityRole="button" accessibilityLabel={t('cards.save')} onPress={() => void save()} style={{ backgroundColor: palette.gold, borderRadius: 999, paddingVertical: 12, alignItems: 'center' }}>
        <Text style={{ color: palette.ink, fontWeight: '700' }}>{t('cards.save')}</Text>
      </Pressable>
      {note ? <Text style={{ color: palette.ivory }}>{note}</Text> : null}
    </View>
  );
}

/** Stripe Elements. Metro uses this file on web and web-card-form.native.tsx everywhere else. */
export function WebCardForm({ publishableKey, onSaved }: { publishableKey: string; onSaved: () => void }) {
  return (
    <Elements stripe={stripeFor(publishableKey)}>
      <CardFields onSaved={onSaved} />
    </Elements>
  );
}
