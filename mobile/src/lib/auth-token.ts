import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * The Sanctum bearer token, in the platform keystore.
 *
 * iOS: stored WHEN_UNLOCKED_THIS_DEVICE_ONLY, so it is unreadable while the phone is locked and is never
 * copied into an iCloud/iTunes backup or restored onto another device. expo-secure-store only applies
 * the accessibility when an item is created (an update keeps the old one), so a token saved by an older
 * build under the default (WHEN_UNLOCKED, which migrates with backups) is moved once to a new item.
 * Android: Keystore-backed; the expo-secure-store config plugin excludes it from Auto Backup.
 */
const AUTH_TOKEN_KEY = 'auth_token.v2';
const LEGACY_AUTH_TOKEN_KEY = 'auth_token';
const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };

/** Sanctum tokens are `<id>|<40+ chars>`; anything else (whitespace, CR/LF, a pasted JSON) is not a token. */
const TOKEN_PATTERN = /^[A-Za-z0-9|._~+/=-]{20,512}$/;

function validToken(value: string | null | undefined): string | null {
  const trimmed = value?.trim();
  return trimmed && TOKEN_PATTERN.test(trimmed) ? trimmed : null;
}

/**
 * Development-only bearer token, so the API-backed screens (Reels, Scan, 1-Tap) can be exercised before
 * a sign-in flow exists. Inside `if (__DEV__)`, which is `false` in release builds: the bundler drops the
 * branch and the value never ships. Never set EXPO_PUBLIC_DEV_AUTH_TOKEN for a release build.
 */
function developmentToken(): string | null {
  if (__DEV__) {
    return validToken(process.env.EXPO_PUBLIC_DEV_AUTH_TOKEN);
  }
  return null;
}

async function store(token: string): Promise<void> {
  // Delete first: setItemAsync on an existing item keeps that item's (possibly weaker) accessibility.
  await SecureStore.deleteItemAsync(AUTH_TOKEN_KEY, OPTIONS);
  await SecureStore.setItemAsync(AUTH_TOKEN_KEY, token, OPTIONS);
}

let migration: Promise<void> | null = null;

/** Moves a token saved by an older build to the device-only item, once per launch at most. */
function migrateLegacyToken(): Promise<void> {
  if (Platform.OS === 'web') {
    return Promise.resolve();
  }
  migration ??= (async () => {
    try {
      const legacy = validToken(await SecureStore.getItemAsync(LEGACY_AUTH_TOKEN_KEY));
      if (legacy && !(await SecureStore.getItemAsync(AUTH_TOKEN_KEY, OPTIONS))) {
        await store(legacy);
      }
      await SecureStore.deleteItemAsync(LEGACY_AUTH_TOKEN_KEY);
    } catch {
      // Locked keychain right after a reboot, or no SecureStore (web): try again on the next launch.
      migration = null;
    }
  })();
  return migration;
}

/** The signed-in user's Sanctum token, or null. Never throws: an unreadable store means "signed out". */
export async function readAuthToken(): Promise<string | null> {
  if (Platform.OS === 'web') {
    // No keystore in the browser: the web build only ever uses the development token.
    return developmentToken();
  }
  await migrateLegacyToken();
  try {
    const stored = validToken(await SecureStore.getItemAsync(AUTH_TOKEN_KEY, OPTIONS));
    if (stored) {
      return stored;
    }
  } catch {
    // SecureStore is unavailable on web and can be locked right after a device reboot.
  }
  return developmentToken();
}

export async function writeAuthToken(token: string): Promise<void> {
  const valid = validToken(token);
  if (!valid) {
    throw new Error('Refusing to store a malformed auth token.');
  }
  await store(valid);
  await SecureStore.deleteItemAsync(LEGACY_AUTH_TOKEN_KEY).catch(() => undefined);
}

export async function clearAuthToken(): Promise<void> {
  await Promise.all([
    SecureStore.deleteItemAsync(AUTH_TOKEN_KEY, OPTIONS),
    SecureStore.deleteItemAsync(LEGACY_AUTH_TOKEN_KEY),
  ]);
}
