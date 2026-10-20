/**
 * Incoming deep links that are not screens.
 *
 * - `.../stripe-redirect`: a bank page returning after 3-D Secure. Stripe's SDK consumes it (the root
 *   layout passes it to the bridge); the router must not try to open a "stripe-redirect" screen.
 * - `.../s/<token>`: a share link. The root layout records the token for attribution; there is no share
 *   screen, so a cold start lands on Home and a warm start stays where the person is.
 *
 * Returning null keeps the app on its current screen.
 */
export function redirectSystemPath({ path, initial }: { path: string; initial: boolean }): string | null {
  try {
    if (/(^|\/|:)stripe-redirect(\/|\?|#|$)/.test(path) || /(^|\/)s\/[A-Za-z0-9_-]+(\/|\?|#|$)/.test(path)) {
      return initial ? '/' : null;
    }
    return path;
  } catch {
    return initial ? '/' : null;
  }
}
