import { Text, View } from 'react-native';
import Svg, { Polyline } from 'react-native-svg';

import type { PricePoint } from '@/api/compare';
import { t, type MessageKey } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

const HINTS: Record<string, MessageKey> = {
  good_now: 'compare.hintGood',
  wait: 'compare.hintWait',
  typical: 'compare.hintTypical',
  learning: 'compare.hintLearning',
};

export function PriceChart({ points, hint }: { points: PricePoint[]; hint: string }) {
  const message = HINTS[hint] ?? HINTS.learning;
  if (points.length === 0) {
    return <Text style={{ color: palette.muted }}>{t(message)}</Text>;
  }
  const width = 280;
  const height = 72;
  const values = points.map((point) => point.low_minor);
  const low = Math.min(...values);
  const high = Math.max(...values);
  const span = Math.max(1, high - low);
  const line = points.map((point, index) => {
    const x = points.length === 1 ? width / 2 : (index / (points.length - 1)) * width;
    const y = height - 4 - ((point.low_minor - low) / span) * (height - 8);
    return `${x},${y}`;
  }).join(' ');

  return (
    <View style={{ gap: 6 }}>
      <Text style={{ color: hint === 'good_now' ? palette.goldSoft : palette.ivory, fontWeight: '700' }}>{t(message)}</Text>
      <Svg height={height} viewBox={`0 0 ${width} ${height}`} width="100%">
        <Polyline fill="none" points={line} stroke={palette.gold} strokeWidth={2} />
      </Svg>
    </View>
  );
}
