import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { adminApi, loginAdmin, type AdminSession } from '@/api/social-commerce';
import { t } from '@/lib/i18n';
import { palette } from '@/theme/tokens';

export function AdminScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const queryClient = useQueryClient();
  const [email, setEmail] = useState('admin@example.com');
  const [password, setPassword] = useState('');
  const [otp, setOtp] = useState('');
  const [session, setSession] = useState<AdminSession | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const token = session?.token ?? '';
  const panel = useQuery({
    queryKey: ['admin', token],
    enabled: session !== null,
    queryFn: async () => {
      const [auctions, products, sellers, posts, comments, users] = await Promise.all([
        adminApi.auctions(token),
        adminApi.products(token),
        adminApi.sellers(token),
        adminApi.posts(token),
        adminApi.comments(token),
        adminApi.users(token),
      ]);
      return { auctions, products, sellers, posts, comments, users };
    },
  });

  async function signIn() {
    setNote(null);
    try {
      setSession(await loginAdmin(email.trim(), password, otp.trim()));
    } catch {
      setNote(t('admin.otp'));
    }
  }

  async function run(action: () => Promise<unknown>) {
    try {
      await action();
      await queryClient.invalidateQueries({ queryKey: ['admin', token] });
    } catch {
      setNote(t('admin.title'));
    }
  }

  const product = panel.data?.products[0];
  const auction = panel.data?.auctions[0];
  const seller = panel.data?.sellers[0];
  const post = panel.data?.posts[0];
  const comment = panel.data?.comments[0];
  const member = panel.data?.users.find((user) => user.role !== 'admin');

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, paddingTop: insets.top + 16, paddingHorizontal: 20, gap: 14 }}>
      <Pressable accessibilityRole="button" onPress={() => router.back()}>
        <Text style={{ color: palette.ivory, fontWeight: '600' }}>{t('profile.back')}</Text>
      </Pressable>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('admin.title')}</Text>
      {session === null ? (
        <View style={{ gap: 10 }}>
          <TextInput accessibilityLabel="Email" autoCapitalize="none" onChangeText={setEmail} value={email} placeholder="Email" placeholderTextColor={palette.faint} style={field} />
          <TextInput accessibilityLabel="Password" onChangeText={setPassword} secureTextEntry value={password} placeholder="Password" placeholderTextColor={palette.faint} style={field} />
          <TextInput accessibilityLabel={t('admin.otp')} keyboardType="number-pad" onChangeText={setOtp} value={otp} placeholder={t('admin.otp')} placeholderTextColor={palette.faint} style={field} />
          <Pressable accessibilityRole="button" accessibilityLabel={t('admin.signIn')} onPress={() => void signIn()} style={button}>
            <Text style={buttonText}>{t('admin.signIn')}</Text>
          </Pressable>
        </View>
      ) : (
        <View style={{ gap: 10 }}>
          <Text style={{ color: palette.muted }}>{session.user.username}</Text>
          {product ? (
            <View style={{ flexDirection: 'row', gap: 8, flexWrap: 'wrap' }}>
              <Pressable accessibilityRole="button" accessibilityLabel={t('admin.schedule')} onPress={() => void run(() => adminApi.scheduleAuction(token, product.id))} style={pill}>
                <Text style={pillText}>{t('admin.schedule')}</Text>
              </Pressable>
              <Pressable accessibilityRole="button" accessibilityLabel={t('admin.blockListing')} onPress={() => void run(() => adminApi.blockProduct(token, product.id))} style={pill}>
                <Text style={pillText}>{t('admin.blockListing')}</Text>
              </Pressable>
            </View>
          ) : null}
          {auction ? (
            <Pressable accessibilityRole="button" accessibilityLabel={t('admin.end')} onPress={() => void run(() => adminApi.endAuction(token, auction.id))} style={pill}>
              <Text style={pillText}>{`${t('admin.end')} ${auction.title}`}</Text>
            </Pressable>
          ) : null}
          {seller ? (
            <Pressable accessibilityRole="button" accessibilityLabel={t('admin.verify')} onPress={() => void run(() => adminApi.verifySeller(token, seller.user_id))} style={pill}>
              <Text style={pillText}>{`${t('admin.verify')} ${seller.username}`}</Text>
            </Pressable>
          ) : null}
          {post ? (
            <Pressable accessibilityRole="button" accessibilityLabel={t('admin.hide')} onPress={() => void run(() => (post.hidden ? adminApi.unhidePost(token, post.id) : adminApi.hidePost(token, post.id)))} style={pill}>
              <Text style={pillText}>{`${post.hidden ? t('admin.unhide') : t('admin.hide')} ${post.username}`}</Text>
            </Pressable>
          ) : null}
          {comment ? (
            <Pressable accessibilityRole="button" accessibilityLabel={t('admin.remove')} onPress={() => void run(() => adminApi.removeComment(token, comment.id))} style={pill}>
              <Text style={pillText}>{`${t('admin.remove')} ${comment.username}`}</Text>
            </Pressable>
          ) : null}
          {member ? (
            <Pressable accessibilityRole="button" accessibilityLabel={t('admin.ban')} onPress={() => void run(() => adminApi.banUser(token, member.id))} style={pill}>
              <Text style={pillText}>{`${t('admin.ban')} ${member.username}`}</Text>
            </Pressable>
          ) : null}
        </View>
      )}
      {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
    </View>
  );
}

const field = { color: palette.ivory, borderColor: palette.line, borderWidth: 1, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12 };
const button = { backgroundColor: palette.gold, borderRadius: 999, paddingVertical: 12, alignItems: 'center' as const };
const buttonText = { color: palette.ink, fontWeight: '700' as const };
const pill = { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.12)' };
const pillText = { color: palette.ivory, fontSize: 13, fontWeight: '700' as const };
