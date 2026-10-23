import { useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { loginAccount, registerAccount } from '@/api/social-commerce';
import { t } from '@/lib/i18n';
import { useSession } from '@/stores/session';
import { palette } from '@/theme/tokens';

export function AuthScreen() {
  const signIn = useSession((state) => state.signIn);
  const [mode, setMode] = useState<'sign-in' | 'sign-up'>('sign-in');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [username, setUsername] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const session = mode === 'sign-up'
        ? await registerAccount({ email: email.trim(), password, username: username.trim(), display_name: displayName.trim() })
        : await loginAccount(email.trim(), password);
      await signIn(session.token);
    } catch (caught) {
      setError(caught instanceof ApiError ? t('auth.failed') : t('auth.network'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={{ flex: 1, backgroundColor: palette.ink, padding: 24, paddingTop: 80, gap: 12 }}>
      <Text style={{ color: palette.ivory, fontSize: 28, fontWeight: '700' }}>{mode === 'sign-up' ? t('auth.signUp') : t('auth.signIn')}</Text>
      <TextInput accessibilityLabel={t('auth.email')} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" onChangeText={setEmail} placeholder={t('auth.email')} placeholderTextColor={palette.muted} style={field} value={email} />
      <TextInput accessibilityLabel={t('auth.password')} onChangeText={setPassword} placeholder={t('auth.password')} placeholderTextColor={palette.muted} secureTextEntry style={field} value={password} />
      {mode === 'sign-up' ? (
        <>
          <TextInput accessibilityLabel={t('auth.username')} autoCapitalize="none" onChangeText={setUsername} placeholder={t('auth.username')} placeholderTextColor={palette.muted} style={field} value={username} />
          <TextInput accessibilityLabel={t('auth.displayName')} onChangeText={setDisplayName} placeholder={t('auth.displayName')} placeholderTextColor={palette.muted} style={field} value={displayName} />
        </>
      ) : null}
      {error ? <Text style={{ color: palette.goldSoft }}>{error}</Text> : null}
      <Pressable accessibilityRole="button" disabled={busy} onPress={() => void submit()} style={button}>
        <Text style={label}>{busy ? t('auth.working') : mode === 'sign-up' ? t('auth.signUp') : t('auth.signIn')}</Text>
      </Pressable>
      <Pressable accessibilityRole="button" onPress={() => setMode(mode === 'sign-up' ? 'sign-in' : 'sign-up')}>
        <Text style={{ color: palette.ivory }}>{mode === 'sign-up' ? t('auth.haveAccount') : t('auth.needAccount')}</Text>
      </Pressable>
    </View>
  );
}

const field = { color: palette.ivory, borderWidth: 1, borderColor: 'rgba(255,255,255,0.16)', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 10 };
const button = { alignSelf: 'flex-start' as const, paddingHorizontal: 14, paddingVertical: 10, borderRadius: 999, backgroundColor: palette.gold };
const label = { color: palette.ink, fontWeight: '700' as const };
