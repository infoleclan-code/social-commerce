import { router as rootRouter, type ImperativeRouter } from 'expo-router';

/**
 * Pop the stack when it has a screen to return to.
 * `canGoBack()` can be true on web even when no navigator will handle GO_BACK,
 * so this uses `canDismiss()`, which is true only when a stack has history.
 */
export function goBackOrTabs(router: ImperativeRouter = rootRouter): void {
  if (router.canDismiss()) {
    router.back();
    return;
  }
  router.push('/(tabs)');
}
