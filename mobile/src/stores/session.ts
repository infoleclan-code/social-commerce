import { create } from 'zustand';

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
    set({ ready: true, signedIn: token !== null });
  },
  signIn: async (token) => {
    await writeAuthToken(token);
    set({ ready: true, signedIn: true });
  },
  signOut: async () => {
    await clearAuthToken();
    set({ signedIn: false, ready: true });
  },
}));
