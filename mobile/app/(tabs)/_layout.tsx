import { Tabs } from 'expo-router/js-tabs';

import { TabBar } from '@/components/ui/tab-bar';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

export const unstable_settings = {
  initialRouteName: 'compare',
};

export default function TabsLayout() {
  return (
    <Tabs
      initialRouteName="compare"
      tabBar={(props) => <TabBar {...props} />}
      screenOptions={{
        headerShown: false,
        sceneStyle: { backgroundColor: palette.ink },
      }}
    >
      <Tabs.Screen name="index" options={{ href: null }} />
      <Tabs.Screen name="compare" options={{ title: t('tab.compare') }} />
      <Tabs.Screen name="reels" options={{ title: t('tab.reels') }} />
      <Tabs.Screen name="explore" options={{ title: t('tab.explore') }} />
      <Tabs.Screen name="profile" options={{ title: t('tab.profile') }} />
      <Tabs.Screen name="drops" options={{ href: null }} />
      <Tabs.Screen name="closet" options={{ href: null }} />
      <Tabs.Screen name="wallet" options={{ href: null }} />
    </Tabs>
  );
}
