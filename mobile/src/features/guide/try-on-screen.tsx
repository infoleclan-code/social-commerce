import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState, type ComponentType } from 'react';
import { Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Ellipse, Rect } from 'react-native-svg';

import { fetchTryOn } from '@/api/discover';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

type CameraPiece = ComponentType<{ style?: object; facing?: 'front' | 'back' }>;

export function TryOnScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const [category, setCategory] = useState('shoes');
  const [cameraOn, setCameraOn] = useState(false);
  const [cameraView, setCameraView] = useState<{ View: CameraPiece } | null>(null);
  const scope = useQuery({ queryKey: ['try-on', category], queryFn: () => fetchTryOn(category) });
  const overlay = scope.data?.data.supported === true && scope.data.data.mode === 'camera_overlay';
  const showCamera = overlay && cameraOn && cameraView !== null;

  async function openCamera() {
    try {
      const camera = await import('expo-camera');
      const current = await camera.Camera.getCameraPermissionsAsync();
      const next = current.granted ? current : await camera.Camera.requestCameraPermissionsAsync();
      if (next.granted) {
        setCameraView({ View: camera.CameraView as CameraPiece });
        setCameraOn(true);
        return;
      }
    } catch {
      // The browser or simulator can refuse the camera. The still preview remains.
    }
    setCameraOn(false);
  }

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, paddingTop: insets.top + 16, paddingHorizontal: 20, gap: 14 }}>
      <Pressable accessibilityRole="button" onPress={() => router.back()}>
        <Text style={{ color: palette.ivory, fontWeight: '600' }}>{t('profile.back')}</Text>
      </Pressable>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('guide.tryOnTitle')}</Text>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        {(['shoes', 'glasses', 'hats'] as const).map((choice) => (
          <Pressable accessibilityRole="button" key={choice} onPress={() => { setCategory(choice); setCameraOn(false); }} style={pill}>
            <Text style={pillText}>{choice === 'shoes' ? t('guide.tryOnShoes') : choice === 'glasses' ? t('guide.tryOnGlasses') : t('guide.tryOnOther')}</Text>
          </Pressable>
        ))}
      </View>
      <Text style={{ color: palette.muted }}>{scope.data?.data.scope}</Text>
      <View style={{ height: 320, borderRadius: 16, overflow: 'hidden', backgroundColor: '#111' }}>
        {showCamera && cameraView ? <cameraView.View facing="front" style={{ flex: 1 }} /> : null}
        <View style={{ position: 'absolute', left: 0, right: 0, top: 0, bottom: 0, alignItems: 'center', justifyContent: 'center' }}>
          <Svg height="180" width="240" viewBox="0 0 240 180">
            {category === 'glasses' ? (
              <>
                <Ellipse cx="70" cy="90" rx="48" ry="32" stroke={palette.gold} strokeWidth="4" fill="transparent" />
                <Ellipse cx="170" cy="90" rx="48" ry="32" stroke={palette.gold} strokeWidth="4" fill="transparent" />
                <Rect x="112" y="86" width="16" height="6" fill={palette.gold} />
              </>
            ) : category === 'shoes' ? (
              <Ellipse cx="120" cy="100" rx="90" ry="36" stroke={palette.gold} strokeWidth="4" fill="rgba(212,175,55,0.25)" />
            ) : (
              <Rect x="70" y="40" rx="12" width="100" height="100" stroke={palette.gold} strokeWidth="4" fill="transparent" />
            )}
          </Svg>
        </View>
      </View>
      {overlay && !showCamera ? (
        <Pressable accessibilityRole="button" onPress={() => void openCamera()} style={pill}>
          <Text style={pillText}>{t('guide.useCamera')}</Text>
        </Pressable>
      ) : null}
      {showCamera ? null : <Text style={{ color: palette.goldSoft }}>{t('guide.cameraOff')}</Text>}
    </View>
  );
}

const pill = { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.12)' };
const pillText = { color: palette.ivory, fontSize: 13, fontWeight: '700' as const };
