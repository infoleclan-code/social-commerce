import { create } from 'zustand';

import { feedProducts, variantsFor } from '@/features/feed/fixtures';

type Phase = 'idle' | 'scanning' | 'results';

type DupeScanState = {
  phase: Phase;
  productId: string | null;
  variant: string;
  colorMatch: boolean;
  material: string;
  budgetMinor: number | null;
  startScan: (productId: string) => void;
  close: () => void;
  setVariant: (variant: string) => void;
  setColorMatch: (colorMatch: boolean) => void;
  setMaterial: (material: string) => void;
  setBudgetMinor: (budgetMinor: number | null) => void;
};

const SCAN_MS = 2200;
let scanTimer: ReturnType<typeof setTimeout> | null = null;

function clearScanTimer(): void {
  if (scanTimer) {
    clearTimeout(scanTimer);
    scanTimer = null;
  }
}

/** UI phase for the frame scan and dupe sheet. Not a purchase. */
export const useDupeScanStore = create<DupeScanState>((set, get) => ({
  phase: 'idle',
  productId: null,
  variant: 'M',
  colorMatch: false,
  material: 'all',
  budgetMinor: null,
  startScan: (productId) => {
    clearScanTimer();
    const product = feedProducts.find((item) => item.id === productId);
    const variants = product ? variantsFor(product.slotId) : ['M'];
    set({
      phase: 'scanning',
      productId,
      variant: variants[0] ?? 'M',
      colorMatch: false,
      material: 'all',
      budgetMinor: null,
    });
    scanTimer = setTimeout(() => {
      const current = get();
      if (current.phase === 'scanning' && current.productId === productId) {
        set({ phase: 'results' });
      }
    }, SCAN_MS);
  },
  close: () => {
    clearScanTimer();
    set({ phase: 'idle', productId: null });
  },
  setVariant: (variant) => {
    set({ variant });
  },
  setColorMatch: (colorMatch) => {
    set({ colorMatch });
  },
  setMaterial: (material) => {
    set({ material });
  },
  setBudgetMinor: (budgetMinor) => {
    set({ budgetMinor });
  },
}));
