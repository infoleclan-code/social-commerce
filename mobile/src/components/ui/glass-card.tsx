import { BlurTargetView, BlurView } from 'expo-blur';
import { type ReactNode, useRef } from 'react';
import { View, type StyleProp, type ViewStyle } from 'react-native';

import { blur } from '@/theme/tokens';

type Props = {
  children: ReactNode;
  backdrop: ReactNode;
  style?: StyleProp<ViewStyle>;
  panelStyle?: StyleProp<ViewStyle>;
};

export function GlassCard({ children, backdrop, style, panelStyle }: Props) {
  const targetRef = useRef<View>(null);

  return (
    <View className="overflow-hidden rounded-3xl border border-ivory/10" style={style}>
      <BlurTargetView ref={targetRef} style={{ flex: 1 }}>
        {backdrop}
      </BlurTargetView>
      <BlurView
        blurMethod="dimezisBlurViewSdk31Plus"
        blurTarget={targetRef}
        intensity={blur.card}
        tint="dark"
        style={[{ position: 'absolute', left: 0, right: 0, bottom: 0 }, panelStyle]}
      >
        {children}
      </BlurView>
    </View>
  );
}
