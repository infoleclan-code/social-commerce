import * as Haptics from 'expo-haptics';
import { Platform } from 'react-native';

/**
 * The app's haptic vocabulary.
 *
 * - iOS: UIFeedbackGenerator through expo-haptics. iOS stays silent in Low Power Mode, while the
 *   camera or dictation is active, or when the user has turned system haptics off.
 * - Android: View.performHapticFeedback through performAndroidHapticsAsync. It needs no VIBRATE
 *   permission and honours the system touch-feedback setting. Constants that need a newer API
 *   level fall back to ones every Android version has.
 * - Web: the Vibration API where the browser allows it. High-frequency ticks are skipped so a
 *   drag never buzzes continuously.
 *
 * Every call is fire-and-forget: feedback never blocks, throws, or delays the interaction it decorates.
 */

type AndroidPair = readonly [preferred: Haptics.AndroidHaptics, fallback: Haptics.AndroidHaptics];

function ignore(task: Promise<void>): void {
  task.catch(() => undefined);
}

function android([preferred, fallback]: AndroidPair): void {
  Haptics.performAndroidHapticsAsync(preferred).catch(() => {
    ignore(Haptics.performAndroidHapticsAsync(fallback));
  });
}

const ANDROID = {
  tick: [Haptics.AndroidHaptics.Segment_Tick, Haptics.AndroidHaptics.Clock_Tick],
  dragStart: [Haptics.AndroidHaptics.Drag_Start, Haptics.AndroidHaptics.Keyboard_Tap],
  lock: [Haptics.AndroidHaptics.Toggle_On, Haptics.AndroidHaptics.Virtual_Key],
  release: [Haptics.AndroidHaptics.Gesture_End, Haptics.AndroidHaptics.Clock_Tick],
  success: [Haptics.AndroidHaptics.Confirm, Haptics.AndroidHaptics.Long_Press],
  error: [Haptics.AndroidHaptics.Reject, Haptics.AndroidHaptics.Long_Press],
} as const satisfies Record<string, AndroidPair>;

/** Light detent: chips, toggles, slider checkpoints. */
export function playSelection(): void {
  if (Platform.OS === 'android') {
    android(ANDROID.tick);
    return;
  }
  if (Platform.OS === 'web') {
    return;
  }
  ignore(Haptics.selectionAsync());
}

/** A finger lands on a draggable control. */
export function playDragStart(): void {
  if (Platform.OS === 'android') {
    android(ANDROID.dragStart);
    return;
  }
  if (Platform.OS === 'web') {
    return;
  }
  ignore(Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light));
}

/** Something snaps into place: the scanner locking on, a slider hitting its end stop. */
export function playLock(): void {
  if (Platform.OS === 'android') {
    android(ANDROID.lock);
    return;
  }
  ignore(Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Rigid));
}

/** Released before the end stop: the control springs back. */
export function playRelease(): void {
  if (Platform.OS === 'android') {
    android(ANDROID.release);
    return;
  }
  if (Platform.OS === 'web') {
    return;
  }
  ignore(Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Soft));
}

/** A purchase, top-up or withdrawal went through. */
export function playSuccess(): void {
  if (Platform.OS === 'android') {
    android(ANDROID.success);
    return;
  }
  ignore(Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success));
}

/** Something was refused: invalid amount, nothing to withdraw. */
export function playError(): void {
  if (Platform.OS === 'android') {
    android(ANDROID.error);
    return;
  }
  ignore(Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error));
}
