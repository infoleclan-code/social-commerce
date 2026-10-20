/**
 * Share tokens are random alphanumerics minted by the API (16 characters today). Only a token in the
 * first path segment after `/s/` counts, and only from an https link or the app's own scheme: a token
 * inside a query string (`?next=/s/abc`) or from another app's scheme is ignored. Attribution is decided
 * on the server, which also refuses self-referrals.
 */
const SHARE_TOKEN = '([A-Za-z0-9]{10,64})';
const HTTPS_LINK = new RegExp(`^https://[A-Za-z0-9.-]+(?::\\d{1,5})?/s/${SHARE_TOKEN}(?:[/?#]|$)`, 'i');
const APP_LINK = new RegExp(`^socialcommerce://(?:/?--/)?/?s/${SHARE_TOKEN}(?:[/?#]|$)`, 'i');
// Expo Go and dev clients wrap the path: exp://<host>/--/s/<token>.
const DEV_LINK = new RegExp(`^exps?://[^/?#]+/--/s/${SHARE_TOKEN}(?:[/?#]|$)`, 'i');

/** Pulls the share token from `https://<domain>/s/<token>` or `socialcommerce://s/<token>`. */
export function parseShareToken(url: string): string | null {
  if (typeof url !== 'string' || url.length > 2_048) {
    return null;
  }
  const match = HTTPS_LINK.exec(url) ?? APP_LINK.exec(url) ?? (__DEV__ ? DEV_LINK.exec(url) : null);
  return match?.[1] ?? null;
}
