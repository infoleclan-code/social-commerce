import { Image } from 'expo-image';
import { Heart, MessageCircle, Send, Volume2, VolumeX, type LucideIcon } from 'lucide-react-native';
import { useEffect, useRef } from 'react';
import { Text, View } from 'react-native';
import Animated, { useAnimatedStyle, useReducedMotion, useSharedValue, withSequence, withSpring } from 'react-native-reanimated';

import type { FeedPost, UserSummary } from '@/api/contracts';
import { PressableScale } from '@/components/ui/pressable-scale';
import { playSelection } from '@/lib/feedback';
import { t } from '@/lib/i18n';
import { formatCompact } from '@/lib/relative-time';
import { palette, radius } from '@/theme/tokens';

type Props = {
  post: FeedPost;
  muted: boolean;
  hasVideo: boolean;
  sharing: boolean;
  onLike: () => void;
  onComments: () => void;
  onOpenAuthor: () => void;
  onShare: () => void;
  onToggleMute: () => void;
};

const COUNT_SHADOW = { textShadowColor: 'rgba(0, 0, 0, 0.65)', textShadowRadius: 6, textShadowOffset: { width: 0, height: 1 } } as const;

/** The right-hand action column of a reel: author, like, comments, share, sound. */
export function ActionRail({ post, muted, hasVideo, sharing, onLike, onComments, onOpenAuthor, onShare, onToggleMute }: Props) {
  return (
    <View style={{ alignItems: 'center', gap: 18 }}>
      <AuthorBadge author={post.author} onPress={onOpenAuthor} />
      <LikeButton count={post.stats.likes} liked={post.viewer.liked} onPress={onLike} />
      <RailButton count={post.stats.comments} icon={MessageCircle} label={t('reels.comments')} onPress={onComments} />
      <RailButton busy={sharing} count={post.stats.shares} icon={Send} label={t('reels.share')} onPress={onShare} />
      {hasVideo ? (
        <RailButton icon={muted ? VolumeX : Volume2} label={muted ? t('reels.unmute') : t('reels.mute')} onPress={onToggleMute} />
      ) : null}
    </View>
  );
}

function AuthorBadge({ author, onPress }: { author: UserSummary; onPress: () => void }) {
  const initial = (author.display_name ?? author.username).slice(0, 1).toUpperCase();
  return (
    <PressableScale accessibilityLabel={t('reels.openProfile', { handle: author.username })} onPress={onPress}>
      <View
        style={{
          width: 46,
          height: 46,
          borderRadius: 23,
          borderWidth: 1.5,
          borderColor: palette.goldSoft,
          overflow: 'hidden',
          alignItems: 'center',
          justifyContent: 'center',
          backgroundColor: palette.overlay,
        }}
      >
        {author.avatar_url ? (
          <Image accessibilityIgnoresInvertColors cachePolicy="memory-disk" contentFit="cover" source={{ uri: author.avatar_url }} style={{ width: '100%', height: '100%' }} />
        ) : (
          <Text style={{ color: palette.goldSoft, fontSize: 17, fontWeight: '700' }}>{initial}</Text>
        )}
      </View>
    </PressableScale>
  );
}

function LikeButton({ liked, count, onPress }: { liked: boolean; count: number; onPress: () => void }) {
  const reduceMotion = useReducedMotion();
  const scale = useSharedValue(1);
  const wasLiked = useRef(liked);

  // Pop when the heart fills, whether from this button or a double tap on the video.
  useEffect(() => {
    if (liked && !wasLiked.current && !reduceMotion) {
      scale.set(withSequence(withSpring(1.38, { damping: 7, stiffness: 520, mass: 0.35 }), withSpring(1, { damping: 12, stiffness: 320 })));
    }
    wasLiked.current = liked;
  }, [liked, reduceMotion, scale]);

  const iconStyle = useAnimatedStyle(() => ({ transform: [{ scale: scale.get() }] }));
  const color = liked ? palette.goldSoft : palette.ivory;

  return (
    <PressableScale
      accessibilityLabel={liked ? t('reels.unlike') : t('reels.like')}
      accessibilityState={{ selected: liked }}
      accessibilityValue={{ text: formatCompact(count) }}
      onPress={() => {
        playSelection();
        onPress();
      }}
    >
      <View style={{ alignItems: 'center', minWidth: 48, minHeight: 48, justifyContent: 'center' }}>
        <Animated.View style={iconStyle}>
          <Heart color={color} fill={liked ? color : 'transparent'} size={30} strokeWidth={1.8} />
        </Animated.View>
        <Text style={[{ marginTop: 3, color: palette.ivory, fontSize: 12, fontWeight: '600' }, COUNT_SHADOW]}>{formatCompact(count)}</Text>
      </View>
    </PressableScale>
  );
}

function RailButton({
  icon: Icon,
  label,
  count,
  busy = false,
  onPress,
}: {
  icon: LucideIcon;
  label: string;
  count?: number;
  busy?: boolean;
  onPress: () => void;
}) {
  return (
    <PressableScale
      accessibilityLabel={label}
      accessibilityState={{ busy }}
      accessibilityValue={typeof count === 'number' ? { text: formatCompact(count) } : undefined}
      disabled={busy}
      onPress={() => {
        playSelection();
        onPress();
      }}
    >
      <View style={{ alignItems: 'center', minWidth: 48, minHeight: 48, justifyContent: 'center', opacity: busy ? 0.5 : 1 }}>
        <View
          style={{
            width: 44,
            height: 44,
            borderRadius: radius.pill,
            alignItems: 'center',
            justifyContent: 'center',
            backgroundColor: 'rgba(0, 0, 0, 0.28)',
          }}
        >
          <Icon color={palette.ivory} size={24} strokeWidth={1.8} />
        </View>
        {typeof count === 'number' ? (
          <Text style={[{ marginTop: 3, color: palette.ivory, fontSize: 12, fontWeight: '600' }, COUNT_SHADOW]}>{formatCompact(count)}</Text>
        ) : null}
      </View>
    </PressableScale>
  );
}
