import { Redirect } from 'expo-router';
import type { ReactNode } from 'react';

import { purchasesEnabled } from '@/lib/flags';

/** Purchase screens leave the navigation tree for the launch build. */
export function PurchasesGate({ children }: { children: ReactNode }) {
  if (!purchasesEnabled) {
    return <Redirect href="/compare" />;
  }

  return children;
}
