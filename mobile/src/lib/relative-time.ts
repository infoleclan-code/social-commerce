import { t } from '@/lib/i18n';

const compact = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });

/** 1234 -> "1.2K". Counters only: never for money. */
export function formatCompact(value: number): string {
  return compact.format(Math.max(0, value));
}

/** "now", "5m", "3h", "2d", "4w" since an ISO timestamp. Empty for a missing or unparsable value. */
export function timeAgo(iso: string | null, now: number = Date.now()): string {
  if (!iso) {
    return '';
  }
  const then = Date.parse(iso);
  if (!Number.isFinite(then)) {
    return '';
  }
  const seconds = Math.max(0, Math.floor((now - then) / 1000));
  if (seconds < 60) {
    return t('time.now');
  }
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) {
    return t('time.minutes', { count: String(minutes) });
  }
  const hours = Math.floor(minutes / 60);
  if (hours < 24) {
    return t('time.hours', { count: String(hours) });
  }
  const days = Math.floor(hours / 24);
  if (days < 7) {
    return t('time.days', { count: String(days) });
  }
  return t('time.weeks', { count: String(Math.floor(days / 7)) });
}

/** Remaining time as m:ss for short countdowns (the 1-Tap undo window). */
export function formatMinutesSeconds(remainingMs: number): string {
  const total = Math.max(0, Math.ceil(remainingMs / 1000));
  const minutes = Math.floor(total / 60);
  const seconds = total % 60;
  return `${minutes}:${String(seconds).padStart(2, '0')}`;
}
