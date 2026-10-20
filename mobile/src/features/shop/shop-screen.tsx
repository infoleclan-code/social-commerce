import { ShoppingBag } from 'lucide-react-native';
import { Text, View } from 'react-native';

import { DisplayText } from '@/components/ui/display-text';
import { Eyebrow } from '@/components/ui/eyebrow';
import { PressableScale } from '@/components/ui/pressable-scale';
import { ProgressBar } from '@/components/ui/progress-bar';
import { Reveal } from '@/components/ui/reveal';
import { Screen } from '@/components/ui/screen';
import { Surface } from '@/components/ui/surface';
import { previewDrops, previewWallet } from '@/features/fixtures';
import { formatExpectedDate } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette } from '@/theme/tokens';

type Props = {
  onOpenCart: () => void;
};

export function ShopScreen({ onOpenCart }: Props) {
  const expected = formatExpectedDate(previewWallet.expectedOn);

  return (
    <Screen>
      <Reveal>
        <View className="flex-row items-start justify-between">
          <View className="flex-1 pr-4">
            <Eyebrow>{t('shop.eyebrow')}</Eyebrow>
            <DisplayText className="mt-3 text-[40px] leading-[46px] text-ivory">
              {t('shop.title')}
            </DisplayText>
          </View>
          <PressableScale
            accessibilityLabel={t('shop.cartA11y')}
            onPress={onOpenCart}
            className="h-11 w-11 items-center justify-center rounded-full border border-line bg-raised"
          >
            <ShoppingBag color={palette.ivory} size={18} strokeWidth={1.5} />
          </PressableScale>
        </View>
        <Text className="mt-4 text-[15px] leading-6 text-muted">{t('shop.body')}</Text>
      </Reveal>

      <Reveal delay={90}>
        <Surface>
          <Text className="text-[13px] text-muted">{t('cashback.available')}</Text>
          <DisplayText className="mt-2 text-[32px] leading-9 text-ivory">
            {formatMoney(previewWallet.available)}
          </DisplayText>
          <View className="my-5 h-px bg-line" />
          <Text className="text-[13px] text-gold-soft">
            {t('cashback.pending', { date: expected })}
          </Text>
          <Text className="mt-2 text-[22px] text-muted">{formatMoney(previewWallet.pending)}</Text>
          <Text className="mt-3 text-[13px] leading-5 text-faint">{t('cashback.pendingNote')}</Text>
        </Surface>
      </Reveal>

      <Reveal delay={160}>
        <View className="gap-3">
          <Eyebrow>{t('shop.dropsHeading')}</Eyebrow>
          {previewDrops.map((drop) => (
            <Surface key={drop.id}>
              <Text className="text-[17px] text-ivory">{drop.title}</Text>
              <Text className="mt-1 text-[13px] text-muted">{drop.detail}</Text>
              <View className="mt-4">
                <ProgressBar ratio={drop.purchased / drop.goal} />
              </View>
              <Text className="mt-3 text-[13px] text-faint">
                {t('shop.dropPurchased', { count: String(drop.purchased) })}
              </Text>
            </Surface>
          ))}
        </View>
      </Reveal>
    </Screen>
  );
}
