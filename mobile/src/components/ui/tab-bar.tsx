import { BlurTargetView, BlurView } from 'expo-blur';
import type { BottomTabBarProps } from 'expo-router/js-tabs';
import { Clapperboard, Hash, Layers, ScanSearch, Shirt, UserRound, Wallet, type LucideIcon } from 'lucide-react-native';
import { useEffect, useRef } from 'react';
import { Pressable, Text, View } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withSpring } from 'react-native-reanimated';

import { blur, elevation, glass, motion, palette, radius } from '@/theme/tokens';

const HIDDEN_TABS = new Set(['index', 'drops', 'closet', 'wallet']);

const ICONS: Record<string, LucideIcon> = {
  index: ScanSearch,
  reels: Clapperboard,
  explore: Hash,
  drops: Layers,
  closet: Shirt,
  wallet: Wallet,
  profile: UserRound,
};

export function TabBar({ state, descriptors, navigation, insets }: BottomTabBarProps) {
  const targetRef = useRef<View>(null);

  return (
    <View style={{ backgroundColor: 'transparent', paddingHorizontal: 14, paddingBottom: Math.max(insets.bottom, 10) }}>
      <View
        style={{
          borderRadius: radius.sheet,
          overflow: 'hidden',
          borderWidth: glass.borderWidth,
          borderColor: glass.borderColor,
          backgroundColor: glass.backgroundColor,
          boxShadow: elevation.inset,
        }}
      >
        <BlurTargetView ref={targetRef} style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, pointerEvents: 'none' }} />
        <BlurView
          blurMethod="dimezisBlurViewSdk31Plus"
          blurTarget={targetRef}
          intensity={blur.bar}
          tint="dark"
          style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, pointerEvents: 'none' }}
        />
        <View className="flex-row px-1 py-1.5">
          {state.routes.map((route, index) => {
            const { options } = descriptors[route.key];
            if (HIDDEN_TABS.has(route.name)) {
              return null;
            }
            const focused = state.index === index;
            const label = options.title ?? route.name;
            const Icon = ICONS[route.name] ?? ScanSearch;

            return (
              <TabButton
                key={route.key}
                focused={focused}
                icon={Icon}
                label={label}
                onLongPress={() => {
                  navigation.emit({ type: 'tabLongPress', target: route.key });
                }}
                onPress={() => {
                  const event = navigation.emit({
                    type: 'tabPress',
                    target: route.key,
                    canPreventDefault: true,
                  });
                  if (!focused && !event.defaultPrevented) {
                    navigation.navigate(route.name, route.params);
                  }
                }}
              />
            );
          })}
        </View>
      </View>
    </View>
  );
}

type TabButtonProps = {
  label: string;
  focused: boolean;
  icon: LucideIcon;
  onPress: () => void;
  onLongPress: () => void;
};

function TabButton({ label, focused, icon: Icon, onPress, onLongPress }: TabButtonProps) {
  const scale = useSharedValue(focused ? 1 : motion.iconPress);
  const pressed = useSharedValue(1);

  useEffect(() => {
    scale.value = withSpring(focused ? 1 : motion.iconPress, motion.spring);
  }, [focused, scale]);

  const iconStyle = useAnimatedStyle(() => ({
    transform: [{ scale: scale.value * pressed.value }],
  }));

  return (
    <Pressable
      accessibilityLabel={label}
      accessibilityRole="button"
      accessibilityState={{ selected: focused }}
      onLongPress={onLongPress}
      onPress={onPress}
      onPressIn={() => {
        pressed.value = withSpring(motion.press, motion.spring);
      }}
      onPressOut={() => {
        pressed.value = withSpring(1, motion.spring);
      }}
      className="flex-1 items-center py-1.5"
    >
      <Animated.View style={iconStyle} className="items-center">
        <View
          style={{
            minWidth: 44,
            alignItems: 'center',
            borderRadius: radius.chip,
            paddingHorizontal: 10,
            paddingVertical: 4,
            backgroundColor: focused ? palette.goldWash : 'transparent',
          }}
        >
          <Icon color={focused ? palette.goldSoft : palette.faint} size={20} strokeWidth={1.6} />
        </View>
      </Animated.View>
      <Text
        style={{ letterSpacing: 0.4, fontWeight: focused ? '600' : '500' }}
        className={`mt-0.5 text-[10px] ${focused ? 'text-ivory' : 'text-faint'}`}
      >
        {label}
      </Text>
    </Pressable>
  );
}
