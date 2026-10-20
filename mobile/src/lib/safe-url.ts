import * as WebBrowser from 'expo-web-browser';

import { palette } from '@/theme/tokens';

/**
 * Strict URL checks for every URL the app did not build itself: API origins from the environment,
 * merchant links and images from the vision service, redirect links from the API.
 *
 * React Native's `URL` is a loose regex shim (it neither validates nor normalizes), so security
 * decisions never rely on it. This parser accepts only `http(s)://host[:port][/path][?query][#hash]`
 * with a DNS name or an IP literal: no user-info (`https://good.com@evil.com`), no backslashes, no
 * whitespace or control characters, no other schemes (`javascript:`, `intent:`, `file:`, `data:`).
 */

export type ParsedUrl = {
  protocol: 'http:' | 'https:';
  /** Lower-cased host name or IP literal, without the port. */
  hostname: string;
  /** Explicit port, or the scheme's default (443 / 80). */
  port: number;
  /** `https://host[:port]`, lower-cased, without a default port. */
  origin: string;
  /** Path including the leading slash ('/' when absent). */
  pathname: string;
};

const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
const STRICT_URL = new RegExp(
  `^(https?)://(${LABEL}(?:\\.${LABEL})*\\.?|\\[[0-9A-Fa-f:.]{2,45}\\])(?::(\\d{1,5}))?(/[^\\s?#\\\\]*)?(\\?[^\\s#\\\\]*)?(#[^\\s\\\\]*)?$`,
  'i',
);
const MAX_URL_LENGTH = 2_048;

export function parseHttpUrl(value: unknown): ParsedUrl | null {
  if (typeof value !== 'string' || value.length === 0 || value.length > MAX_URL_LENGTH) {
    return null;
  }
  // Control characters (including CR/LF) are never valid in a URL we accept.
  for (let index = 0; index < value.length; index += 1) {
    const code = value.charCodeAt(index);
    if (code < 0x21 || code === 0x7f) {
      return null;
    }
  }
  const match = STRICT_URL.exec(value);
  if (!match) {
    return null;
  }
  const protocol = match[1].toLowerCase() === 'https' ? 'https:' : 'http:';
  const hostname = match[2].toLowerCase().replace(/\.$/, '');
  const defaultPort = protocol === 'https:' ? 443 : 80;
  const port = match[3] === undefined ? defaultPort : Number(match[3]);
  if (!Number.isInteger(port) || port < 1 || port > 65_535) {
    return null;
  }
  const origin = `${protocol}//${hostname}${port === defaultPort ? '' : `:${port}`}`;
  return { protocol, hostname, port, origin, pathname: match[4] || '/' };
}

/** True for an https URL (or, only when `allowHttp`, an http one) that passes the strict parser. */
export function isWebUrl(value: unknown, allowHttp = false): value is string {
  const parsed = parseHttpUrl(value);
  return parsed !== null && (parsed.protocol === 'https:' || allowHttp);
}

/** Same scheme, host and port. Used before attaching credentials to a request. */
export function sameOrigin(a: string, b: string): boolean {
  const left = parseHttpUrl(a);
  const right = parseHttpUrl(b);
  return left !== null && right !== null && left.origin === right.origin;
}

/**
 * Opens a merchant or tracking link in the in-app browser. Only https URLs are opened; anything else
 * (another app's scheme, `javascript:`, a file) is refused and resolves to false. The link is never
 * handed to Linking.openURL, which would launch whatever app claims the scheme.
 */
export async function openExternalUrl(url: string): Promise<boolean> {
  if (!isWebUrl(url)) {
    return false;
  }
  try {
    await WebBrowser.openBrowserAsync(url, {
      controlsColor: palette.gold,
      toolbarColor: palette.ink,
      presentationStyle: WebBrowser.WebBrowserPresentationStyle.PAGE_SHEET,
      dismissButtonStyle: 'close',
    });
    return true;
  } catch {
    return false;
  }
}
