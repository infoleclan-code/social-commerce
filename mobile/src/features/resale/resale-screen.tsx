import { Tag } from 'lucide-react-native';

import { EmptyHero } from '@/components/ui/empty-hero';
import { Screen } from '@/components/ui/screen';
import { t } from '@/lib/i18n';

export function ResaleScreen() {
  return (
    <Screen>
      <EmptyHero body={t('resale.body')} eyebrow={t('resale.title')} icon={Tag} title={t('resale.title')} />
    </Screen>
  );
}
