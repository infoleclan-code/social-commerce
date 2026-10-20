/**
 * Brand palette. tailwind.config.js loads this module, so a re-skin starts here.
 * Washes, lines, and scrims are the same hexes at named opacities.
 */

const brand = {
  ink: '#000000',
  ivory: '#FFFFFF',
  gold: '#D4AF37',
  goldSoft: '#E5C158',
} as const;

function alpha(hex: string, opacity: number): string {
  const value = Number.parseInt(hex.slice(1), 16);
  const red = (value >> 16) & 255;
  const green = (value >> 8) & 255;
  const blue = value & 255;
  return `rgba(${red}, ${green}, ${blue}, ${opacity})`;
}

export const palette = {
  ink: brand.ink,
  ivory: brand.ivory,
  gold: brand.gold,
  goldSoft: brand.goldSoft,
  raised: alpha(brand.ivory, 0.05),
  overlay: alpha(brand.ivory, 0.08),
  sheet: alpha(brand.ivory, 0.06),
  line: alpha(brand.ivory, 0.1),
  lineStrong: alpha(brand.ivory, 0.16),
  muted: alpha(brand.ivory, 0.62),
  faint: alpha(brand.ivory, 0.38),
  goldWash: alpha(brand.gold, 0.16),
  goldWashSoft: alpha(brand.gold, 0.1),
  goldGlow: alpha(brand.gold, 0.22),
  goldMist: alpha(brand.goldSoft, 0.08),
  goldRing: alpha(brand.goldSoft, 0.55),
  goldPulse: alpha(brand.goldSoft, 0.28),
  goldHalo: alpha(brand.goldSoft, 0.45),
  goldFill: alpha(brand.goldSoft, 0.85),
  scrim: alpha(brand.ink, 0.62),
  veil: alpha(brand.ink, 0.55),
  veilDeep: alpha(brand.ink, 0.92),
  veilSoft: alpha(brand.ink, 0.28),
  veilBottom: alpha(brand.ink, 0.9),
  clear: alpha(brand.ink, 0),
} as const;

export const radius = {
  pill: 999,
  sheet: 28,
  card: 28,
  panel: 24,
  media: 22,
  chip: 16,
  thumb: 24,
} as const;

export const blur = {
  glass: 32,
  bar: 40,
  card: 48,
} as const;

export const elevation = {
  inset: `inset 0 1px 0 ${palette.lineStrong}`,
  insetSoft: `inset 0 1px 0 ${alpha(brand.ivory, 0.14)}`,
  gold: `0 0 16px ${palette.goldHalo}`,
  goldBar: `0 0 12px ${palette.goldFill}`,
  goldOrb: `0 0 28px ${alpha(brand.gold, 0.28)}`,
} as const;

export const motion = {
  press: 0.96,
  iconPress: 0.88,
  spring: { damping: 18, stiffness: 340, mass: 0.32 },
} as const;

export const glass = {
  backgroundColor: palette.raised,
  borderWidth: 1,
  borderColor: palette.line,
} as const;

export const scrim = {
  media: [palette.clear, palette.veil, palette.veilDeep] as [string, string, string],
  reel: [palette.veilSoft, palette.clear, palette.veilBottom] as [string, string, string],
};
