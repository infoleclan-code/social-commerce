type TapHandlers = {
  single: () => void;
  double: (x: number, y: number) => void;
};

export type TapClassifier = {
  /** Feed every recognized tap here. A second tap inside the window is a double tap. */
  tap: (x: number, y: number, handlers: TapHandlers) => void;
  /** Drops a pending single tap (unmount). */
  dispose: () => void;
};

/**
 * Splits taps into single and double without composing two native recognizers, so the result is the
 * same on iOS, Android and web: a double tap fires at once; a single tap fires when the window passes
 * without a second one. Plain mutable state outside React, so render stays pure.
 */
export function createTapClassifier(windowMs: number): TapClassifier {
  let lastTapAt = 0;
  let pending: ReturnType<typeof setTimeout> | null = null;

  return {
    tap(x, y, handlers) {
      const now = Date.now();
      if (pending !== null && now - lastTapAt < windowMs) {
        clearTimeout(pending);
        pending = null;
        lastTapAt = 0;
        handlers.double(x, y);
        return;
      }
      lastTapAt = now;
      pending = setTimeout(() => {
        pending = null;
        handlers.single();
      }, windowMs);
    },
    dispose() {
      if (pending !== null) {
        clearTimeout(pending);
        pending = null;
      }
    },
  };
}
