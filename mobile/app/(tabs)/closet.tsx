import { Redirect } from 'expo-router';

/** The fixture closet is not an order history. Purchases live on the profile and the orders screen. */
export default function ClosetRoute() {
  return <Redirect href="/profile" />;
}
