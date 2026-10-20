import type { ReactNode } from 'react';
import { Text, View } from 'react-native';

import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { playSelection } from '@/lib/feedback';
import { elevation, palette, radius } from '@/theme/tokens';

type Props = {
  label: string;
  icon: ReactNode;
  active?: boolean;
  onPress: () => void;
};

export function SearchActionPill({ label, icon, active = false, onPress }: Props) {
  return (
    <PressableScale
      accessibilityLabel={label}
      accessibilityState={{ selected: active }}
      onPress={() => {
        playSelection();
        onPress();
      }}
    >
      <GlassSurface
        radius={radius.pill}
        style={{
          borderColor: active ? palette.gold : palette.line,
          backgroundColor: active ? palette.goldWash : 'rgba(255, 255, 255, 0.06)',
          boxShadow: active ? elevation.gold : elevation.inset,
        }}
      >
        <View style={{ minHeight: 44, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 8 }}>
          {icon}
          <Text style={{ color: active ? palette.goldSoft : palette.ivory, fontSize: 14, fontWeight: '600' }}>{label}</Text>
        </View>
      </GlassSurface>
    </PressableScale>
  );
}
