import { create } from 'zustand';

export type SheetTarget = { kind: 'product'; id: string } | { kind: 'drop'; id: string };

type ProductSheetState = {
  target: SheetTarget | null;
  openProduct: (id: string) => void;
  openDrop: (id: string) => void;
  close: () => void;
};

/** Which detail sheet is open. UI only. */
export const useProductSheetStore = create<ProductSheetState>((set) => ({
  target: null,
  openProduct: (id) => {
    set({ target: { kind: 'product', id } });
  },
  openDrop: (id) => {
    set({ target: { kind: 'drop', id } });
  },
  close: () => {
    set({ target: null });
  },
}));
