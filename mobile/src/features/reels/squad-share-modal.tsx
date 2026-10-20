import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, Share, Text, View } from 'react-native';

import type { FeedPost } from '@/api/contracts';
import { openReferral } from '@/api/social-commerce';
import { useSharePost } from '@/features/reels/use-reels';
import { t } from '@/lib/i18n';
import { formatMoney, type DisplayMoney } from '@/lib/money';
import { palette, radius } from '@/theme/tokens';

export type SquadHold = {
  post: FeedPost;
  solo: DisplayMoney;
  squad: DisplayMoney;
  expiresAt: string;
  members: number;
};

type Props = {
  hold: SquadHold;
  onClose: () => void;
};

/** The 24-hour squad hold, with the tracked share link that records a referral click. */
export function SquadShareModal({ hold, onClose }: Props) {
  const share = useSharePost();
  const [url, setUrl] = useState<string | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [tracked, setTracked] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const left = useCountdown(hold.expiresAt);

  async function onShareLink() {
    setError(null);
    try {
      if (token && url) {
        await openReferral(token);
        setTracked(true);
        await presentShare(hold.post, url);
        return;
      }
      const result = await share.mutateAsync(hold.post);
      setUrl(result.url);
      setToken(result.token ?? null);
      setTracked(Boolean(result.token));
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('shop.failed'));
    }
  }

  return (
    <View style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, zIndex: 40, justifyContent: 'flex-end', backgroundColor: 'rgba(0, 0, 0, 0.55)' }}>
      <Pressable accessibilityLabel={t('shop.close')} onPress={onClose} style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }} />
      <View style={{ margin: 12, padding: 16, gap: 12, borderRadius: radius.sheet, backgroundColor: '#141210', borderWidth: 1, borderColor: 'rgba(255,255,255,0.12)' }}>
        <Text style={{ color: palette.ivory, fontSize: 18, fontWeight: '700' }}>{t('shop.squadTitle')}</Text>
        <Text style={{ color: palette.ivory, fontSize: 15 }}>{hold.post.shop?.product.title}</Text>
        <Text style={{ color: palette.muted, fontSize: 14 }}>{t('shop.squadSolo', { price: formatMoney(hold.solo) })}</Text>
        <Text style={{ color: palette.goldSoft, fontSize: 16, fontWeight: '700' }}>{t('shop.squadGroup', { price: formatMoney(hold.squad) })}</Text>
        <Text style={{ color: palette.ivory, fontSize: 14 }}>{t('shop.squadWindow', { time: formatRemaining(left) })}</Text>
        <Text style={{ color: palette.muted, fontSize: 13 }}>{t('shop.squadMembers', { count: String(hold.members) })}</Text>
        <Pressable
          accessibilityRole="link"
          disabled={share.isPending}
          onPress={() => {
            void onShareLink();
          }}
          style={{ paddingVertical: 12, paddingHorizontal: 14, borderRadius: radius.chip, backgroundColor: palette.gold, opacity: share.isPending ? 0.6 : 1 }}
        >
          <Text style={{ color: palette.ink, fontSize: 14, fontWeight: '700' }}>{url ?? t('shop.shareLink')}</Text>
        </Pressable>
        {share.isPending ? <ActivityIndicator color={palette.goldSoft} /> : null}
        {tracked ? <Text style={{ color: palette.goldSoft, fontSize: 13 }}>{t('shop.shareTracked')}</Text> : null}
        {error ? <Text style={{ color: palette.ivory, fontSize: 13 }}>{error}</Text> : null}
        <Pressable accessibilityRole="button" onPress={onClose}>
          <Text style={{ color: palette.muted, fontSize: 14 }}>{t('shop.close')}</Text>
        </Pressable>
      </View>
    </View>
  );
}

async function presentShare(post: FeedPost, url: string): Promise<void> {
  const caption = post.caption?.trim() || post.shop?.product.title || t('reels.shareFallback');
  try {
    await Share.share({ message: t('reels.shareMessage', { caption, url }) });
  } catch {
    // The sheet was dismissed. The referral click is already recorded.
  }
}

function formatRemaining(seconds: number): string {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  if (hours > 0) {
    return `${hours}h ${minutes}m`;
  }
  return `${minutes}m`;
}

function useCountdown(endsAt: string): number {
  const [seconds, setSeconds] = useState(() => remaining(endsAt));
  useEffect(() => {
    setSeconds(remaining(endsAt));
    const timer = setInterval(() => setSeconds(remaining(endsAt)), 1000);
    return () => clearInterval(timer);
  }, [endsAt]);
  return seconds;
}

function remaining(endsAt: string): number {
  const normalized = endsAt.includes('T') ? endsAt : endsAt.replace(' ', 'T');
  const zoned = /[+-]\d{2}$/.test(normalized) ? `${normalized}:00` : normalized;
  const parsed = Date.parse(zoned);
  if (Number.isNaN(parsed)) {
    return 24 * 3600;
  }
  return Math.max(0, Math.ceil((parsed - Date.now()) / 1000));
}
