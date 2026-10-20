import { Platform, View } from 'react-native';

import { palette } from '@/theme/tokens';

export function AmbientGlow() {
  return (
    <View style={{ position: 'absolute', top: 0, right: 0, left: 0, height: 360, pointerEvents: 'none' }}>
      <View
        style={{
          position: 'absolute',
          top: -40,
          alignSelf: 'center',
          width: 280,
          height: 280,
          borderRadius: 140,
          backgroundColor: palette.goldGlow,
          opacity: 0.9,
          ...(Platform.OS === 'web' ? { filter: 'blur(56px)' } : null),
        }}
      />
      <View
        style={{
          position: 'absolute',
          top: 80,
          right: -40,
          width: 180,
          height: 180,
          borderRadius: 90,
          backgroundColor: palette.goldMist,
          ...(Platform.OS === 'web' ? { filter: 'blur(40px)' } : null),
        }}
      />
      <View
        style={{
          position: 'absolute',
          top: 120,
          left: -30,
          width: 140,
          height: 140,
          borderRadius: 70,
          backgroundColor: palette.ivory,
          opacity: 0.04,
          ...(Platform.OS === 'web' ? { filter: 'blur(36px)' } : null),
        }}
      />
    </View>
  );
}
