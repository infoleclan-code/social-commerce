import { Image } from 'expo-image';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Modal, Pressable, ScrollView, Text, View, useWindowDimensions } from 'react-native';
import Animated, {
  cancelAnimation,
  runOnJS,
  useAnimatedStyle,
  useSharedValue,
  withRepeat,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { PressableScale } from '@/components/ui/pressable-scale';
import { feedProducts } from '@/features/feed/fixtures';
import { budgetCurrency, fabricsIn, rankDupes, useDupeSearch } from '@/features/dupes/use-dupe-search';
import { t, type MessageKey } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { VisionError, type DupeResult, type VisionFailure, type VisionMatch } from '@/services/ai-vision';
import { useCheckoutStore } from '@/stores/checkout';
import { useDupeScanStore } from '@/stores/dupe-scan';
import { palette, radius } from '@/theme/tokens';

const BUDGETS: { id: string; maxMinor: number | null }[] = [
  { id: 'any', maxMinor: null },
  { id: '200', maxMinor: 20_000 },
  { id: '500', maxMinor: 50_000 },
  { id: '1000', maxMinor: 100_000 },
];

const FAILURE_KEY: Record<VisionFailure, MessageKey> = {
  timeout: 'dupe.timeout',
  unavailable: 'dupe.unavailable',
  invalid: 'dupe.invalid',
};

export function DupeFinderSheet() {
  const phase = useDupeScanStore((state) => state.phase);
  const productId = useDupeScanStore((state) => state.productId);
  const close = useDupeScanStore((state) => state.close);
  const [visible, setVisible] = useState(false);
  const { height } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const translateY = useSharedValue(height);
  const generation = useRef(0);
  const dismissGeneration = useRef(0);
  const closing = useRef(false);
  const sheetHeight = Math.max(560, Math.round(height * 0.92));
  const open = phase === 'results' && productId !== null;
  const imageUrl =
    phase === 'idle' || productId === null
      ? null
      : (feedProducts.find((item) => item.id === productId)?.image ?? null);
  const search = useDupeSearch(imageUrl);

  useEffect(() => {
    generation.current += 1;
    closing.current = false;
    if (!open) {
      setVisible(false);
      return;
    }
    setVisible(true);
    translateY.value = sheetHeight;
    translateY.value = withSpring(0, { damping: 24, stiffness: 240, mass: 0.75 });
  }, [open, sheetHeight, translateY]);

  function finishClose() {
    closing.current = false;
    if (dismissGeneration.current !== generation.current) {
      return;
    }
    setVisible(false);
    close();
  }

  function releaseDismiss() {
    closing.current = false;
  }

  function dismiss() {
    if (closing.current) {
      return;
    }
    closing.current = true;
    dismissGeneration.current = generation.current;
    translateY.value = withTiming(sheetHeight, { duration: 240 }, (finished) => {
      if (finished) {
        runOnJS(finishClose)();
      } else {
        runOnJS(releaseDismiss)();
      }
    });
  }

  const sheetStyle = useAnimatedStyle(() => ({
    transform: [{ translateY: translateY.value }],
  }));

  return (
    <Modal animationType="none" onRequestClose={dismiss} transparent visible={visible && open}>
      <View style={{ flex: 1, justifyContent: 'flex-end' }}>
        <Pressable
          accessibilityLabel={t('feed.closeA11y')}
          onPress={dismiss}
          style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: palette.scrim }}
        />
        <Animated.View
          style={[
            {
              height: sheetHeight,
              borderTopLeftRadius: radius.sheet,
              borderTopRightRadius: radius.sheet,
              overflow: 'hidden',
              borderWidth: 1,
              borderColor: palette.line,
              backgroundColor: palette.ink,
            },
            sheetStyle,
          ]}
        >
          {open && productId ? (
            <FinderBody bottom={Math.max(insets.bottom, 20)} productId={productId} search={search} />
          ) : null}
        </Animated.View>
      </View>
    </Modal>
  );
}

type DupeSearchQuery = ReturnType<typeof useDupeSearch>;

function FinderBody({
  bottom,
  productId,
  search,
}: {
  bottom: number;
  productId: string;
  search: DupeSearchQuery;
}) {
  const product = feedProducts.find((item) => item.id === productId) ?? null;
  const variant = useDupeScanStore((state) => state.variant);
  const setVariant = useDupeScanStore((state) => state.setVariant);
  const colorMatch = useDupeScanStore((state) => state.colorMatch);
  const setColorMatch = useDupeScanStore((state) => state.setColorMatch);
  const material = useDupeScanStore((state) => state.material);
  const setMaterial = useDupeScanStore((state) => state.setMaterial);
  const budgetMinor = useDupeScanStore((state) => state.budgetMinor);
  const setBudgetMinor = useDupeScanStore((state) => state.setBudgetMinor);
  const close = useDupeScanStore((state) => state.close);
  const openPrepared = useCheckoutStore((state) => state.openPrepared);
  const match = search.data;
  const precise = match
    ? {
        ...match,
        exact: match.exact && match.exact.similarityScore >= 75 ? match.exact : null,
        dupes: match.dupes.filter((dupe) => dupe.similarityScore >= 75),
      }
    : undefined;
  const view = sheetView(search, Boolean(product?.image), product !== null, precise);

  const currency = precise ? budgetCurrency(precise) : null;
  const revealPrice = Boolean(product?.showPrice && currency);
  const dupes = useMemo(() => {
    if (!precise) {
      return [];
    }
    return rankDupes(precise, {
      colorMatch,
      fabric: material,
      budgetMinor: revealPrice ? budgetMinor : null,
      currency: revealPrice ? currency : null,
    });
  }, [budgetMinor, colorMatch, currency, precise, material, revealPrice]);

  const sizes = useMemo(() => sizesFor(precise), [precise]);

  useEffect(() => {
    const next = sizes[0];
    if (next && !sizes.includes(variant)) {
      setVariant(next);
    }
  }, [setVariant, sizes, variant]);

  function buy(result: DupeResult, kind: 'exact' | 'dupe') {
    if (!product) {
      return;
    }
    const selected = result.variants.includes(variant) ? variant : (result.variants[0] ?? variant);
    const price = kind === 'exact' ? result.originalPrice : result.dupePrice;
    const showPrice = product.showPrice && price !== null;
    const slotId = kind === 'exact' ? product.slotId : result.id;
    openPrepared({
      slotId,
      action: 'buy',
      title: result.title,
      eyebrow: result.merchantName,
      image: result.image,
      showPrice,
      price: showPrice ? price : null,
      cashbackBadge: null,
      expectedOn: product.expectedOn,
      endsAt: product.endsAt,
      purchased: kind === 'exact' ? product.purchased : 0,
      goal: kind === 'exact' ? product.goal : 1,
      variants: result.variants,
      variant: selected,
      merchantId: result.merchantId,
      merchantUrl: result.merchantUrl,
    });
    if (useCheckoutStore.getState().draft?.slotId === slotId) {
      close();
    }
  }

  return (
    <ScrollView
      className="px-5 pt-4"
      contentContainerStyle={{ paddingBottom: bottom, gap: 16 }}
      showsVerticalScrollIndicator={false}
    >
      <View className="h-1 w-10 self-center rounded-full" style={{ backgroundColor: palette.line }} />
      <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{t('dupe.results')}</Text>
      {view === 'loading' ? null : (
        <Text className="text-[26px] font-medium text-ivory">{precise?.exact?.title ?? product?.name ?? t('dupe.results')}</Text>
      )}

      {view === 'loading' ? <LoadingState /> : null}
      {view === 'error' ? (
        <NoticeState
          message={t(FAILURE_KEY[failureOf(search.error)])}
          onRetry={() => void search.refetch()}
          title={t('dupe.errorTitle')}
        />
      ) : null}
      {view === 'empty' ? (
        <NoticeState message={t('dupe.noMatch')} onRetry={() => void search.refetch()} title={t('dupe.noMatchTitle')} />
      ) : null}

      {view === 'results' && precise?.features ? (
        <Text className="text-[13px] text-muted">
          {t('dupe.features', {
            material: precise.features.material,
            silhouette: precise.features.silhouette,
            vibe: precise.features.brandVibe,
          })}
        </Text>
      ) : null}

      {view === 'results' && precise ? (
        <Results
          budgetMinor={budgetMinor}
          colorMatch={colorMatch}
          currency={currency}
          dupes={dupes}
          match={precise}
          material={material}
          onBuy={buy}
          revealPrice={revealPrice}
          setBudgetMinor={setBudgetMinor}
          setColorMatch={setColorMatch}
          setMaterial={setMaterial}
          setVariant={setVariant}
          sizes={sizes}
          variant={variant}
        />
      ) : null}
    </ScrollView>
  );
}

function sheetView(
  search: DupeSearchQuery,
  hasImage: boolean,
  hasProduct: boolean,
  precise: VisionMatch | undefined,
): 'loading' | 'error' | 'empty' | 'results' {
  const empty = Boolean(precise && !precise.exact && precise.dupes.length === 0);
  if ((hasImage && precise === undefined && !search.isError) || (search.isFetching && (search.isError || empty))) {
    return 'loading';
  }
  if (search.isError || !hasProduct || !hasImage) {
    return 'error';
  }
  if (empty) {
    return 'empty';
  }
  if (precise && (precise.exact || precise.dupes.length > 0)) {
    return 'results';
  }
  return 'loading';
}

function sizesFor(match: VisionMatch | undefined): string[] {
  if (match?.exact && match.exact.variants.length > 0) {
    return match.exact.variants;
  }
  const first = match?.dupes[0];
  if (first && first.variants.length > 0) {
    return first.variants;
  }
  return [];
}

function failureOf(error: unknown): VisionFailure {
  if (error instanceof VisionError) {
    return error.reason;
  }
  return 'unavailable';
}

function Results({
  match,
  dupes,
  sizes,
  variant,
  setVariant,
  colorMatch,
  setColorMatch,
  material,
  setMaterial,
  budgetMinor,
  setBudgetMinor,
  revealPrice,
  currency,
  onBuy,
}: {
  match: VisionMatch;
  dupes: DupeResult[];
  sizes: string[];
  variant: string;
  setVariant: (variant: string) => void;
  colorMatch: boolean;
  setColorMatch: (colorMatch: boolean) => void;
  material: string;
  setMaterial: (material: string) => void;
  budgetMinor: number | null;
  setBudgetMinor: (budgetMinor: number | null) => void;
  revealPrice: boolean;
  currency: string | null;
  onBuy: (result: DupeResult, kind: 'exact' | 'dupe') => void;
}) {
  const fabrics = fabricsIn(match);
  const exact = match.exact;
  const sample = exact?.originalPrice ?? match.dupes.find((dupe) => dupe.dupePrice)?.dupePrice ?? null;

  return (
    <>
      {sizes.length > 0 ? (
        <>
          <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{t('checkout.size')}</Text>
          <View className="flex-row flex-wrap gap-2">
            {sizes.map((size) => {
              const selected = size === variant;
              return (
                <PressableScale accessibilityState={{ selected }} key={size} onPress={() => setVariant(size)}>
                  <View
                    style={{
                      borderRadius: radius.pill,
                      borderWidth: 1,
                      borderColor: selected ? palette.gold : palette.line,
                      backgroundColor: selected ? palette.goldWash : palette.overlay,
                      paddingHorizontal: 14,
                      paddingVertical: 8,
                    }}
                  >
                    <Text style={{ color: selected ? palette.goldSoft : palette.ivory, fontSize: 14, fontWeight: '600' }}>
                      {size}
                    </Text>
                  </View>
                </PressableScale>
              );
            })}
          </View>
        </>
      ) : null}

      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
        <FilterChip label={t('dupe.colorMatch')} onPress={() => setColorMatch(!colorMatch)} selected={colorMatch} />
        <FilterChip label={t('dupe.material.all')} onPress={() => setMaterial('all')} selected={material === 'all'} />
        {fabrics.map((fabric) => (
          <FilterChip key={fabric} label={fabric} onPress={() => setMaterial(fabric)} selected={material === fabric} />
        ))}
      </ScrollView>

      {revealPrice && sample && currency ? (
        <View className="gap-2">
          <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{t('dupe.budget')}</Text>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
            {BUDGETS.map((choice) => {
              const selected = choice.maxMinor === budgetMinor;
              const label =
                choice.maxMinor === null
                  ? t('dupe.anyBudget')
                  : formatMoney({ amount_minor: choice.maxMinor, currency, minor_unit: sample.minor_unit });
              return (
                <FilterChip key={choice.id} label={label} onPress={() => setBudgetMinor(choice.maxMinor)} selected={selected} />
              );
            })}
          </ScrollView>
        </View>
      ) : null}

      {exact ? (
        <>
          <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{t('dupe.exact')}</Text>
          <ResultCard
            kind="exact"
            onBuy={() => onBuy(exact, 'exact')}
            result={exact}
            revealPrice={revealPrice}
            sameCut={false}
          />
        </>
      ) : null}

      <View>
        <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{t('dupe.verified')}</Text>
        <Text className="mt-1 text-[13px] text-muted">{t('dupe.bestValue')}</Text>
      </View>

      {dupes.length === 0 ? (
        <Text className="text-[15px] text-muted">{t('dupe.empty')}</Text>
      ) : (
        dupes.map((dupe) => (
          <ResultCard
            key={dupe.id}
            kind="dupe"
            onBuy={() => onBuy(dupe, 'dupe')}
            result={dupe}
            revealPrice={revealPrice}
            sameCut={sameCutAs(match, dupe)}
          />
        ))
      )}
    </>
  );
}

function sameCutAs(match: VisionMatch, dupe: DupeResult): boolean {
  const source = match.exact?.attributes ?? match.attributes;
  return (
    dupe.attributes.fabric.toLowerCase() === source.fabric.toLowerCase() &&
    dupe.attributes.fit.toLowerCase() === source.fit.toLowerCase()
  );
}

function ResultCard({
  result,
  kind,
  revealPrice,
  sameCut,
  onBuy,
}: {
  result: DupeResult;
  kind: 'exact' | 'dupe';
  revealPrice: boolean;
  sameCut: boolean;
  onBuy: () => void;
}) {
  const price = kind === 'exact' ? result.originalPrice : result.dupePrice;
  const showPrice = revealPrice && price !== null;
  const original = kind === 'dupe' && revealPrice ? result.originalPrice : null;

  return (
    <View
      style={{
        borderRadius: radius.panel,
        borderWidth: 1,
        borderColor: palette.line,
        backgroundColor: palette.raised,
        padding: 12,
        gap: 8,
      }}
    >
      <View className="flex-row gap-3">
        <Image
          contentFit="cover"
          source={{ uri: result.image }}
          style={{ width: 72, height: 88, borderRadius: radius.chip, backgroundColor: palette.ink }}
        />
        <View className="flex-1">
          {kind === 'dupe' ? (
            <Text className="text-[12px] font-semibold text-gold-soft">
              {t('dupe.match', { score: String(result.similarityScore) })}
            </Text>
          ) : null}
          <Text className="mt-1 text-[11px] font-semibold uppercase tracking-[1.5px] text-muted">{result.merchantName}</Text>
          <Text className="mt-1 text-[16px] font-medium text-ivory">{result.title}</Text>
          <Text className="mt-1 text-[13px] text-muted">
            {result.attributes.fabric} · {result.attributes.color} · {result.attributes.fit}
          </Text>
          <Text className="mt-1 text-[13px] text-muted">
            {t('dupe.confidence', { score: String(result.visualMatchConfidence) })}
          </Text>
          <Text className="mt-1 text-[13px] text-gold-soft" numberOfLines={1}>
            {result.merchantUrl}
          </Text>
          {showPrice && price ? <Text className="mt-1 text-[15px] font-medium text-ivory">{formatMoney(price)}</Text> : null}
          {original ? (
            <Text className="mt-1 text-[13px] text-muted">{t('dupe.original', { amount: formatMoney(original) })}</Text>
          ) : null}
        </View>
      </View>
      {sameCut ? (
        <View
          style={{
            alignSelf: 'flex-start',
            borderRadius: radius.pill,
            borderWidth: 1,
            borderColor: palette.gold,
            backgroundColor: palette.goldWash,
            paddingHorizontal: 10,
            paddingVertical: 6,
          }}
        >
          <Text style={{ color: palette.goldSoft, fontSize: 12, fontWeight: '600' }}>{t('dupe.sameCut')}</Text>
        </View>
      ) : null}
      <BuyButton label={t('dupe.buyA11y', { name: result.title })} onPress={onBuy} />
    </View>
  );
}

function LoadingState() {
  return (
    <View
      accessibilityLabel={t('dupe.loading')}
      accessibilityRole="progressbar"
      accessibilityState={{ busy: true }}
      className="gap-3"
    >
      <Text className="text-[15px] text-muted">{t('dupe.loading')}</Text>
      <View accessibilityElementsHidden className="gap-3" importantForAccessibility="no-hide-descendants">
        <SkeletonBlock height={28} width="72%" />
        <View className="flex-row gap-2">
          <SkeletonBlock height={32} width={96} />
          <SkeletonBlock height={32} width={84} />
          <SkeletonBlock height={32} width={72} />
        </View>
        <SkeletonCard />
        <SkeletonCard />
      </View>
    </View>
  );
}

function SkeletonCard() {
  return (
    <View
      style={{
        borderRadius: radius.panel,
        borderWidth: 1,
        borderColor: palette.line,
        backgroundColor: palette.raised,
        padding: 12,
        gap: 10,
      }}
    >
      <View className="flex-row gap-3">
        <SkeletonBlock height={88} width={72} />
        <View className="flex-1 gap-2">
          <SkeletonBlock height={12} width="40%" />
          <SkeletonBlock height={16} width="72%" />
          <SkeletonBlock height={12} width="88%" />
          <SkeletonBlock height={14} width="36%" />
        </View>
      </View>
      <SkeletonBlock height={48} />
    </View>
  );
}

function SkeletonBlock({ height, width = '100%' }: { height: number; width?: number | `${number}%` }) {
  const pulse = useSharedValue(0.55);

  useEffect(() => {
    pulse.value = withRepeat(withTiming(1, { duration: 900 }), -1, true);
    return () => cancelAnimation(pulse);
  }, [pulse]);

  const animated = useAnimatedStyle(() => ({
    opacity: pulse.value,
  }));

  return (
    <Animated.View
      style={[
        {
          height,
          width,
          borderRadius: radius.chip,
          backgroundColor: palette.lineStrong,
        },
        animated,
      ]}
    />
  );
}

function NoticeState({ title, message, onRetry }: { title: string; message: string; onRetry: () => void }) {
  return (
    <View accessibilityRole="alert" className="gap-4">
      <Text className="text-[22px] font-medium text-ivory">{title}</Text>
      <Text className="text-[15px] leading-6 text-muted">{message}</Text>
      <PressableScale accessibilityLabel={t('dupe.retry')} block onPress={onRetry}>
        <View className="h-12 items-center justify-center" style={{ borderRadius: radius.pill, backgroundColor: palette.ivory }}>
          <Text className="text-[15px] font-semibold tracking-wide text-ink">{t('dupe.retry')}</Text>
        </View>
      </PressableScale>
    </View>
  );
}

function FilterChip({ label, selected, onPress }: { label: string; selected: boolean; onPress: () => void }) {
  return (
    <PressableScale accessibilityLabel={label} accessibilityState={{ selected }} onPress={onPress}>
      <View
        style={{
          borderRadius: radius.pill,
          borderWidth: 1,
          borderColor: selected ? palette.gold : palette.line,
          backgroundColor: selected ? palette.goldWash : palette.overlay,
          paddingHorizontal: 14,
          paddingVertical: 8,
        }}
      >
        <Text style={{ color: selected ? palette.goldSoft : palette.ivory, fontSize: 13, fontWeight: '600' }}>{label}</Text>
      </View>
    </PressableScale>
  );
}

function BuyButton({ label, onPress }: { label: string; onPress: () => void }) {
  return (
    <PressableScale accessibilityLabel={label} block onPress={onPress}>
      <View className="h-12 items-center justify-center" style={{ borderRadius: radius.pill, backgroundColor: palette.ivory }}>
        <Text className="text-[15px] font-semibold tracking-wide text-ink">{t('drops.instantBuy')}</Text>
      </View>
    </PressableScale>
  );
}
