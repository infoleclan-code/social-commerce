import { useQueryClient, type QueryClient } from '@tanstack/react-query';
import { AlertCircle, CheckCircle2, ShieldCheck, Undo2, X } from 'lucide-react-native';
import { useEffect } from 'react';
import { AccessibilityInfo, ActivityIndicator, Pressable, Text, View } from 'react-native';
import Animated, { FadeInUp, FadeOutUp } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { DefaultPaymentMethod } from '@/api/contracts';
import { queryKeys } from '@/api/keys';
import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { useNow } from '@/features/feed/use-now';
import { retryOneTap, undoOneTap } from '@/features/one-tap/one-tap-engine';
import { useOneTapStore, type OneTapProblem, type Purchase } from '@/features/one-tap/one-tap-store';
import { stripeBridge } from '@/features/payments/stripe-bridge';
import { t, type MessageKey } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { formatMinutesSeconds } from '@/lib/relative-time';
import { palette, radius } from '@/theme/tokens';

/** Terminal states leave the screen on their own; failures stay until dismissed or retried. */
const AUTO_HIDE_MS = 4_500;

type Action = { label: string; run: () => void } | null;

const PROBLEM_COPY: Record<OneTapProblem, MessageKey> = {
  payment_method_required: 'oneTap.error.payment_method_required',
  shipping_address_required: 'oneTap.error.shipping_address_required',
  not_shippable: 'oneTap.error.not_shippable',
  out_of_stock: 'oneTap.error.out_of_stock',
  variant_unavailable: 'oneTap.error.variant_unavailable',
  limit: 'oneTap.error.limit',
  restricted: 'oneTap.error.restricted',
  unavailable: 'oneTap.error.unavailable',
  declined: 'oneTap.error.declined',
  provider_unavailable: 'oneTap.error.provider_unavailable',
  network: 'oneTap.error.network',
  rate_limited: 'oneTap.error.rate_limited',
  signed_out: 'oneTap.error.signed_out',
  auth_required: 'oneTap.error.auth_required',
  auth_failed: 'oneTap.error.auth_failed',
  auth_canceled: 'oneTap.error.auth_canceled',
  unsupported: 'oneTap.error.unsupported',
  still_processing: 'oneTap.error.still_processing',
  not_cancellable: 'oneTap.error.not_cancellable',
  undo_failed: 'oneTap.error.undo_failed',
  setup_failed: 'oneTap.error.setup_failed',
  key_reused: 'oneTap.error.key_reused',
  unknown: 'oneTap.error.unknown',
};

/** Problems a press can resolve, and the label of that press. */
const RETRYABLE: Partial<Record<OneTapProblem, MessageKey>> = {
  payment_method_required: 'oneTap.addCard',
  setup_failed: 'oneTap.addCard',
  auth_required: 'oneTap.continue',
  still_processing: 'oneTap.checkAgain',
  network: 'oneTap.retry',
  provider_unavailable: 'oneTap.retry',
  rate_limited: 'oneTap.retry',
  declined: 'oneTap.retry',
  auth_failed: 'oneTap.retry',
  auth_canceled: 'oneTap.retry',
  key_reused: 'oneTap.retry',
  unknown: 'oneTap.retry',
};

/**
 * Status of the most recent 1-Tap purchase, pinned under the status bar above every screen: progress,
 * the undo countdown, and the one action that resolves a failure (add a card, try again, continue with
 * the bank). Rendered once at the root, so it survives scrolling and navigation.
 */
export function OneTapBanner() {
  const focusId = useOneTapStore((state) => state.focusId);
  const purchase = useOneTapStore((state) => (state.focusId ? state.purchases[state.focusId] : undefined));
  const visible = purchase !== undefined && purchase.phase !== 'redirected';

  return visible && focusId ? <BannerCard key={focusId} purchase={purchase} /> : null;
}

function BannerCard({ purchase }: { purchase: Purchase }) {
  const insets = useSafeAreaInsets();
  const queryClient = useQueryClient();
  const dismiss = useOneTapStore((state) => state.dismiss);
  const counting = purchase.phase === 'authorized';
  const now = useNow(counting);
  const { title, body, tone } = describe(purchase);
  const action = actionFor(purchase, queryClient, now);

  useEffect(() => {
    AccessibilityInfo.announceForAccessibility(`${title}. ${body}`);
  }, [title, body]);

  useEffect(() => {
    if (purchase.phase !== 'completed' && purchase.phase !== 'cancelled') {
      return;
    }
    const id = setTimeout(() => {
      if (useOneTapStore.getState().focusId === purchase.productId) {
        useOneTapStore.getState().dismiss();
      }
    }, AUTO_HIDE_MS);
    return () => clearTimeout(id);
  }, [purchase.phase, purchase.productId, purchase.updatedAt]);

  return (
    <Animated.View
      accessibilityLiveRegion="polite"
      entering={FadeInUp.springify().damping(18)}
      exiting={FadeOutUp.duration(180)}
      style={{ position: 'absolute', top: insets.top + 6, left: 12, right: 12, zIndex: 2000, alignItems: 'center' }}
    >
      <GlassSurface
        radius={radius.panel}
        style={{
          width: '100%',
          maxWidth: 460,
          backgroundColor: 'rgba(8, 8, 8, 0.9)',
          borderColor: tone === 'success' ? palette.goldRing : tone === 'error' ? 'rgba(232, 160, 160, 0.4)' : palette.lineStrong,
          boxShadow: '0 12px 32px rgba(0, 0, 0, 0.55)',
        }}
      >
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12, paddingLeft: 14, paddingRight: 8 }}>
          <StatusGlyph purchase={purchase} />
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text numberOfLines={1} style={{ color: palette.ivory, fontSize: 15, fontWeight: '700' }}>
              {title}
            </Text>
            <Text numberOfLines={2} style={{ color: tone === 'error' ? '#E8A0A0' : palette.muted, fontSize: 13, lineHeight: 18 }}>
              {body}
            </Text>
          </View>
          {action ? (
            <PressableScale accessibilityLabel={action.label} onPress={action.run}>
              <View
                style={{
                  minHeight: 36,
                  paddingHorizontal: 14,
                  borderRadius: radius.pill,
                  flexDirection: 'row',
                  alignItems: 'center',
                  gap: 6,
                  backgroundColor: counting ? palette.ivory : palette.gold,
                }}
              >
                {counting ? <Undo2 color={palette.ink} size={14} strokeWidth={2.4} /> : null}
                <Text style={{ color: palette.ink, fontSize: 13, fontWeight: '700', fontVariant: ['tabular-nums'] }}>{action.label}</Text>
              </View>
            </PressableScale>
          ) : null}
          <Pressable accessibilityLabel={t('oneTap.dismissA11y')} accessibilityRole="button" hitSlop={8} onPress={dismiss}>
            <View style={{ width: 32, height: 32, alignItems: 'center', justifyContent: 'center' }}>
              <X color={palette.faint} size={16} strokeWidth={2} />
            </View>
          </Pressable>
        </View>
      </GlassSurface>
    </Animated.View>
  );
}

function StatusGlyph({ purchase }: { purchase: Purchase }) {
  switch (purchase.phase) {
    case 'submitting':
    case 'saving_card':
    case 'confirming':
    case 'cancelling':
      return <ActivityIndicator color={palette.goldSoft} size="small" />;
    case 'authenticating':
      return <ShieldCheck color={palette.goldSoft} size={22} strokeWidth={1.8} />;
    case 'authorized':
    case 'completed':
      return purchase.problem ? (
        <AlertCircle color="#E8A0A0" size={22} strokeWidth={1.8} />
      ) : (
        <CheckCircle2 color={palette.goldSoft} size={22} strokeWidth={1.8} />
      );
    case 'cancelled':
      return <CheckCircle2 color={palette.muted} size={22} strokeWidth={1.8} />;
    default:
      return <AlertCircle color="#E8A0A0" size={22} strokeWidth={1.8} />;
  }
}

function describe(purchase: Purchase): { title: string; body: string; tone: 'neutral' | 'success' | 'error' } {
  const problem = purchase.problem ? t(PROBLEM_COPY[purchase.problem]) : null;
  switch (purchase.phase) {
    case 'submitting':
      return { title: t('oneTap.state.submitting'), body: purchase.title, tone: 'neutral' };
    case 'saving_card':
      return { title: t('oneTap.state.savingCard'), body: purchase.title, tone: 'neutral' };
    case 'authenticating':
      return { title: t('oneTap.state.authenticating'), body: purchase.title, tone: 'neutral' };
    case 'confirming':
      return { title: t('oneTap.state.confirming'), body: purchase.title, tone: 'neutral' };
    case 'authorized': {
      const total = purchase.order?.total ? formatMoney(purchase.order.total) : '';
      const body = problem ?? (total ? t('oneTap.authorizedBody', { title: purchase.title, total }) : purchase.title);
      return { title: t('oneTap.state.authorized'), body, tone: problem ? 'error' : 'success' };
    }
    case 'completed':
      return { title: t('oneTap.state.completed'), body: problem ?? t('oneTap.completedBody'), tone: problem ? 'error' : 'success' };
    case 'cancelling':
      return { title: t('oneTap.state.cancelling'), body: purchase.title, tone: 'neutral' };
    case 'cancelled':
      return { title: t('oneTap.state.cancelled'), body: t('oneTap.cancelledBody'), tone: 'neutral' };
    default:
      return { title: t('oneTap.state.failed'), body: problem ?? t('oneTap.error.unknown'), tone: 'error' };
  }
}

/** `now` comes from a 1-second ticker while the undo window is open, so the countdown label stays live. */
function actionFor(purchase: Purchase, queryClient: QueryClient, now: number): Action {
  if (purchase.phase === 'authorized' && purchase.order?.undo_until) {
    const remaining = Date.parse(purchase.order.undo_until) - now;
    if (remaining <= 0) {
      return null;
    }
    return {
      label: t('oneTap.undoIn', { time: formatMinutesSeconds(remaining) }),
      run: () => {
        void undoOneTap(queryClient, purchase.productId);
      },
    };
  }
  if (purchase.phase !== 'failed' || !purchase.problem) {
    return null;
  }
  const label = RETRYABLE[purchase.problem];
  if (!label) {
    return null;
  }
  if (label === 'oneTap.addCard' && !stripeBridge.supported) {
    const method = queryClient.getQueryData<DefaultPaymentMethod>(queryKeys.oneTap.paymentMethod);
    if (method?.publishable_key) {
      return null;
    }
  }
  return {
    label: t(label),
    run: () => retryOneTap(queryClient, purchase.productId),
  };
}
