import { useRouter } from 'expo-router';

import { VisualScannerScreen } from '@/features/scanner/visual-scanner-screen';
import { goBackOrTabs } from '@/lib/go-back';

export default function ScanRoute() {
  const router = useRouter();
  return <VisualScannerScreen onClose={() => goBackOrTabs(router)} />;
}
