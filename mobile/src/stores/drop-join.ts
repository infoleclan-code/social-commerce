import { create } from 'zustand';

type DropJoinState = {
  joinedIds: Record<string, true>;
  join: (slotId: string) => void;
};

/** Session-only slot reservation. Not a payment, and not persisted. */
export const useDropJoinStore = create<DropJoinState>((set, get) => ({
  joinedIds: {},
  join: (slotId) => {
    if (get().joinedIds[slotId]) {
      return;
    }
    set((state) => ({
      joinedIds: { ...state.joinedIds, [slotId]: true },
    }));
  },
}));

export function displayedPurchased(base: number, goal: number, joined: boolean): number {
  if (!joined) {
    return base;
  }
  return Math.min(goal, base + 1);
}
