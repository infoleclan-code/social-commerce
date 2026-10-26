import { RotateCcw, SearchX } from 'lucide-react-native';
import { FlatList, Text, View, useWindowDimensions } from 'react-native';
import Animated, { FadeInDown, SlideInDown, SlideOutDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { VisualMatch, VisualSearchResult } from '@/api/contracts';
import { PressableScale } from '@/components/ui/pressable-scale';
import { OneTapBuyCard } from '@/features/one-tap/one-tap-buy-card';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { palette, radius } from '@/theme/tokens';

type Props = {
  result: VisualSearchResult;
  onScanAgain: () => void;
};

/** Up to three labels the analyzer saw ("black, white"), strongest first. */
function seenLabels(result: VisualSearchResult): string | null {
  const labels = [...result.labels]
    .sort((left, right) => right.score - left.score)
    .slice(0, 3)
    .map((label) => label.label);
  return labels.length > 0 ? labels.join(', ') : null;
}

/**
 * Ranked matches for a scan, each with its own 1-Tap Buy (or "Shop at" for affiliate merchants).
 * An in-tree sheet, not a native modal, so Stripe's 3-D Secure and card sheets can present over it.
 */
export function ScanResultsSheet({ result, onScanAgain }: Props) {
  const insets = useSafeAreaInsets();
  const { height } = useWindowDimensions();
  const seen = seenLabels(result);
  const matches = result.results.filter((item) => matchPercent(item) >= 1);
  const count = matches.length;

  return (
    <Animated.View
      entering={SlideInDown.springify().damping(22).stiffness(200)}
      exiting={SlideOutDown.duration(200)}
      style={{
        position: 'absolute',
        left: 0,
        right: 0,
        bottom: 0,
        height: count > 0 ? Math.round(height * 0.6) : undefined,
        borderTopLeftRadius: radius.sheet,
        borderTopRightRadius: radius.sheet,
        backgroundColor: '#0D0D0D',
        borderWidth: 1,
        borderColor: palette.line,
        paddingBottom: insets.bottom + 8,
        zIndex: 20,
      }}
    >
      <View style={{ alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: palette.lineStrong, marginTop: 8 }} />
      <View style={{ flexDirection: 'row', alignItems: 'center', paddingHorizontal: 16, paddingTop: 12, paddingBottom: 8, gap: 12 }}>
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text accessibilityRole="header" style={{ color: palette.ivory, fontSize: 18, fontWeight: '700', letterSpacing: -0.2 }}>
            {count > 0 ? (count === 1 ? t('scanner.resultsOne') : t('scanner.resultsCount', { count: String(count) })) : t('scanner.noMatchTitle')}
          </Text>
          {seen ? (
            <Text numberOfLines={1} style={{ color: palette.faint, fontSize: 12, marginTop: 2 }}>
              {t('scanner.detected', { labels: seen })}
            </Text>
          ) : null}
          {count > 0 && result.fallback === 'semantic' ? (
            <Text style={{ color: palette.goldSoft, fontSize: 12, marginTop: 2 }}>{t('scanner.semantic')}</Text>
          ) : null}
        </View>
        <PressableScale accessibilityLabel={t('scanner.scanAgain')} onPress={onScanAgain}>
          <View
            style={{
              minHeight: 40,
              paddingHorizontal: 14,
              borderRadius: radius.pill,
              flexDirection: 'row',
              alignItems: 'center',
              gap: 6,
              borderWidth: 1,
              borderColor: palette.lineStrong,
              backgroundColor: palette.overlay,
            }}
          >
            <RotateCcw color={palette.ivory} size={15} strokeWidth={2} />
            <Text style={{ color: palette.ivory, fontSize: 13, fontWeight: '700' }}>{t('scanner.scanAgain')}</Text>
          </View>
        </PressableScale>
      </View>

      {count === 0 ? (
        <View style={{ alignItems: 'center', paddingHorizontal: 32, paddingVertical: 28, gap: 10 }}>
          <SearchX color={palette.goldSoft} size={30} strokeWidth={1.5} />
          <Text style={{ color: palette.muted, fontSize: 14, lineHeight: 20, textAlign: 'center' }}>
            {result.detected?.category
              ? t('scanner.noMatchSeen', { detail: [result.detected.category, ...(result.detected.colors ?? []), result.detected.brand].filter(Boolean).join(', ') })
              : t('scanner.noMatchBody')}
          </Text>
        </View>
      ) : (
        <FlatList
          contentContainerStyle={{ paddingHorizontal: 12, paddingBottom: 12, gap: 12 }}
          data={matches}
          keyExtractor={(item) => item.product.id}
          renderItem={({ item, index }) => <MatchRow index={index} match={item} />}
          showsVerticalScrollIndicator={false}
          style={{ flex: 1 }}
        />
      )}
    </Animated.View>
  );
}

function MatchRow({ match, index }: { match: VisualMatch; index: number }) {
  const percent = matchPercent(match);
  return (
    <Animated.View entering={FadeInDown.delay(Math.min(index, 6) * 60).duration(260)} style={{ gap: 6 }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 4 }}>
        <View
          style={{
            paddingHorizontal: 10,
            paddingVertical: 3,
            borderRadius: radius.pill,
            backgroundColor: index === 0 ? palette.goldWash : palette.overlay,
            borderWidth: 1,
            borderColor: index === 0 ? palette.goldRing : palette.line,
          }}
        >
          <Text style={{ color: index === 0 ? palette.goldSoft : palette.muted, fontSize: 12, fontWeight: '700', fontVariant: ['tabular-nums'] }}>
            {t('scanner.similarity', { score: String(percent) })}
          </Text>
        </View>
        {match.landed ? (
          <Text style={{ color: palette.ivory, fontSize: 12, fontWeight: '700' }}>{formatMoney(match.landed.total)}</Text>
        ) : null}
      </View>
      <OneTapBuyCard compact expectedTotalMinor={match.landed?.total.amount_minor ?? null} product={match.product} />
    </Animated.View>
  );
}

function matchPercent(match: VisualMatch): number {
  const similarity = Math.max(match.visual_similarity ?? 0, match.structure_similarity ?? 0, match.score ?? 0);
  return Math.round(Math.min(1, Math.max(0, similarity)) * 100);
}
