import { useRouter } from 'expo-router';
import { useEffect } from 'react';

/** The file-based index stays so existing links resolve, then the app opens on Compare. */
export default function IndexRedirect() {
  const router = useRouter();

  useEffect(() => {
    router.replace('/compare');
  }, [router]);

  return null;
}
