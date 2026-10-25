import { readAuthToken } from '@/lib/auth-token';
import { parseHttpUrl } from '@/lib/safe-url';

/**
 * Where the API lives: `EXPO_PUBLIC_API_URL` (origin plus an optional base path).
 *
 * Release builds require https and have no fallback. A missing, malformed or http URL makes every call
 * fail with ApiConfigError instead of sending the bearer token in cleartext (or to a default host).
 * Development builds may use http (simulator, a laptop on the LAN) and default to 127.0.0.1:8000.
 */
export function resolveApiRoot(raw: string | undefined, development: boolean): string | null {
  const value = raw?.trim();
  if (!value) {
    return development ? 'http://127.0.0.1:8000' : null;
  }
  const parsed = parseHttpUrl(value);
  if (parsed === null || value.includes('?') || value.includes('#')) {
    return null;
  }
  if (parsed.protocol !== 'https:' && !development) {
    return null;
  }
  return `${parsed.origin}${parsed.pathname.replace(/\/+$/, '')}`;
}

export const API_ROOT = resolveApiRoot(process.env.EXPO_PUBLIC_API_URL, __DEV__);

/** Per-attempt time limits. Keyed (money) requests wait longer: the server may be talking to Stripe. */
const READ_TIMEOUT_MS = 15_000;
const WRITE_TIMEOUT_MS = 20_000;
const MONEY_TIMEOUT_MS = 35_000;
const UPLOAD_TIMEOUT_MS = 35_000;

/** Waits before retry 1, 2, 3 (plus up to 250 ms of jitter so a fleet of phones does not retry in step). */
const BACKOFF_MS = [600, 1_500, 3_000] as const;
const MAX_RETRY_AFTER_SECONDS = 10;
const RETRYABLE_STATUS: ReadonlySet<number> = new Set([408, 425, 429, 500, 502, 503, 504]);

type ErrorBody = {
  message?: string;
  code?: string;
  errors?: Record<string, string[]>;
  meta?: Record<string, unknown>;
};

/**
 * A non-2xx answer from the API. `code` is the stable machine code from the server
 * (`{ message, code, errors?, meta? }`), e.g. OUT_OF_STOCK or PRICE_CHANGED. Copy shown to people is
 * chosen from the code, never from `message`.
 */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string | undefined;
  readonly fieldErrors: Readonly<Record<string, string[]>>;
  readonly meta: Readonly<Record<string, unknown>>;
  /** From a 429's Retry-After header, when the server sent one. */
  readonly retryAfterSeconds: number | null;

  constructor(status: number, body: ErrorBody, retryAfterSeconds: number | null = null) {
    super(body.message ?? 'Request failed');
    this.name = 'ApiError';
    this.status = status;
    this.code = body.code;
    this.fieldErrors = body.errors ?? {};
    this.meta = body.meta ?? {};
    this.retryAfterSeconds = retryAfterSeconds;
  }
}

/**
 * No usable HTTP response (offline, DNS, TLS, connection reset, timeout, unreadable body). The request
 * may or may not have reached the server, so the outcome is unknown: only safe requests and requests
 * carrying an Idempotency-Key are retried, and a keyed retry must reuse the same key.
 */
export class NetworkError extends Error {
  readonly timedOut: boolean;

  constructor(cause: unknown, timedOut = false) {
    super(timedOut ? 'Request timed out' : 'Network request failed', { cause });
    this.name = 'NetworkError';
    this.timedOut = timedOut;
  }
}

/** A release build without an https EXPO_PUBLIC_API_URL. Nothing was sent. */
export class ApiConfigError extends Error {
  constructor() {
    super('EXPO_PUBLIC_API_URL must be an https URL in release builds.');
    this.name = 'ApiConfigError';
  }
}

export type ApiOptions = {
  /** Time limit per attempt. Defaults: 15 s reads, 20 s writes, 35 s keyed writes and uploads. */
  timeoutMs?: number;
  /** Overrides the signed-in token. Used for the separate admin session. */
  token?: string | null;
  /**
   * Retries after an unknown outcome or a transient refusal (408, 425, 429, 5xx, REQUEST_IN_PROGRESS).
   * Default 3 for GET/HEAD and for requests with an Idempotency-Key; 0 for other writes, which are
   * never repeated automatically (a lost "like" is better than a double comment).
   */
  retries?: number;
};

function abortError(): Error {
  const error = new Error('Aborted');
  error.name = 'AbortError';
  return error;
}

function isAbort(error: unknown): boolean {
  return error instanceof Error && error.name === 'AbortError';
}

function stringRecord(value: unknown): Record<string, string[]> | undefined {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return undefined;
  }
  const out: Record<string, string[]> = {};
  for (const [key, messages] of Object.entries(value as Record<string, unknown>)) {
    if (Array.isArray(messages)) {
      out[key] = messages.filter((message): message is string => typeof message === 'string');
    }
  }
  return out;
}

async function readError(response: Response): Promise<ErrorBody> {
  try {
    const body: unknown = await response.json();
    if (!body || typeof body !== 'object') {
      return {};
    }
    const record = body as Record<string, unknown>;
    const meta = record.meta;
    return {
      message: typeof record.message === 'string' ? record.message : undefined,
      code: typeof record.code === 'string' ? record.code : undefined,
      errors: stringRecord(record.errors),
      meta: meta && typeof meta === 'object' && !Array.isArray(meta) ? (meta as Record<string, unknown>) : undefined,
    };
  } catch {
    return {};
  }
}

function retryAfter(response: Response): number | null {
  const header = response.headers.get('Retry-After');
  if (!header || !/^\d{1,6}$/.test(header.trim())) {
    return null;
  }
  return Number.parseInt(header, 10);
}

function sleep(ms: number, signal: AbortSignal | undefined): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(abortError());
      return;
    }
    const onAbort = () => {
      clearTimeout(timer);
      reject(abortError());
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort);
      resolve();
    }, ms);
    signal?.addEventListener('abort', onAbort);
  });
}

/** Wait before the next attempt, or null when this failure must not be retried. */
function retryDelay(failure: unknown, attempt: number, keyed: boolean): number | null {
  const backoff = BACKOFF_MS[Math.min(attempt, BACKOFF_MS.length - 1)] + Math.floor(Math.random() * 250);
  if (failure instanceof NetworkError) {
    return backoff;
  }
  if (!(failure instanceof ApiError)) {
    return null;
  }
  if (failure.status === 409) {
    // The twin of this keyed request is still running on the server: ask again, it will replay.
    return keyed && failure.code === 'REQUEST_IN_PROGRESS' ? backoff : null;
  }
  if (!RETRYABLE_STATUS.has(failure.status)) {
    return null;
  }
  if (failure.retryAfterSeconds !== null) {
    return failure.retryAfterSeconds > MAX_RETRY_AFTER_SECONDS ? null : Math.max(backoff, failure.retryAfterSeconds * 1_000);
  }
  return backoff;
}

/** One HTTP exchange, body included, inside one time limit. */
async function attemptOnce<T>(url: string, init: RequestInit, timeoutMs: number, callerSignal: AbortSignal | undefined): Promise<T> {
  const controller = new AbortController();
  let timedOut = false;
  const timer = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, timeoutMs);
  const onCallerAbort = () => controller.abort();
  callerSignal?.addEventListener('abort', onCallerAbort);

  try {
    let response: Response;
    try {
      response = await fetch(url, { ...init, signal: controller.signal });
    } catch (error) {
      if (callerSignal?.aborted) {
        throw abortError();
      }
      throw new NetworkError(error, timedOut || isAbort(error));
    }

    if (!response.ok) {
      throw new ApiError(response.status, await readError(response), retryAfter(response));
    }
    if (response.status === 204) {
      return undefined as T;
    }
    try {
      return (await response.json()) as T;
    } catch (error) {
      if (callerSignal?.aborted) {
        throw abortError();
      }
      // A 2xx whose body never fully arrived: the server acted, the client cannot tell how.
      throw new NetworkError(error, timedOut);
    }
  } finally {
    clearTimeout(timer);
    callerSignal?.removeEventListener('abort', onCallerAbort);
  }
}

/**
 * Calls `/api/v1{path}` with the signed-in user's bearer token. JSON in, JSON out; a FormData body is sent
 * as multipart (fetch sets the boundary). 204 resolves to undefined.
 *
 * Every attempt has a time limit. Safe requests and keyed requests (Idempotency-Key header) are retried
 * with backoff after an unknown outcome; the same headers, and so the same key, go out on every attempt.
 * A caller's AbortSignal cancels the request and any pending retry with an AbortError.
 */
export async function api<T>(path: string, init: RequestInit = {}, options: ApiOptions = {}): Promise<T> {
  if (API_ROOT === null) {
    throw new ApiConfigError();
  }
  if (!path.startsWith('/') || /(^|\/)\.{1,2}(\/|\?|#|$)/.test(path)) {
    // Route ids are URI-encoded, which leaves '.' and '..' intact: never let one climb the path.
    throw new ApiError(400, { code: 'INVALID_PATH', message: 'Invalid request path.' });
  }

  const method = (init.method ?? 'GET').toUpperCase();
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  const token = options.token !== undefined ? options.token : await readAuthToken();
  if (token) {
    headers.set('Authorization', `Bearer ${token}`);
  }

  const keyed = headers.has('Idempotency-Key');
  const safe = method === 'GET' || method === 'HEAD';
  const upload = typeof FormData !== 'undefined' && init.body instanceof FormData;
  const retries = Math.max(0, options.retries ?? (safe || keyed ? BACKOFF_MS.length : 0));
  const timeoutMs = options.timeoutMs ?? (upload ? UPLOAD_TIMEOUT_MS : keyed ? MONEY_TIMEOUT_MS : safe ? READ_TIMEOUT_MS : WRITE_TIMEOUT_MS);
  const callerSignal = init.signal ?? undefined;
  const url = `${API_ROOT}/api/v1${path}`;

  for (let attempt = 0; ; attempt += 1) {
    if (callerSignal?.aborted) {
      throw abortError();
    }
    try {
      return await attemptOnce<T>(url, { ...init, method, headers }, timeoutMs, callerSignal);
    } catch (failure) {
      if (isAbort(failure)) {
        throw failure;
      }
      const delay = attempt < retries ? retryDelay(failure, attempt, keyed) : null;
      if (delay === null) {
        throw failure;
      }
      await sleep(delay, callerSignal);
    }
  }
}

/** RequestInit for a JSON body. */
export function json(method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown, headers?: Record<string, string>): RequestInit {
  return {
    method,
    headers: { 'Content-Type': 'application/json', ...headers },
    body: body === undefined ? undefined : JSON.stringify(body),
  };
}

/** RequestInit for a keyed (money or stock) JSON request. */
export function keyed(idempotencyKey: string, body?: unknown): RequestInit {
  return json('POST', body, { 'Idempotency-Key': idempotencyKey });
}

/**
 * Builds a query string from defined values only: `?mode=for_you&cursor=...`. Hand-rolled because React
 * Native's URLSearchParams has historically been incomplete.
 */
export function query(params: Record<string, string | number | null | undefined>): string {
  const parts: string[] = [];
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '') {
      parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
    }
  }
  return parts.length > 0 ? `?${parts.join('&')}` : '';
}

/** Records an open of a tracked share. The server counts it on GET /s/{token}. */
export function recordShareOpen(token: string): Promise<void> {
  return api<void>(`/s/${encodeURIComponent(token)}`).then(() => undefined);
}
