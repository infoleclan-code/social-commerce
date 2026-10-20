import type { ReactNode } from 'react';
import { ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AmbientGlow } from '@/components/ui/ambient-glow';

type Props = {
  children: ReactNode;
  tabBar?: boolean;
};

export function Screen({ children, tabBar = true }: Props) {
  const insets = useSafeAreaInsets();

  return (
    <View className="flex-1 bg-ink">
      <AmbientGlow />
      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={{
          paddingTop: insets.top + 20,
          paddingBottom: insets.bottom + (tabBar ? 112 : 32),
          paddingHorizontal: 20,
          gap: 28,
        }}
      >
        {children}
      </ScrollView>
    </View>
  );
}
