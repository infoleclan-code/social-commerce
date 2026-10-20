import type { LucideIcon } from 'lucide-react-native';
import { Text, View } from 'react-native';

import { GlassSurface } from '@/components/ui/glass-surface';
import { elevation, palette, radius } from '@/theme/tokens';

type Props = {
  icon: LucideIcon;
  eyebrow: string;
  title: string;
  body: string;
};

export function EmptyHero({ icon: Icon, eyebrow, title, body }: Props) {
  return (
    <GlassSurface>
      <View className="items-center px-6 py-12">
        <View
          style={{
            height: 72,
            width: 72,
            alignItems: 'center',
            justifyContent: 'center',
            borderRadius: radius.sheet + 8,
            borderWidth: 1,
            borderColor: palette.goldRing,
            backgroundColor: palette.goldWashSoft,
            boxShadow: elevation.goldOrb,
          }}
        >
          <Icon color={palette.goldSoft} size={28} strokeWidth={1.25} />
        </View>
        <Text className="mt-6 text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{eyebrow}</Text>
        <Text className="mt-2 text-center text-[26px] font-medium text-ivory">{title}</Text>
        <Text className="mt-3 text-center text-[15px] leading-6 text-muted">{body}</Text>
      </View>
    </GlassSurface>
  );
}
