import type { ReactNode } from 'react';
import { Easing, Platform } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';

type Props = {
  children: ReactNode;
  delay?: number;
};

export function Reveal({ children, delay = 0 }: Props) {
  return (
    // Web layout animations only accept Reanimated's built-in easings, so the custom curve is native-only.
    <Animated.View
      entering={
        Platform.OS === 'web' ? FadeInDown.duration(560).delay(delay) : FadeInDown.duration(560).delay(delay).easing(Easing.out(Easing.cubic))
      }
    >
      {children}
    </Animated.View>
  );
}
