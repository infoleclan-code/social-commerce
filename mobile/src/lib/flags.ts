/** In-app checkout stays off unless a release build opts in. */
export const purchasesEnabled = process.env.EXPO_PUBLIC_COMMERCE_PURCHASES === 'true';
