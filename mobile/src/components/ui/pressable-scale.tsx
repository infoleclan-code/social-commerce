import { Pressable, type PressableProps } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withSpring } from 'react-native-reanimated';

import { motion } from '@/theme/tokens';

type Props = PressableProps & {
  className?: string;
  block?: boolean;
  fill?: boolean;
};

export function PressableScale({
  className,
  block = false,
  fill = false,
  children,
  onPressIn,
  onPressOut,
  ...props
}: Props) {
  const scale = useSharedValue(1);
  const animatedStyle = useAnimatedStyle(() => ({
    transform: [{ scale: scale.value }],
  }));

  return (
    <Animated.View
      style={[
        animatedStyle,
        fill ? { flex: 1 } : block ? { alignSelf: 'stretch' } : { alignSelf: 'flex-start' },
      ]}
    >
      <Pressable
        accessibilityRole="button"
        className={className}
        style={fill ? { flex: 1 } : undefined}
        onPressIn={(event) => {
          scale.set(withSpring(motion.press, motion.spring));
          onPressIn?.(event);
        }}
        onPressOut={(event) => {
          scale.set(withSpring(1, motion.spring));
          onPressOut?.(event);
        }}
        {...props}
      >
        {children}
      </Pressable>
    </Animated.View>
  );
}
