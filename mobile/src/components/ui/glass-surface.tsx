import { BlurTargetView, BlurView } from 'expo-blur';
import { type ReactNode, useRef } from 'react';
import { View, type StyleProp, type ViewStyle } from 'react-native';

import { blur, elevation, glass, radius as radii } from '@/theme/tokens';

type Props = {
  children: ReactNode;
  style?: StyleProp<ViewStyle>;
  radius?: number;
};

export function GlassSurface({ children, style, radius = radii.panel }: Props) {
  const targetRef = useRef<View>(null);

  return (
    <View
      style={[
        glass,
        {
          borderRadius: radius,
          overflow: 'hidden',
          boxShadow: elevation.inset,
        },
        style,
      ]}
    >
      <BlurTargetView ref={targetRef} style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, pointerEvents: 'none' }} />
      <BlurView
        blurMethod="dimezisBlurViewSdk31Plus"
        blurTarget={targetRef}
        intensity={blur.glass}
        tint="dark"
        style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, pointerEvents: 'none' }}
      />
      {children}
    </View>
  );
}
