import { create } from 'zustand';

export type ClosetPurchase = {
  id: string;
  title: string;
  image: string;
  variant: string;
  status: 'purchased';
  fulfillment: 'in_transit';
  orderedAt: string;
  price: { amount_minor: number; currency: string; minor_unit: number } | null;
};

type ClosetState = {
  items: readonly ClosetPurchase[];
  trackingId: string | null;
  addPurchased: (item: ClosetPurchase) => void;
  track: (id: string) => void;
  closeTracking: () => void;
};

/** Private purchases. A checkout key is appended once. */
export const useClosetStore = create<ClosetState>((set, get) => ({
  items: [],
  trackingId: null,
  addPurchased: (item) => {
    if (get().items.some((owned) => owned.id === item.id)) {
      return;
    }
    set({ items: [item, ...get().items] });
  },
  track: (id) => {
    set({ trackingId: id });
  },
  closeTracking: () => {
    set({ trackingId: null });
  },
}));
