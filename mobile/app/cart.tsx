import { useRouter } from 'expo-router';

import { CartScreen } from '@/features/cart/cart-screen';
import { goBackOrTabs } from '@/lib/go-back';

export default function CartRoute() {
  const router = useRouter();
  return <CartScreen onBack={() => goBackOrTabs(router)} />;
}
