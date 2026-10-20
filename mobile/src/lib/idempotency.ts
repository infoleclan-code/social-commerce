import * as Crypto from 'expo-crypto';

/**
 * Idempotency-Key for a request that moves money or reserves stock (the API refuses those requests
 * without one: 8-80 characters of A-Z, a-z, 0-9, '-' and '_').
 *
 * The rule on the client: one key per intent (one tap, one swipe, one "Add card"). Every retry whose
 * outcome is unknown (timeout, connection lost, 5xx, REQUEST_IN_PROGRESS) reuses that key, so the server
 * answers with the result it already produced instead of charging again. A new key only after a definite
 * answer (declined, cancelled, sold out), so the next attempt is a new purchase rather than a replay.
 *
 * Keys come from a CSPRNG (UUID v4), never from Date.now() or Math.random(): a guessable key is only
 * scoped to the signed-in user on the server, but a colliding key would replay someone else's attempt
 * on the same account (two devices).
 */
export function newIdempotencyKey(prefix?: string): string {
  const id = Crypto.randomUUID();
  return prefix ? `${prefix.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 20)}-${id}` : id;
}

/** Remembers one key per intent (e.g. per post for "Buy") until the attempt reaches a definite end. */
export class IntentKeys {
  private readonly keys = new Map<string, string>();

  constructor(private readonly prefix: string) {}

  /** The key of the open attempt for `intent`, creating one if there is none. */
  for(intent: string): string {
    let key = this.keys.get(intent);
    if (!key) {
      key = newIdempotencyKey(this.prefix);
      this.keys.set(intent, key);
    }
    return key;
  }

  /** The attempt ended with a definite answer: the next one is a new request with a new key. */
  settle(intent: string): void {
    this.keys.delete(intent);
  }
}
