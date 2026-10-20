import { create } from 'zustand';

type ReelSocialState = {
  likedIds: Record<string, true>;
  savedIds: Record<string, true>;
  comments: Record<string, string[]>;
  toggleLike: (id: string) => void;
  toggleSaved: (id: string) => void;
  addComment: (id: string, body: string) => void;
};

/** Local reel reactions. Not persisted, and not a purchase. */
export const useReelSocialStore = create<ReelSocialState>((set, get) => ({
  likedIds: {},
  savedIds: {},
  comments: {},
  toggleLike: (id) => {
    const likedIds = { ...get().likedIds };
    if (likedIds[id]) {
      delete likedIds[id];
    } else {
      likedIds[id] = true;
    }
    set({ likedIds });
  },
  toggleSaved: (id) => {
    const savedIds = { ...get().savedIds };
    if (savedIds[id]) {
      delete savedIds[id];
    } else {
      savedIds[id] = true;
    }
    set({ savedIds });
  },
  addComment: (id, body) => {
    const trimmed = body.trim().slice(0, 180);
    if (!trimmed) {
      return;
    }
    const current = get().comments[id] ?? [];
    set({
      comments: { ...get().comments, [id]: [...current, trimmed] },
    });
  },
}));
