import { DarkTheme, type Theme } from 'expo-router';

import { palette } from '@/theme/tokens';

export const navigationTheme: Theme = {
  ...DarkTheme,
  colors: {
    ...DarkTheme.colors,
    primary: palette.gold,
    background: palette.ink,
    card: palette.raised,
    text: palette.ivory,
    border: palette.line,
    notification: palette.gold,
  },
};
