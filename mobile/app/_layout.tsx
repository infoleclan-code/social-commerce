import { GestureHandlerRootView } from 'react-native-gesture-handler';
import '../global.css';

import { QueryClientProvider } from '@tanstack/react-query';
import { Stack, ThemeProvider, usePathname } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import * as SystemUI from 'expo-system-ui';
import * as Linking from 'expo-linking';
import { colorScheme } from 'nativewind';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { BackHandler, Platform } from 'react-native';

import { createQueryClient } from '@/api/query-client';
import { AuthScreen } from '@/features/auth/auth-screen';
import { registerPushDevice } from '@/features/auth/register-push';
import { useSession } from '@/stores/session';
import { DupeSearchOverlay } from '@/features/feed/dupe-search-overlay';
import { ProductSheet } from '@/features/feed/product-sheet';
import { CheckoutSheet } from '@/features/checkout/checkout-sheet';
import { OrderTracker } from '@/features/closet/order-tracker';
import { VisualSearch } from '@/features/search/visual-search';
import { DupeFinderSheet } from '@/features/dupes/dupe-finder-sheet';
import { OneTapBanner } from '@/features/one-tap/one-tap-banner';
import { purchasesEnabled } from '@/lib/flags';
import { stripeBridge } from '@/features/payments/stripe-bridge';
import { CommentsSheet } from '@/features/reels/comments-sheet';
import { parseShareToken } from '@/lib/deep-links';
import { useCheckoutStore } from '@/stores/checkout';
import { useClosetStore } from '@/stores/closet';
import { useCommentsSheetStore } from '@/stores/comments-sheet';
import { useVisualSearchStore } from '@/features/search/store';
import { useDupeScanStore } from '@/stores/dupe-scan';
import { useDupeSearchStore } from '@/stores/dupe-search';
import { useProductSheetStore } from '@/stores/product-sheet';
import { useShareLinkStore } from '@/stores/share-link';
import { navigationTheme } from '@/theme/navigation';
import { palette } from '@/theme/tokens';

SplashScreen.preventAutoHideAsync().catch(() => undefined);

function GlobalSearch() {
  const path = usePathname();
  const open = useVisualSearchStore((state) => state.open);
  if (path === '/' || path === '/index') {
    return null;
  }
  return open ? <VisualSearch /> : null;
}

function SessionGate({ children }: { children: ReactNode }) {
  const ready = useSession((state) => state.ready);
  const signedIn = useSession((state) => state.signedIn);
  useEffect(() => {
    void useSession.getState().refresh().then(() => {
      if (useSession.getState().signedIn) {
        void registerPushDevice();
      }
    });
  }, []);
  if (!ready) {
    return null;
  }
  if (!signedIn) {
    return <AuthScreen />;
  }
  return children;
}

function lockDarkMode(): void {
  if (Platform.OS === 'web') {
    if (typeof document === 'undefined') {
      return;
    }
    document.documentElement.classList.add('dark');
    document.documentElement.style.backgroundColor = palette.ink;
  }
  colorScheme.set('dark');
}

function SheetNavigationReset() {
  const pathname = usePathname();
  const previous = useRef(pathname);

  useEffect(() => {
    if (previous.current === pathname) {
      return;
    }
    previous.current = pathname;
    useDupeScanStore.getState().close();
    useProductSheetStore.getState().close();
    useCheckoutStore.getState().close();
    useDupeSearchStore.getState().closeOverlay();
    useClosetStore.getState().closeTracking();
    useVisualSearchStore.getState().dismiss();
    useCommentsSheetStore.getState().close();
  }, [pathname]);

  useEffect(() => {
    if (Platform.OS === 'web') {
      return;
    }
    const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
      const scan = useDupeScanStore.getState();
      if (scan.phase !== 'idle') {
        scan.close();
        return true;
      }
      if (useCheckoutStore.getState().draft) {
        useCheckoutStore.getState().close();
        return true;
      }
      if (useClosetStore.getState().trackingId) {
        useClosetStore.getState().closeTracking();
        return true;
      }
      if (useVisualSearchStore.getState().open) {
        useVisualSearchStore.getState().dismiss();
        return true;
      }
      if (useProductSheetStore.getState().target) {
        useProductSheetStore.getState().close();
        return true;
      }
      if (useDupeSearchStore.getState().overlayOpen) {
        useDupeSearchStore.getState().closeOverlay();
        return true;
      }
      if (useCommentsSheetStore.getState().postId) {
        useCommentsSheetStore.getState().close();
        return true;
      }
      return false;
    });
    return () => {
      subscription.remove();
    };
  }, []);

  return null;
}

export default function RootLayout() {
  const [queryClient] = useState(createQueryClient);

  useEffect(() => {
    try {
      lockDarkMode();
    } catch {
      // The palette is already dark if the appearance lock is unavailable.
    }
    void SystemUI.setBackgroundColorAsync(palette.ink);
    SplashScreen.hideAsync().catch(() => undefined);
  }, []);

  useEffect(() => {
    function capture(url: string | null) {
      if (!url) {
        return;
      }
      // A bank page returning from 3-D Secure: Stripe's SDK finishes the authentication.
      void stripeBridge.handleRedirect(url);
      const token = parseShareToken(url);
      if (token) {
        useShareLinkStore.getState().setToken(token);
      }
    }

    void Linking.getInitialURL().then(capture);
    const subscription = Linking.addEventListener('url', ({ url }) => {
      capture(url);
    });
    return () => {
      subscription.remove();
    };
  }, []);

  return (
    <GestureHandlerRootView style={{ flex: 1, backgroundColor: palette.ink }}>
      <QueryClientProvider client={queryClient}>
        <ThemeProvider value={navigationTheme}>
          <StatusBar style="light" />
          <SessionGate>
          <Stack
            screenOptions={{
              headerShown: false,
              contentStyle: { backgroundColor: palette.ink },
              animation: 'slide_from_right',
            }}
          >
            <Stack.Screen name="(tabs)" />
            <Stack.Screen name="user/[id]" options={{ animation: 'slide_from_right' }} />
            <Stack.Screen name="cart" />
            <Stack.Screen name="cards" />
            <Stack.Screen name="admin" />
            <Stack.Screen name="legal" />
            <Stack.Screen name="scan" options={{ animation: 'slide_from_bottom', gestureEnabled: true }} />
            <Stack.Screen name="play" />
            <Stack.Screen name="grow" />
            <Stack.Screen name="gift/[token]" />
            <Stack.Screen name="taste" />
            <Stack.Screen name="sell" />
            <Stack.Screen name="product/[id]" />
            <Stack.Screen name="orders" />
          </Stack>
          </SessionGate>
          <SheetNavigationReset />
          <ProductSheet />
          <DupeFinderSheet />
          {purchasesEnabled ? <CheckoutSheet /> : null}
          {purchasesEnabled ? <OrderTracker /> : null}
          <DupeSearchOverlay />
          <GlobalSearch />
          <CommentsSheet />
          {purchasesEnabled ? <OneTapBanner /> : null}
        </ThemeProvider>
      </QueryClientProvider>
    </GestureHandlerRootView>
  );
}
