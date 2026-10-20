import { useEffect, useState } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

function isActive(state: AppStateStatus | null | undefined): boolean {
  // `currentState` can be null on a cold start before the first event; treat that as foreground.
  return state === 'active' || state === null || state === undefined;
}

/** False while the app is in the background or inactive (control center, incoming call, app switcher). */
export function useAppActive(): boolean {
  const [active, setActive] = useState(() => isActive(AppState.currentState));

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      setActive(isActive(state));
    });
    return () => {
      subscription.remove();
    };
  }, []);

  return active;
}
