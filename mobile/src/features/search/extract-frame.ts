import { FRAME_BY_SOURCE } from '@/features/search/catalog';
import type { ExtractedFrame, SocialSource } from '@/features/search/types';

function hostOf(raw: string): string | null {
  const trimmed = raw.trim();
  if (trimmed.length < 8) {
    return null;
  }
  try {
    return new URL(trimmed.startsWith('http') ? trimmed : `https://${trimmed}`).hostname.toLowerCase();
  } catch {
    return null;
  }
}

export function sourceForHost(host: string): SocialSource | null {
  if (host.includes('tiktok')) {
    return 'tiktok';
  }
  if (host.includes('instagram')) {
    return 'instagram';
  }
  if (host.includes('youtube') || host === 'youtu.be' || host.endsWith('.youtu.be')) {
    return 'youtube';
  }
  return null;
}

/** Simulated frame grab. A recognized social link becomes a product still for matching. */
export function extractProductFrame(raw: string): ExtractedFrame | null {
  const host = hostOf(raw);
  if (!host) {
    return null;
  }
  const source = sourceForHost(host);
  if (!source) {
    return null;
  }
  const frame = FRAME_BY_SOURCE[source];
  return { source, imageUrl: frame.image, kind: frame.kind };
}
