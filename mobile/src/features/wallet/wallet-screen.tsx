import { Text, View } from 'react-native';

import { DisplayText } from '@/components/ui/display-text';
import { Eyebrow } from '@/components/ui/eyebrow';
import { Reveal } from '@/components/ui/reveal';
import { Screen } from '@/components/ui/screen';
import { Surface } from '@/components/ui/surface';
import { previewActivity, previewWallet } from '@/features/fixtures';
import { ReferEarn } from '@/features/wallet/refer-earn';
import { WalletActions } from '@/features/wallet/wallet-actions';
import { formatExpectedDate } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { useWalletStore } from '@/stores/wallet';

export function WalletScreen() {
  const expected = formatExpectedDate(previewWallet.expectedOn);
  const available = useWalletStore((state) => state.available);
  const ledger = useWalletStore((state) => state.ledger);

  return (
    <Screen>
      <Reveal>
        <Eyebrow>{t('wallet.eyebrow')}</Eyebrow>
        <DisplayText className="mt-3 text-[40px] leading-[46px] text-ivory">{t('wallet.title')}</DisplayText>
        <Text className="mt-4 text-[15px] leading-6 text-muted">{t('wallet.hold')}</Text>
      </Reveal>

      <Reveal delay={70}>
        {/* Balance and the actions that move it read as one unit: the suite sits directly under the card. */}
        <View className="gap-3">
          <Surface>
            <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">
              {t('cashback.available')}
            </Text>
            <DisplayText accessibilityLiveRegion="polite" className="mt-2 text-[36px] leading-10 text-ivory">
              {formatMoney(available)}
            </DisplayText>
            <Text className="mt-3 text-[13px] text-faint">{t('wallet.earned')}</Text>
          </Surface>
          <WalletActions />
        </View>
      </Reveal>

      <Reveal delay={110}>
        <Surface>
          <ReferEarn />
        </Surface>
      </Reveal>

      <Reveal delay={150}>
        <Surface>
          <Text className="text-[13px] text-gold-soft">{t('cashback.pending', { date: expected })}</Text>
          <Text className="mt-2 text-[28px] text-muted">{formatMoney(previewWallet.pending)}</Text>
          <View className="mt-4 h-px bg-line" />
          <Text className="mt-4 text-[13px] leading-5 text-faint">{t('cashback.pendingNote')}</Text>
        </Surface>
      </Reveal>

      <Reveal delay={190}>
        <Surface>
          <Eyebrow>{t('wallet.history')}</Eyebrow>
          {ledger.map((entry) => (
            <View className="mt-4 border-t border-line pt-4" key={entry.id}>
              <View className="flex-row items-center justify-between gap-3">
                <Text className="flex-1 text-[15px] font-medium text-ivory">{entry.title}</Text>
                <Text className="text-[14px] text-ivory">
                  {entry.type === 'purchase' || entry.type === 'withdrawal' ? `-${formatMoney(entry.amount)}` : formatMoney(entry.amount)}
                </Text>
              </View>
              <Text className="mt-1 text-[12px] text-gold-soft">
                {entry.type === 'referral'
                  ? t('wallet.referralReward')
                  : entry.type === 'withdrawal'
                    ? t('wallet.withdrawal')
                    : entry.type === 'purchase'
                      ? t('wallet.purchase')
                      : t('wallet.topUp')}{' '}
                · {entry.paymentMethod} · {entry.status}
              </Text>
            </View>
          ))}
          {previewActivity.map((entry) => (
            <View className="mt-4 border-t border-line pt-4" key={entry.id}>
              <View className="flex-row items-center justify-between gap-3">
                <Text className="flex-1 text-[15px] font-medium text-ivory">{t(entry.titleKey)}</Text>
                <Text className={entry.kind === 'available' ? 'text-[14px] text-ivory' : 'text-[14px] text-muted'}>
                  {formatMoney(entry.amount)}
                </Text>
              </View>
              <Text className="mt-1 text-[12px] text-faint">
                {entry.kind === 'available'
                  ? t('cashback.available')
                  : t('cashback.pending', { date: formatExpectedDate(entry.on) })}
              </Text>
            </View>
          ))}
        </Surface>
      </Reveal>
    </Screen>
  );
}
