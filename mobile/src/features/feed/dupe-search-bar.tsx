import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { Image as ImageIcon, Link, Search } from 'lucide-react-native';
import { Alert, Text, View } from 'react-native';

import { GlassSurface } from '@/components/ui/glass-surface';
import { PressableScale } from '@/components/ui/pressable-scale';
import { SearchActionPill } from '@/features/search/action-pill';
import { useVisualSearchStore } from '@/features/search/store';
import { t } from '@/lib/i18n';
import { useDupeSearchStore } from '@/stores/dupe-search';
import { palette, radius, elevation } from '@/theme/tokens';

export function DupeSearchBar() {
  const query = useDupeSearchStore((state) => state.query);
  const category = useDupeSearchStore((state) => state.category);
  const openOverlay = useDupeSearchStore((state) => state.openOverlay);
  const attachedLink = useDupeSearchStore((state) => state.attachedLink);
  const imageUri = useDupeSearchStore((state) => state.imageUri);
  const clearSearch = useDupeSearchStore((state) => state.clearSearch);
  const setImageUri = useDupeSearchStore((state) => state.setImageUri);
  const mediaUri = useVisualSearchStore((state) => state.mediaUri);
  const offers = useVisualSearchStore((state) => state.offers);
  const openSearch = useVisualSearchStore((state) => state.openSearch);
  const openPaste = useVisualSearchStore((state) => state.openPaste);
  const beginScan = useVisualSearchStore((state) => state.beginScan);
  const reset = useVisualSearchStore((state) => state.reset);
  const trimmed = query.trim();
  const filtering = trimmed.length > 0 || category !== 'all' || Boolean(imageUri) || Boolean(attachedLink);
  const thumb = mediaUri ?? imageUri;
  const session = Boolean(thumb) || offers.length > 0 || Boolean(attachedLink);

  async function upload() {
    const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) {
      Alert.alert(t('feed.photoDeniedTitle'), t('feed.photoDenied'));
      return;
    }
    const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.8 });
    const asset = result.canceled ? null : result.assets[0];
    if (!asset) {
      return;
    }
    setImageUri(asset.uri);
    beginScan(asset.uri, 'shoes', { width: asset.width, height: asset.height });
  }

  function clear() {
    clearSearch();
    reset();
  }

  return (
    <View className="gap-3">
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
        <View style={{ flex: 1 }}>
          <GlassSurface radius={radius.pill}>
            <PressableScale accessibilityLabel={t('feed.searchA11y')} block onPress={openOverlay}>
              <View className="h-12 flex-row items-center px-3">
                <Search color={filtering ? palette.goldSoft : palette.gold} size={18} strokeWidth={1.75} />
                <Text className={`mx-2 flex-1 text-[15px] ${trimmed ? 'text-ivory' : 'text-faint'}`} numberOfLines={1}>
                  {trimmed || t('feed.searchPlaceholder')}
                </Text>
              </View>
            </PressableScale>
          </GlassSurface>
        </View>
        {thumb ? (
          <PressableScale accessibilityLabel={t('search.cameraA11y')} onPress={openSearch}>
            <GlassSurface radius={radius.chip} style={{ borderColor: palette.gold, boxShadow: elevation.gold }}>
              <Image
                contentFit="contain"
                source={{ uri: thumb }}
                style={{ width: 44, height: 44, backgroundColor: palette.ink }}
              />
            </GlassSurface>
          </PressableScale>
        ) : null}
      </View>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        <SearchActionPill
          active={Boolean(thumb)}
          icon={<ImageIcon color={thumb ? palette.goldSoft : palette.ivory} size={18} strokeWidth={1.75} />}
          label={t('search.upload')}
          onPress={() => void upload()}
        />
        <SearchActionPill
          active={Boolean(attachedLink)}
          icon={<Link color={attachedLink ? palette.goldSoft : palette.ivory} size={18} strokeWidth={1.75} />}
          label={t('feed.linkA11y')}
          onPress={openPaste}
        />
        {session ? (
          <SearchActionPill
            icon={<Search color={palette.ivory} size={18} strokeWidth={1.75} />}
            label={t('search.clear')}
            onPress={clear}
          />
        ) : null}
      </View>
    </View>
  );
}
