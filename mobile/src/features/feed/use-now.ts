import { useEffect, useState } from 'react';

/** Pass active=false on a hidden screen so its feed does not re-render every second. */
export function useNow(active = true): number {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (!active) {
      return;
    }
    const kick = setTimeout(() => setNow(Date.now()), 0);
    const id = setInterval(() => {
      setNow(Date.now());
    }, 1000);
    return () => {
      clearTimeout(kick);
      clearInterval(id);
    };
  }, [active]);

  return now;
}
