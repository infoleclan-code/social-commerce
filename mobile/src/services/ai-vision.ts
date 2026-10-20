import { API_ROOT } from '@/api/client';
import { readAuthToken } from '@/lib/auth-token';
import type { DisplayMoney } from '@/lib/money';
import { isWebUrl, sameOrigin } from '@/lib/safe-url';
import { serviceEnv } from '@/services/keys';

/**
 * Vision client. Posts the frame URL to the configured endpoint.
 *
 * Credentials: the user's bearer token goes only to our own API origin. When the matcher is a separate
 * service it gets no credentials at all (there is no client-side model key: anything in the bundle is
 * public). Its answer is untrusted input: every URL must be https before the app shows or opens it, and
 * malformed matches are dropped one by one instead of poisoning the whole result.
 */

const VISION_TIMEOUT_MS = 12_000;
/** Larger answers are refused before parsing (a matcher returns a few kilobytes). */
const MAX_RESPONSE_BYTES = 1_000_000;
const MAX_DUPES = 24;
const MAX_VARIANTS = 20;
const MAX_TEXT = 300;

export type ItemAttributes = {
  category: string;
  color: string;
  fabric: string;
  fit: string;
  style: string;
};

export type DupeResult = {
  id: string;
  merchantId: string;
  merchantName: string;
  title: string;
  similarityScore: number;
  visualMatchConfidence: number;
  originalPrice: DisplayMoney | null;
  dupePrice: DisplayMoney | null;
  merchantUrl: string;
  image: string;
  variants: string[];
  attributes: ItemAttributes;
};

export type VisualFeatures = {
  category: string;
  color: string;
  material: string;
  silhouette: string;
  brandVibe: string;
};

/** Product bounds in normalized image coordinates (0..1 of the decoded image's width and height). */
export type VisionBox = {
  x: number;
  y: number;
  width: number;
  height: number;
};

export type VisionMatch = {
  attributes: ItemAttributes;
  features: VisualFeatures;
  confidenceScore: number;
  exact: DupeResult | null;
  dupes: DupeResult[];
  /** Optional: present when the matcher localizes the product in the frame. */
  boundingBox?: VisionBox | null;
};

export type VisionFrame = {
  imageUrl: string;
};

export type VisionFailure = 'timeout' | 'unavailable' | 'invalid';

export class VisionError extends Error {
  readonly reason: VisionFailure;

  constructor(reason: VisionFailure) {
    super(reason);
    this.name = 'VisionError';
    this.reason = reason;
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function readText(value: unknown): string {
  if (typeof value !== 'string' || value.trim().length === 0 || value.length > MAX_TEXT) {
    throw new VisionError('invalid');
  }
  return value.trim();
}

/** A link the app may open: https only (no other app's scheme, no javascript:, no file:). */
function readLink(value: unknown): string {
  const text = readText(value);
  if (!isWebUrl(text)) {
    throw new VisionError('invalid');
  }
  return text;
}

/** An image the app may load: https (http only against a development server). */
function readImage(value: unknown): string {
  const text = readText(value);
  if (!isWebUrl(text, __DEV__)) {
    throw new VisionError('invalid');
  }
  return text;
}

function readField(record: Record<string, unknown>, camel: string, snake: string): unknown {
  if (camel in record) {
    return record[camel];
  }
  if (snake in record) {
    return record[snake];
  }
  throw new VisionError('invalid');
}

function readScore(value: unknown): number {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    throw new VisionError('invalid');
  }
  const score = Math.round(value);
  if (score < 0 || score > 100) {
    throw new VisionError('invalid');
  }
  return score;
}

function readMoney(value: unknown): DisplayMoney | null {
  if (value === null) {
    return null;
  }
  if (!isRecord(value)) {
    throw new VisionError('invalid');
  }
  const amount = value.amount_minor;
  const currency = value.currency;
  const minor = value.minor_unit ?? 2;
  if (typeof amount !== 'number' || !Number.isInteger(amount) || amount < 0) {
    throw new VisionError('invalid');
  }
  if (typeof currency !== 'string' || currency.length !== 3) {
    throw new VisionError('invalid');
  }
  if (typeof minor !== 'number' || !Number.isInteger(minor) || minor < 0) {
    throw new VisionError('invalid');
  }
  return { amount_minor: amount, currency, minor_unit: minor };
}

function readAttributes(value: unknown): ItemAttributes {
  if (!isRecord(value)) {
    throw new VisionError('invalid');
  }
  return {
    category: readText(readField(value, 'category', 'category')),
    color: readText(readField(value, 'color', 'color')),
    fabric: readText(readField(value, 'fabric', 'fabric')),
    fit: readText(readField(value, 'fit', 'fit')),
    style: readText(readField(value, 'style', 'style')),
  };
}

function readToken(value: unknown, field: 'category' | 'feature'): string {
  if (typeof value !== 'string') {
    throw new VisionError('invalid');
  }
  const token = value.trim().toLowerCase().replace(/\s+/g, ' ');
  const pattern = field === 'category' ? /^[a-z0-9]+(?:\.[a-z0-9]+)*$/ : /^[a-z0-9]+(?:[- ][a-z0-9]+)*$/;
  if (!pattern.test(token)) {
    throw new VisionError('invalid');
  }
  return token;
}

function readFeatures(payload: Record<string, unknown>): VisualFeatures {
  return {
    category: readToken(payload.category, 'category'),
    color: readToken(payload.color, 'feature'),
    material: readToken(payload.material, 'feature'),
    silhouette: readToken(payload.silhouette, 'feature'),
    brandVibe: readToken(payload.brandVibe, 'feature'),
  };
}

function readModelConfidence(value: unknown): number {
  if (typeof value !== 'number' || !Number.isInteger(value) || value < 0 || value > 100) {
    throw new VisionError('invalid');
  }
  return value;
}

function readVariants(value: unknown): string[] {
  if (!Array.isArray(value) || value.length === 0) {
    throw new VisionError('invalid');
  }
  return value.slice(0, MAX_VARIANTS).map((item) => readText(item));
}

function readResult(value: unknown): DupeResult {
  if (!isRecord(value)) {
    throw new VisionError('invalid');
  }
  return {
    id: readText(readField(value, 'id', 'id')),
    merchantId: readText(readField(value, 'merchantId', 'merchant_id')),
    merchantName: readText(readField(value, 'merchantName', 'merchant_name')),
    title: readText(readField(value, 'title', 'title')),
    similarityScore: readScore(readField(value, 'similarityScore', 'similarity_score')),
    visualMatchConfidence: readScore(readField(value, 'visualMatchConfidence', 'visual_match_confidence')),
    originalPrice: readMoney(readField(value, 'originalPrice', 'original_price')),
    dupePrice: readMoney(readField(value, 'dupePrice', 'dupe_price')),
    merchantUrl: readLink(readField(value, 'merchantUrl', 'merchant_url')),
    image: readImage(readField(value, 'image', 'image')),
    variants: readVariants(readField(value, 'variants', 'variants')),
    attributes: readAttributes(readField(value, 'attributes', 'attributes')),
  };
}

/** One malformed or unsafe match is dropped; the rest of the answer stays usable. */
function optionalResult(value: unknown): DupeResult | null {
  try {
    return readResult(value);
  } catch (error) {
    if (error instanceof VisionError) {
      return null;
    }
    throw error;
  }
}

function readUnit(value: unknown): number {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0 || value > 1) {
    throw new VisionError('invalid');
  }
  return value;
}

/** Absent or null means the matcher did not localize the product. A malformed box rejects the payload. */
function readBox(value: unknown): VisionBox | null {
  if (value === undefined || value === null) {
    return null;
  }
  if (!isRecord(value)) {
    throw new VisionError('invalid');
  }
  const x = readUnit(value.x);
  const y = readUnit(value.y);
  const width = readUnit(value.width);
  const height = readUnit(value.height);
  if (width === 0 || height === 0) {
    throw new VisionError('invalid');
  }
  return { x, y, width: Math.min(width, 1 - x), height: Math.min(height, 1 - y) };
}

export function parseVisionMatch(payload: unknown): VisionMatch {
  if (!isRecord(payload)) {
    throw new VisionError('invalid');
  }
  const features = readFeatures(payload);
  const confidenceScore = readModelConfidence(payload.confidenceScore);
  const exact = payload.exact === null || payload.exact === undefined ? null : optionalResult(payload.exact);
  if (!Array.isArray(payload.dupes)) {
    throw new VisionError('invalid');
  }
  const dupes: DupeResult[] = [];
  for (const item of payload.dupes) {
    const result = optionalResult(item);
    if (result) {
      dupes.push(result);
    }
    if (dupes.length >= MAX_DUPES) {
      break;
    }
  }
  return {
    attributes: {
      category: features.category,
      color: features.color,
      fabric: features.material,
      fit: features.silhouette,
      style: features.brandVibe,
    },
    features,
    confidenceScore,
    exact,
    dupes,
    boundingBox: readBox(payload.boundingBox ?? payload.bounding_box),
  };
}

export async function analyzeFrame(frame: VisionFrame, signal?: AbortSignal): Promise<VisionMatch> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), VISION_TIMEOUT_MS);
  const onParentAbort = () => controller.abort();
  signal?.addEventListener('abort', onParentAbort);

  try {
    if (signal?.aborted) {
      throw signal.reason;
    }

    const endpoint = serviceEnv.aiVisionEndpoint;
    if (!isWebUrl(endpoint, __DEV__)) {
      throw new VisionError('unavailable');
    }
    const headers = new Headers({
      Accept: 'application/json',
      'Content-Type': 'application/json',
    });
    // The bearer token is ours: it never leaves for a third-party origin.
    if (API_ROOT !== null && sameOrigin(endpoint, API_ROOT)) {
      const token = await readAuthToken().catch(() => null);
      if (token) {
        headers.set('Authorization', `Bearer ${token}`);
      }
    }

    const response = await fetch(endpoint, {
      method: 'POST',
      headers,
      body: JSON.stringify({ imageUrl: frame.imageUrl }),
      signal: controller.signal,
      credentials: 'omit',
    });

    if (!response.ok) {
      throw new VisionError('unavailable');
    }
    const declared = Number(response.headers.get('Content-Length') ?? '0');
    if (Number.isFinite(declared) && declared > MAX_RESPONSE_BYTES) {
      throw new VisionError('invalid');
    }

    let text: string;
    try {
      text = await response.text();
    } catch {
      throw new VisionError('invalid');
    }
    if (text.length > MAX_RESPONSE_BYTES) {
      throw new VisionError('invalid');
    }
    let payload: unknown;
    try {
      payload = JSON.parse(text);
    } catch {
      throw new VisionError('invalid');
    }
    return parseVisionMatch(payload);
  } catch (error) {
    if (signal?.aborted) {
      throw error;
    }
    if (error instanceof VisionError) {
      throw error;
    }
    if (controller.signal.aborted) {
      throw new VisionError('timeout');
    }
    throw new VisionError('unavailable');
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', onParentAbort);
  }
}
