import { CardsScreen } from '@/features/admin/cards-screen';
import { PurchasesGate } from '@/lib/purchases-gate';

export default function CardsRoute() {
  return (
    <PurchasesGate>
      <CardsScreen />
    </PurchasesGate>
  );
}
