import Constants from 'expo-constants';
import { Platform } from 'react-native';

import { registerDevice } from '@/api/social-commerce';

let listening = false;
let deviceId: string | undefined;

/** The device row from the last successful registration, so logout can revoke it. */
export function currentPushDeviceId(): string | undefined {
  return deviceId;
}

/**
 * Asks for notification permission, then stores the Expo push token. A refresh of the token
 * registers again. Denial and a missing push project leave the session signed in.
 *
 * The notifications module is loaded here, not at startup: importing it during web
 * server rendering leaves `Platform` unset and the page fails before it can paint.
 */
export async function registerPushDevice(): Promise<void> {
  try {
    const Notifications = await import('expo-notifications');
    const existing = await Notifications.getPermissionsAsync();
    let status = existing.status;
    if (status !== 'granted') {
      status = (await Notifications.requestPermissionsAsync()).status;
    }
    if (status !== 'granted') {
      return;
    }
    const projectId = Constants.expoConfig?.extra?.eas?.projectId as string | undefined;
    const token = await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined);
    await send(token.data);
    if (!listening) {
      listening = true;
      Notifications.addPushTokenListener((next) => {
        void send(next.data);
      });
    }
  } catch {
    // A browser without a push project, or a simulator, still has a signed-in session.
  }
}

async function send(token: string): Promise<void> {
  if (token.length < 16) {
    return;
  }
  const platform = Platform.OS === 'ios' ? 'ios' : Platform.OS === 'android' ? 'android' : 'web';
  const saved = await registerDevice({ platform, expo_push_token: token });
  deviceId = saved.id;
}
