import { Share } from 'react-native';

import { demoMoneyEnabled } from '@/lib/demo-mode';
import { playSuccess } from '@/lib/feedback';
import { useWalletStore } from '@/stores/wallet';

export const REFERRAL_CODE = 'NORD10';
export const REFERRAL_REWARD_MINOR = 1_000;

const LINK = `https://social.example/s/${REFERRAL_CODE}`;

export type ShareChannel = 'instagram' | 'tiktok' | 'imessage';

export function referralLink(channel: ShareChannel): string {
  if (channel === 'instagram') {
    return `${LINK}?src=instagram-story`;
  }
  if (channel === 'tiktok') {
    return `${LINK}?src=tiktok`;
  }
  return `${LINK}?src=imessage`;
}

export function referralMessage(channel: ShareChannel): string {
  const link = referralLink(channel);
  if (channel === 'instagram') {
    return `Join my drop circle. ${link}`;
  }
  if (channel === 'tiktok') {
    return `Find the dupe with me. ${link}`;
  }
  return link;
}

export async function shareReferral(channel: ShareChannel): Promise<void> {
  await Share.share({ message: referralMessage(channel), url: referralLink(channel) });
}

/** Same €10 credit the referral claim route posts. One credit per code. Demo builds only. */
export function claimReferralReward(): 'ok' | 'duplicate' | 'unavailable' {
  if (!demoMoneyEnabled) {
    return 'unavailable';
  }
  const result = useWalletStore.getState().credit(
    `referral:${REFERRAL_CODE}`,
    { amount_minor: REFERRAL_REWARD_MINOR, currency: 'EUR', minor_unit: 2 },
    { title: 'Referral reward', paymentMethod: 'wallet' },
    'referral',
  );
  if (result === 'ok') {
    playSuccess();
  }
  return result;
}
