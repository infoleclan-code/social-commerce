import { useLocalSearchParams } from 'expo-router';

import { ProfileScreen } from '@/features/profile/profile-screen';

export default function UserProfileRoute() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const userId = typeof id === 'string' ? id : '';
  return <ProfileScreen userId={userId} />;
}
