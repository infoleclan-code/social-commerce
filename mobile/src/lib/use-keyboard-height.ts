import { useEffect } from 'react';
import { Keyboard, Platform, type KeyboardEvent } from 'react-native';
import { Easing, useSharedValue, withTiming, type SharedValue } from 'react-native-reanimated';

/**
 * Height of the software keyboard as a shared value, animated with the keyboard's own duration on iOS.
 * Works with Android edge-to-edge (where the window no longer resizes for the keyboard) because the
 * height comes from the keyboard event, not from a window resize.
 */
export function useKeyboardHeight(): SharedValue<number> {
  const height = useSharedValue(0);

  useEffect(() => {
    const showEvent = Platform.OS === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow';
    const hideEvent = Platform.OS === 'ios' ? 'keyboardWillHide' : 'keyboardDidHide';

    const onShow = (event: KeyboardEvent) => {
      height.set(withTiming(event.endCoordinates.height, { duration: event.duration || 220, easing: Easing.out(Easing.cubic) }));
    };
    const onHide = (event: KeyboardEvent) => {
      height.set(withTiming(0, { duration: event.duration || 200, easing: Easing.out(Easing.cubic) }));
    };

    const shown = Keyboard.addListener(showEvent, onShow);
    const hidden = Keyboard.addListener(hideEvent, onHide);
    return () => {
      shown.remove();
      hidden.remove();
    };
  }, [height]);

  return height;
}
