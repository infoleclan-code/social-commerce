import * as Crypto from 'expo-crypto';

/**
 * SHA-256 of upload bytes, so the API can prove that what it received is what the phone encoded
 * (visual search refuses a mismatch with IMAGE_HASH_MISMATCH). Hashing is an integrity check on top
 * of TLS, not an authorization: when it cannot be computed the upload goes without it.
 */

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
const LOOKUP = new Int16Array(128).fill(-1);
for (let index = 0; index < ALPHABET.length; index += 1) {
  LOOKUP[ALPHABET.charCodeAt(index)] = index;
}
// URL-safe variants decode to the same bytes.
LOOKUP['-'.charCodeAt(0)] = 62;
LOOKUP['_'.charCodeAt(0)] = 63;

/** Strict base64 decoding (padding optional, no whitespace). Null for anything that is not base64. */
export function base64ToBytes(base64: string): Uint8Array<ArrayBuffer> | null {
  const clean = base64.endsWith('==') ? base64.slice(0, -2) : base64.endsWith('=') ? base64.slice(0, -1) : base64;
  if (clean.length % 4 === 1) {
    return null;
  }
  const out = new Uint8Array(Math.floor((clean.length * 3) / 4));
  let buffer = 0;
  let bits = 0;
  let position = 0;
  for (let index = 0; index < clean.length; index += 1) {
    const code = clean.charCodeAt(index);
    const value = code < 128 ? LOOKUP[code] : -1;
    if (value < 0) {
      return null;
    }
    buffer = ((buffer << 6) | value) & 0xffffff;
    bits += 6;
    if (bits >= 8) {
      bits -= 8;
      out[position] = (buffer >> bits) & 0xff;
      position += 1;
    }
  }
  return out;
}

export function toHex(bytes: Uint8Array): string {
  let hex = '';
  for (let index = 0; index < bytes.length; index += 1) {
    hex += bytes[index].toString(16).padStart(2, '0');
  }
  return hex;
}

export async function sha256Hex(bytes: Uint8Array<ArrayBuffer>): Promise<string> {
  const digest = await Crypto.digest(Crypto.CryptoDigestAlgorithm.SHA256, bytes);
  return toHex(new Uint8Array(digest));
}

/** Hash of a local file (`file://` in the app's cache): the same bytes a multipart upload streams. */
export async function sha256OfFile(uri: string): Promise<string | null> {
  try {
    const response = await fetch(uri);
    const buffer = await response.arrayBuffer();
    return await sha256Hex(new Uint8Array(buffer));
  } catch {
    return null;
  }
}

/** Hash of the bytes inside a base64 payload or `data:` URI. */
export async function sha256OfBase64(base64OrDataUri: string): Promise<string | null> {
  const comma = base64OrDataUri.startsWith('data:') ? base64OrDataUri.indexOf(',') : -1;
  const bytes = base64ToBytes(comma >= 0 ? base64OrDataUri.slice(comma + 1) : base64OrDataUri);
  if (!bytes) {
    return null;
  }
  try {
    return await sha256Hex(bytes);
  } catch {
    return null;
  }
}
