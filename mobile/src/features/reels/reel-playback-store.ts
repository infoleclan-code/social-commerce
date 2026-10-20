import { Platform } from 'react-native';
import { create } from 'zustand';

type ReelPlaybackState = {
  /** One switch for the whole feed, like every short-video app. Web starts muted: browsers block autoplay with sound. */
  muted: boolean;
  toggleMuted: () => void;
};

export const useReelPlaybackStore = create<ReelPlaybackState>((set, get) => ({
  muted: Platform.OS === 'web',
  toggleMuted: () => {
    set({ muted: !get().muted });
  },
}));
