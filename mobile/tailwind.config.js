const jiti = require('jiti')(__dirname);
const { palette, radius } = jiti('./src/theme/tokens.ts');

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./app/**/*.{ts,tsx}', './src/**/*.{ts,tsx}'],
  presets: [require('nativewind/preset')],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        ink: palette.ink,
        raised: palette.raised,
        overlay: palette.overlay,
        line: palette.line,
        ivory: palette.ivory,
        muted: palette.muted,
        faint: palette.faint,
        gold: palette.gold,
        'gold-soft': palette.goldSoft,
      },
      borderRadius: {
        pill: `${radius.pill}px`,
        sheet: `${radius.sheet}px`,
        card: `${radius.card}px`,
        panel: `${radius.panel}px`,
        chip: `${radius.chip}px`,
      },
    },
  },
  plugins: [],
};
