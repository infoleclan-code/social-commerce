import type { ReactNode } from 'react';
import { View } from 'react-native';

import { GlassSurface } from '@/components/ui/glass-surface';

type Props = {
  children: ReactNode;
};

export function Surface({ children }: Props) {
  return (
    <GlassSurface>
      <View className="px-5 py-5">{children}</View>
    </GlassSurface>
  );
}
