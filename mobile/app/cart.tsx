import { useRouter } from 'expo-router';

import { CartScreen } from '@/features/cart/cart-screen';
import { goBackOrTabs } from '@/lib/go-back';
import { PurchasesGate } from '@/lib/purchases-gate';

export default function CartRoute() {
  const router = useRouter();
  return (
    <PurchasesGate>
      <CartScreen onBack={() => goBackOrTabs(router)} />
    </PurchasesGate>
  );
}
