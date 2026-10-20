import { create } from 'zustand';

type CommentsSheetState = {
  /** The post whose comments are open, or null. Only the id and count travel: the sheet loads the rest. */
  postId: string | null;
  open: (postId: string) => void;
  close: () => void;
};

/** UI state of the root-level comments sheet (rendered above the tab bar, like the other sheets). */
export const useCommentsSheetStore = create<CommentsSheetState>((set) => ({
  postId: null,
  open: (postId) => {
    set({ postId });
  },
  close: () => {
    set({ postId: null });
  },
}));
