import { ChevronLeft, ShoppingBag } from 'lucide-react-native';

import { EmptyHero } from '@/components/ui/empty-hero';
import { PressableScale } from '@/components/ui/pressable-scale';
import { Reveal } from '@/components/ui/reveal';
import { Screen } from '@/components/ui/screen';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

type Props = {
  onBack: () => void;
};

export function CartScreen({ onBack }: Props) {
  return (
    <Screen tabBar={false}>
      <Reveal>
        <PressableScale
          accessibilityLabel={t('cart.back')}
          onPress={onBack}
          className="h-11 w-11 items-center justify-center rounded-full border border-line bg-raised"
        >
          <ChevronLeft color={palette.ivory} size={20} strokeWidth={1.5} />
        </PressableScale>
      </Reveal>
      <Reveal delay={60}>
        <EmptyHero body={t('cart.body')} eyebrow={t('cart.title')} icon={ShoppingBag} title={t('cart.empty')} />
      </Reveal>
    </Screen>
  );
}
