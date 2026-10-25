import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { adminApi, loginAdmin, type AdminSession } from '@/api/social-commerce';
import { purchasesEnabled } from '@/lib/flags';
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
    queryKey: ['admin', token, purchasesEnabled],
    enabled: session !== null,
    queryFn: async () => {
      const [posts, comments, users] = await Promise.all([
        adminApi.posts(token),
        adminApi.comments(token),
        adminApi.users(token),
      ]);
      if (!purchasesEnabled) {
        return { auctions: [], products: [], sellers: [], posts, comments, users };
      }
      const [auctions, products, sellers] = await Promise.all([
        adminApi.auctions(token),
        adminApi.products(token),
        adminApi.sellers(token),
      ]);
      return { auctions, products, sellers, posts, comments, users };
    },
  });

  async function signIn() {
    setNote(null);
    try {
      setSession(await loginAdmin(email.trim(), password, otp.trim()));
    } catch (error) {
      setNote(failure(error, true));
    }
  }

  async function run(action: () => Promise<unknown>) {
    setNote(null);
    try {
      await action();
      await queryClient.invalidateQueries({ queryKey: ['admin', token, purchasesEnabled] });
    } catch (error) {
      setNote(failure(error, false));
    }
  }

  return (
    <ScrollView contentContainerStyle={{ paddingTop: insets.top + 16, paddingHorizontal: 20, paddingBottom: insets.bottom + 40, gap: 14 }} style={{ flex: 1, backgroundColor: palette.ink }}>
      <Pressable accessibilityRole="button" onPress={() => router.back()}>
        <Text style={{ color: palette.ivory, fontWeight: '600' }}>{t('profile.back')}</Text>
      </Pressable>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{t('admin.title')}</Text>
      {session === null ? (
        <View style={{ gap: 10 }}>
          <TextInput accessibilityLabel={t('admin.email')} autoCapitalize="none" onChangeText={setEmail} value={email} placeholder={t('admin.email')} placeholderTextColor={palette.faint} style={field} />
          <TextInput accessibilityLabel={t('admin.password')} onChangeText={setPassword} secureTextEntry value={password} placeholder={t('admin.password')} placeholderTextColor={palette.faint} style={field} />
          <TextInput accessibilityLabel={t('admin.otp')} keyboardType="number-pad" onChangeText={setOtp} value={otp} placeholder={t('admin.otp')} placeholderTextColor={palette.faint} style={field} />
          <Pressable accessibilityRole="button" accessibilityLabel={t('admin.signIn')} onPress={() => void signIn()} style={button}>
            <Text style={buttonText}>{t('admin.signIn')}</Text>
          </Pressable>
        </View>
      ) : (
        <View style={{ gap: 10 }}>
          <Text style={{ color: palette.muted }}>{session.user.username}</Text>
          {purchasesEnabled ? (panel.data?.products ?? []).map((product) => (
            <View key={product.id} style={{ flexDirection: 'row', gap: 8, flexWrap: 'wrap' }}>
              <Text style={pillText}>{product.title}</Text>
              <Pressable accessibilityRole="button" accessibilityLabel={`${t('admin.schedule')} ${product.title}`} onPress={() => void run(() => adminApi.scheduleAuction(token, product.id))} style={pill}>
                <Text style={pillText}>{t('admin.schedule')}</Text>
              </Pressable>
              <Pressable accessibilityRole="button" accessibilityLabel={`${t('admin.blockListing')} ${product.title}`} onPress={() => void run(() => adminApi.blockProduct(token, product.id))} style={pill}>
                <Text style={pillText}>{t('admin.blockListing')}</Text>
              </Pressable>
            </View>
          )) : null}
          {purchasesEnabled ? (panel.data?.auctions ?? []).map((auction) => (
            <Pressable accessibilityRole="button" accessibilityLabel={`${t('admin.end')} ${auction.title}`} key={auction.id} onPress={() => void run(() => adminApi.endAuction(token, auction.id))} style={pill}>
              <Text style={pillText}>{`${t('admin.end')} ${auction.title}`}</Text>
            </Pressable>
          )) : null}
          {purchasesEnabled ? (panel.data?.sellers ?? []).map((seller) => (
            <Pressable accessibilityRole="button" accessibilityLabel={`${t('admin.verify')} ${seller.username}`} key={seller.user_id} onPress={() => void run(() => adminApi.verifySeller(token, seller.user_id))} style={pill}>
              <Text style={pillText}>{`${t('admin.verify')} ${seller.username}`}</Text>
            </Pressable>
          )) : null}
          {(panel.data?.posts ?? []).map((post) => (
            <Pressable accessibilityRole="button" accessibilityLabel={`${post.hidden ? t('admin.unhide') : t('admin.hide')} ${post.username}`} key={post.id} onPress={() => void run(() => (post.hidden ? adminApi.unhidePost(token, post.id) : adminApi.hidePost(token, post.id)))} style={pill}>
              <Text style={pillText}>{`${post.hidden ? t('admin.unhide') : t('admin.hide')} ${post.username}`}</Text>
            </Pressable>
          ))}
          {(panel.data?.comments ?? []).map((comment) => (
            <Pressable accessibilityRole="button" accessibilityLabel={`${t('admin.remove')} ${comment.username}`} key={comment.id} onPress={() => void run(() => adminApi.removeComment(token, comment.id))} style={pill}>
              <Text style={pillText}>{`${t('admin.remove')} ${comment.username}`}</Text>
            </Pressable>
          ))}
          {(panel.data?.users ?? []).filter((user) => user.role !== 'admin').map((member) => (
            <Pressable accessibilityRole="button" accessibilityLabel={`${t('admin.ban')} ${member.username}`} key={member.id} onPress={() => void run(() => adminApi.banUser(token, member.id))} style={pill}>
              <Text style={pillText}>{`${t('admin.ban')} ${member.username}`}</Text>
            </Pressable>
          ))}
        </View>
      )}
      {panel.isError ? <Text style={{ color: palette.goldSoft }}>{failure(panel.error, false)}</Text> : null}
      {note ? <Text style={{ color: palette.goldSoft }}>{note}</Text> : null}
    </ScrollView>
  );
}

function failure(error: unknown, signingIn: boolean): string {
  if (error instanceof ApiError && error.code === 'AUTH_FAILED') {
    return t('admin.rejected');
  }
  return signingIn ? t('admin.rejected') : t('admin.actionFailed');
}

const field = { color: palette.ivory, borderColor: palette.line, borderWidth: 1, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12 };
const button = { backgroundColor: palette.gold, borderRadius: 999, paddingVertical: 12, alignItems: 'center' as const };
const buttonText = { color: palette.ink, fontWeight: '700' as const };
const pill = { alignSelf: 'flex-start' as const, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.12)' };
const pillText = { color: palette.ivory, fontSize: 13, fontWeight: '700' as const };
