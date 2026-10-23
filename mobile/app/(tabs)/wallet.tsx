import { Redirect } from 'expo-router';

/** The fixture wallet does not move money. Rewards stay on the signed-in profile. */
export default function WalletRoute() {
  return <Redirect href="/profile" />;
}
