import { parseHttpUrl } from '@/lib/safe-url';

/**
 * Public client configuration.
 *
 * Every EXPO_PUBLIC_ value is compiled into the JavaScript bundle and can be read by anyone who has the
 * app, so none of them is a secret and none of them may hold one. There is deliberately no client-side
 * AI/model API key: the vision service authenticates our backend, and the app reaches it either through
 * the API (same origin, bearer token) or anonymously.
 */

export type ServiceEnv = {
  /** https URL of the vision matcher ('' when not configured: the local catalog preview is used). */
  aiVisionEndpoint: string;
  vectorDbEndpoint: string | null;
};

function filled(value: string | undefined): string | null {
  if (typeof value !== 'string') {
    return null;
  }
  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : null;
}

function warn(message: string): void {
  if (__DEV__) {
    console.warn(`[env] ${message}`);
  }
}

/** An endpoint URL from the environment: https in release builds, http allowed only in development. */
function endpoint(value: string | undefined, name: string): string | null {
  const configured = filled(value);
  if (!configured) {
    return null;
  }
  const parsed = parseHttpUrl(configured);
  if (parsed === null || (parsed.protocol !== 'https:' && !__DEV__)) {
    warn(`${name} must be an https URL; it is ignored.`);
    return null;
  }
  return configured;
}

// EXPO_PUBLIC_AI_API_KEY is intentionally never referenced: Expo inlines every process.env.EXPO_PUBLIC_*
// expression it finds into the bundle, so even a "warn if set" check would ship the key. If one was ever
// set for a release, rotate it.

const vision = endpoint(process.env.EXPO_PUBLIC_AI_VISION_ENDPOINT, 'EXPO_PUBLIC_AI_VISION_ENDPOINT');
if (!vision) {
  warn('EXPO_PUBLIC_AI_VISION_ENDPOINT is not set. Using the local catalog preview.');
}

export const visionEndpointConfigured = vision !== null;

export const serviceEnv: ServiceEnv = {
  aiVisionEndpoint: vision ?? '',
  vectorDbEndpoint: endpoint(process.env.EXPO_PUBLIC_VECTOR_DB_ENDPOINT, 'EXPO_PUBLIC_VECTOR_DB_ENDPOINT'),
};
