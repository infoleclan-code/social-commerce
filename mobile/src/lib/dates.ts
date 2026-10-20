export function formatCountdown(endsAtIso: string, nowMs: number): { days: number; clock: string } {
  const remainingMs = Math.max(0, new Date(endsAtIso).getTime() - nowMs);
  const totalSeconds = Math.floor(remainingMs / 1000);
  const days = Math.floor(totalSeconds / 86_400);
  const hours = Math.floor((totalSeconds % 86_400) / 3_600);
  const minutes = Math.floor((totalSeconds % 3_600) / 60);
  const seconds = totalSeconds % 60;
  const pad = (value: number) => String(value).padStart(2, '0');
  return { days, clock: `${pad(hours)}:${pad(minutes)}:${pad(seconds)}` };
}

export function formatExpectedDate(isoDate: string, locale = 'en'): string {
  const date = new Date(`${isoDate}T00:00:00Z`);
  return new Intl.DateTimeFormat(locale, {
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
  }).format(date);
}
