import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import type { ReactNode } from 'react';
import { View, type StyleProp, type ViewStyle } from 'react-native';

import { elevation, glass, palette, radius, scrim } from '@/theme/tokens';

type Props = {
  image: string;
  children: ReactNode;
  style?: StyleProp<ViewStyle>;
};

export function MediaCard({ image, children, style }: Props) {
  return (
    <View
      style={[
        {
          overflow: 'hidden',
          borderRadius: radius.card,
          borderWidth: glass.borderWidth,
          borderColor: glass.borderColor,
          backgroundColor: palette.ink,
          boxShadow: elevation.insetSoft,
        },
        style,
      ]}
    >
      <Image
        contentFit="cover"
        source={{ uri: image }}
        style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }}
        transition={240}
      />
      <LinearGradient
        colors={scrim.media}
        locations={[0.28, 0.62, 1]}
        style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }}
      />
      <View style={{ flex: 1, justifyContent: 'flex-end' }}>{children}</View>
    </View>
  );
}
