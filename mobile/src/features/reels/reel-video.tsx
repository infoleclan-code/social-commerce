import { useEvent, useEventListener } from 'expo';
import { Image } from 'expo-image';
import { useVideoPlayer, VideoView, type VideoPlayer } from 'expo-video';
import { useEffect } from 'react';
import { Text, View } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withTiming, type SharedValue } from 'react-native-reanimated';

import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

type Props = {
  uri: string;
  posterUri: string | null;
  /** This page is the one snapped on screen. */
  active: boolean;
  /** Active, focused, in the foreground, and not paused by a tap. */
  playing: boolean;
  muted: boolean;
  /** 0..1 playback position. Written without a React render. */
  progress: SharedValue<number>;
};

const FILL = { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 } as const;

function configure(player: VideoPlayer, muted: boolean): void {
  player.loop = true;
  player.muted = muted;
  player.timeUpdateEventInterval = 0;
  player.audioMixingMode = 'auto';
  // A short minimum buffer so autoplay starts on the snap, with a few seconds kept ahead.
  player.bufferOptions = {
    preferredForwardBufferDuration: 4,
    minBufferForPlayback: 0.4,
    waitsToMinimizeStalling: false,
  };
}

function applyMuted(player: VideoPlayer, muted: boolean): void {
  player.muted = muted;
}

function syncPlayback(player: VideoPlayer, playing: boolean): void {
  if (!playing) {
    player.pause();
    return;
  }
  if (player.status === 'readyToPlay') {
    player.play();
  }
}

function syncActive(player: VideoPlayer, active: boolean): void {
  player.timeUpdateEventInterval = active ? 0.1 : 0;
  if (!active && player.status === 'readyToPlay' && player.currentTime > 0.05) {
    player.currentTime = 0;
  }
}

/**
 * One reel video.
 *
 * Autoplay runs only while `playing` is true, and only after the source reports `readyToPlay`.
 * The feed mounts this for the visible page and the next one. `useCaching` keeps that next file
 * on disk so the following swipe starts from the buffer instead of the network.
 */
export function ReelVideo({ uri, posterUri, active, playing, muted, progress }: Props) {
  const player = useVideoPlayer({ uri, useCaching: true }, (instance) => configure(instance, muted));
  const { status } = useEvent(player, 'statusChange', { status: player.status });
  const posterOpacity = useSharedValue(posterUri ? 1 : 0);

  useEffect(() => {
    applyMuted(player, muted);
  }, [player, muted]);

  useEffect(() => {
    syncActive(player, active);
    if (!active) {
      progress.set(0);
    }
    syncPlayback(player, playing);
  }, [player, active, playing, progress]);

  useEffect(() => {
    if (status === 'readyToPlay') {
      syncPlayback(player, playing);
    }
  }, [player, status, playing]);

  useEventListener(player, 'timeUpdate', ({ currentTime }) => {
    const duration = player.duration;
    if (duration > 0) {
      progress.set(Math.min(1, currentTime / duration));
    }
  });

  const posterStyle = useAnimatedStyle(() => ({ opacity: posterOpacity.get() }));

  return (
    <View style={[FILL, { pointerEvents: 'none', backgroundColor: palette.ink }]}>
      <VideoView
        allowsPictureInPicture={false}
        contentFit="cover"
        nativeControls={false}
        onFirstFrameRender={() => {
          posterOpacity.set(withTiming(0, { duration: 180 }));
        }}
        player={player}
        playsInline
        style={FILL}
      />
      {posterUri ? (
        <Animated.View style={[FILL, posterStyle, { pointerEvents: 'none' }]}>
          <Image
            accessibilityIgnoresInvertColors
            cachePolicy="memory-disk"
            contentFit="cover"
            recyclingKey={posterUri}
            source={{ uri: posterUri }}
            style={FILL}
            transition={0}
          />
        </Animated.View>
      ) : null}
      {status === 'error' ? (
        <View style={[FILL, { alignItems: 'center', justifyContent: 'center', padding: 32 }]}>
          <Text style={{ color: palette.muted, fontSize: 14, textAlign: 'center' }}>{t('reels.videoError')}</Text>
        </View>
      ) : null}
    </View>
  );
}
