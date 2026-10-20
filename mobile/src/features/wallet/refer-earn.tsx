import { useState } from 'react';
import { Text, View } from 'react-native';

import { PressableScale } from '@/components/ui/pressable-scale';
import { SuccessBurst } from '@/components/ui/success-burst';
import { claimReferralReward, shareReferral, type ShareChannel } from '@/features/social/referral';
import { t } from '@/lib/i18n';

const CHANNELS: ShareChannel[] = ['instagram', 'tiktok', 'imessage'];

export function ReferEarn() {
  const [burst, setBurst] = useState(false);

  return (
    <View className="gap-3">
      <Text className="text-[20px] font-medium text-ivory">{t('wallet.referTitle')}</Text>
      <Text className="text-[13px] leading-5 text-muted">{t('wallet.referBody')}</Text>
      {burst ? (
        <View className="items-center py-2">
          <SuccessBurst />
        </View>
      ) : null}
      <PressableScale
        accessibilityLabel={t('wallet.referClaim')}
        onPress={() => {
          const result = claimReferralReward();
          if (result === 'ok') {
            setBurst(true);
          }
        }}
      >
        <Text className="text-[14px] font-semibold text-gold-soft">{t('wallet.referClaim')}</Text>
      </PressableScale>
      <View className="flex-row flex-wrap gap-3">
        {CHANNELS.map((channel) => {
          const label =
            channel === 'instagram' ? t('social.instagram') : channel === 'tiktok' ? t('social.tiktok') : t('social.imessage');
          return (
            <PressableScale
              accessibilityLabel={label}
              key={channel}
              onPress={() => {
                void shareReferral(channel);
              }}
            >
              <Text className="text-[13px] font-semibold text-ivory">{label}</Text>
            </PressableScale>
          );
        })}
      </View>
    </View>
  );
}
