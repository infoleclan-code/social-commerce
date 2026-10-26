import { Platform } from 'react-native';
import { create } from 'zustand';

import { api } from '@/api/client';
import { registerPushDevice } from '@/features/auth/register-push';
import { clearAuthToken, readAuthToken, writeAuthToken } from '@/lib/auth-token';

type Session = {
  ready: boolean;
  signedIn: boolean;
  refresh: () => Promise<void>;
  signIn: (token: string) => Promise<void>;
  signOut: () => Promise<void>;
};

export const useSession = create<Session>((set) => ({
  ready: false,
  signedIn: false,
  refresh: async () => {
    const token = await readAuthToken();
    if (token) {
      set({ ready: true, signedIn: true });
      return;
    }
    if (Platform.OS === 'web') {
      try {
        await api('/me');
        set({ ready: true, signedIn: true });
        return;
      } catch {
        // No cookie session.
      }
    }
    set({ ready: true, signedIn: false });
  },
  signIn: async (token) => {
    await writeAuthToken(token);
    set({ ready: true, signedIn: true });
    void registerPushDevice();
  },
  signOut: async () => {
    await clearAuthToken();
    set({ signedIn: false, ready: true });
  },
}));
