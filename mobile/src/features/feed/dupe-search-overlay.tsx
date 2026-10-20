import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { Image as ImageIcon, Link, Search, X } from 'lucide-react-native';
import { useMemo } from 'react';
import { Alert, Modal, ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import {
  dropMatches,
  feedDrops,
  feedProducts,
  productMatches,
  type PieceCategory,
  type SearchCategory,
} from '@/features/feed/fixtures';
import { SearchActionPill } from '@/features/search/action-pill';
import { extractProductFrame } from '@/features/search/extract-frame';
import { useVisualSearchStore } from '@/features/search/store';
import { t, type MessageKey } from '@/lib/i18n';
import { useDupeSearchStore } from '@/stores/dupe-search';
import { palette, radius } from '@/theme/tokens';

const FILTERS: { id: SearchCategory; label: MessageKey }[] = [
  { id: 'all', label: 'feed.filterAll' },
  { id: 'apparel', label: 'feed.filterApparel' },
  { id: 'shoes', label: 'feed.filterShoes' },
  { id: 'watches', label: 'feed.filterWatches' },
  { id: 'jewelry', label: 'feed.filterJewelry' },
  { id: 'eyewear', label: 'feed.filterEyewear' },
];

type Hit = {
  id: string;
  title: string;
  eyebrow: string;
  image: string;
  category: PieceCategory;
};

export function DupeSearchOverlay() {
  const open = useDupeSearchStore((state) => state.overlayOpen);
  const closeOverlay = useDupeSearchStore((state) => state.closeOverlay);
  const query = useDupeSearchStore((state) => state.query);
  const setQuery = useDupeSearchStore((state) => state.setQuery);
  const category = useDupeSearchStore((state) => state.category);
  const setCategory = useDupeSearchStore((state) => state.setCategory);
  const clearSearch = useDupeSearchStore((state) => state.clearSearch);
  const linkOpen = useDupeSearchStore((state) => state.linkOpen);
  const setLinkOpen = useDupeSearchStore((state) => state.setLinkOpen);
  const linkDraft = useDupeSearchStore((state) => state.linkDraft);
  const setLinkDraft = useDupeSearchStore((state) => state.setLinkDraft);
  const applyLink = useDupeSearchStore((state) => state.applyLink);
  const imageUri = useDupeSearchStore((state) => state.imageUri);
  const setImageUri = useDupeSearchStore((state) => state.setImageUri);
  const insets = useSafeAreaInsets();

  const hits = useMemo(() => {
    const drops: Hit[] = feedDrops.filter((drop) => dropMatches(drop, query, category)).map((drop) => ({
      id: `drop-${drop.id}`,
      title: drop.title,
      eyebrow: drop.detail,
      image: drop.image,
      category: drop.category,
    }));
    const products: Hit[] = feedProducts
      .filter((product) => productMatches(product, query, category))
      .map((product) => ({
        id: product.id,
        title: product.name,
        eyebrow: product.brand,
        image: product.image,
        category: product.category,
      }));
    return [...drops, ...products];
  }, [category, query]);

  async function pickPhoto() {
    const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) {
      Alert.alert(t('feed.photoDeniedTitle'), t('feed.photoDenied'));
      return;
    }

    const result = await ImagePicker.launchImageLibraryAsync({
      mediaTypes: ['images'],
      quality: 0.7,
    });

    const asset = result.canceled ? null : result.assets[0];
    if (asset) {
      setImageUri(asset.uri);
      useVisualSearchStore.getState().beginScan(asset.uri, 'shoes', { width: asset.width, height: asset.height });
      closeOverlay();
    }
  }

  function submitLink() {
    const raw = linkDraft;
    applyLink();
    const frame = extractProductFrame(raw);
    if (!frame) {
      return;
    }
    useVisualSearchStore.getState().setLinkDraft(raw);
    if (useVisualSearchStore.getState().prepareExtract(frame.source)) {
      closeOverlay();
    }
  }

  function choose(hit: Hit) {
    setQuery(hit.title);
    closeOverlay();
  }

  return (
    <Modal animationType="fade" onRequestClose={closeOverlay} transparent visible={open}>
      <View style={{ flex: 1, backgroundColor: palette.ink, paddingTop: insets.top + 8 }}>
        <View className="flex-row items-center justify-between px-5 pb-3">
          <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{t('feed.searchTitle')}</Text>
          <PressableScale accessibilityLabel={t('feed.closeA11y')} onPress={closeOverlay}>
            <View
              className="h-10 w-10 items-center justify-center"
              style={{
                borderRadius: radius.pill,
                backgroundColor: palette.raised,
                borderWidth: 1,
                borderColor: palette.line,
              }}
            >
              <X color={palette.ivory} size={16} strokeWidth={1.6} />
            </View>
          </PressableScale>
        </View>

        <View className="px-5">
          <GlassSurface radius={radius.pill}>
            <View className="h-12 flex-row items-center px-3">
              <Search color={palette.goldSoft} size={18} strokeWidth={1.5} />
              <TextInput
                accessibilityLabel={t('feed.searchA11y')}
                autoCapitalize="none"
                autoCorrect={false}
                autoFocus
                className="mx-2 flex-1 text-[15px] text-ivory"
                onChangeText={setQuery}
                placeholder={t('feed.searchPlaceholder')}
                placeholderTextColor={palette.faint}
                value={query}
              />
            </View>
          </GlassSurface>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 10 }}>
            <SearchActionPill
              active={Boolean(imageUri)}
              icon={<ImageIcon color={imageUri ? palette.goldSoft : palette.ivory} size={18} strokeWidth={1.75} />}
              label={t('search.upload')}
              onPress={() => void pickPhoto()}
            />
            <SearchActionPill
              active={linkOpen}
              icon={<Link color={linkOpen ? palette.goldSoft : palette.ivory} size={18} strokeWidth={1.75} />}
              label={t('feed.linkA11y')}
              onPress={() => setLinkOpen(!linkOpen)}
            />
          </View>

          {linkOpen ? (
            <View className="mt-2">
              <GlassSurface radius={radius.pill}>
                <View className="h-12 flex-row items-center px-3">
                  <TextInput
                    accessibilityLabel={t('feed.linkPlaceholder')}
                    autoCapitalize="none"
                    autoCorrect={false}
                    className="flex-1 text-[15px] text-ivory"
                    onChangeText={setLinkDraft}
                    placeholder={t('feed.linkPlaceholder')}
                    placeholderTextColor={palette.faint}
                    value={linkDraft}
                  />
                  <PressableScale accessibilityLabel={t('feed.linkApply')} onPress={submitLink}>
                    <Text className="px-2 text-[13px] font-semibold text-ivory">{t('feed.linkApply')}</Text>
                  </PressableScale>
                </View>
              </GlassSurface>
            </View>
          ) : null}
        </View>

        <ScrollView
          horizontal
          className="mt-4 max-h-12"
          contentContainerStyle={{ paddingHorizontal: 20, gap: 8 }}
          showsHorizontalScrollIndicator={false}
        >
          {FILTERS.map((filter) => {
            const selected = filter.id === category;
            return (
              <PressableScale
                accessibilityLabel={t(filter.label)}
                accessibilityState={{ selected }}
                key={filter.id}
                onPress={() => setCategory(filter.id)}
              >
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
                  <Text style={{ color: selected ? palette.goldSoft : palette.ivory, fontSize: 13, fontWeight: '600' }}>
                    {t(filter.label)}
                  </Text>
                </View>
              </PressableScale>
            );
          })}
          <PressableScale accessibilityLabel={t('feed.clearFilters')} onPress={clearSearch}>
            <View className="px-2 py-2">
              <Text className="text-[13px] font-semibold text-faint">{t('feed.clearFilters')}</Text>
            </View>
          </PressableScale>
        </ScrollView>

        <ScrollView
          className="mt-4 flex-1 px-5"
          contentContainerStyle={{ paddingBottom: Math.max(insets.bottom, 24), gap: 10 }}
          keyboardShouldPersistTaps="handled"
        >
          {hits.length === 0 ? (
            <Text className="pt-6 text-[15px] text-muted">{t('feed.empty')}</Text>
          ) : (
            hits.map((hit) => (
              <PressableScale accessibilityLabel={hit.title} block key={hit.id} onPress={() => choose(hit)}>
                <GlassSurface radius={radius.chip}>
                  <View className="flex-row items-center gap-3 px-3 py-3">
                    <Image
                      contentFit="cover"
                      source={{ uri: hit.image }}
                      style={{ width: 52, height: 64, borderRadius: radius.chip, backgroundColor: palette.ink }}
                    />
                    <View className="flex-1">
                      <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-muted" numberOfLines={1}>
                        {hit.eyebrow}
                      </Text>
                      <Text className="mt-1 text-[16px] font-medium text-ivory" numberOfLines={1}>
                        {hit.title}
                      </Text>
                    </View>
                  </View>
                </GlassSurface>
              </PressableScale>
            ))
          )}
        </ScrollView>
      </View>
    </Modal>
  );
}
