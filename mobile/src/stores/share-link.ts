import { create } from 'zustand';

type ShareLinkState = {
  token: string | null;
  setToken: (token: string | null) => void;
};

/** Pending share-link token only. Attribution is resolved on the server. */
export const useShareLinkStore = create<ShareLinkState>((set) => ({
  token: null,
  setToken: (token) => {
    set({ token });
  },
}));
