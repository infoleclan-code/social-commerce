import { Redirect } from 'expo-router';

/** Demo drop fixtures are not a purchase path. Group buys live on reels. */
export default function DropsRoute() {
  return <Redirect href="/reels" />;
}
