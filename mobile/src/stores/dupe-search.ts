import { create } from 'zustand';

import type { SearchCategory } from '@/features/feed/fixtures';

type DupeSearchState = {
  query: string;
  category: SearchCategory;
  overlayOpen: boolean;
  linkOpen: boolean;
  linkDraft: string;
  attachedLink: string | null;
  imageUri: string | null;
  setQuery: (query: string) => void;
  setCategory: (category: SearchCategory) => void;
  openOverlay: () => void;
  closeOverlay: () => void;
  setLinkOpen: (linkOpen: boolean) => void;
  setLinkDraft: (linkDraft: string) => void;
  applyLink: () => void;
  setImageUri: (imageUri: string | null) => void;
  clearSearch: () => void;
};

/** UI draft for visual search. Not persisted, and not an attribution or price calculation. */
export const useDupeSearchStore = create<DupeSearchState>((set, get) => ({
  query: '',
  category: 'all',
  overlayOpen: false,
  linkOpen: false,
  linkDraft: '',
  attachedLink: null,
  imageUri: null,
  setQuery: (query) => {
    set({ query });
  },
  setCategory: (category) => {
    set({ category });
  },
  openOverlay: () => {
    set({ overlayOpen: true });
  },
  closeOverlay: () => {
    set({ overlayOpen: false, linkOpen: false });
  },
  setLinkOpen: (linkOpen) => {
    set({ linkOpen });
  },
  setLinkDraft: (linkDraft) => {
    set({ linkDraft });
  },
  applyLink: () => {
    const attachedLink = get().linkDraft.trim();
    set({
      attachedLink: attachedLink.length > 0 ? attachedLink : null,
      linkOpen: false,
    });
  },
  setImageUri: (imageUri) => {
    set({ imageUri });
  },
  clearSearch: () => {
    set({
      query: '',
      category: 'all',
      linkDraft: '',
      attachedLink: null,
      imageUri: null,
      linkOpen: false,
    });
  },
}));
