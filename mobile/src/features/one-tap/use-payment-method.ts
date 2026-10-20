import { useQuery } from '@tanstack/react-query';

import { ApiError } from '@/api/client';
import { queryKeys } from '@/api/keys';
import { fetchDefaultPaymentMethod } from '@/api/social-commerce';
import { rememberPublishableKey } from '@/features/payments/publishable-key';

/**
 * What 1-Tap would charge ("Visa ···· 4242"). Shared by every buy card through one cached query; the
 * 1-Tap engine updates it after a card is saved or a tap reports that none is on file.
 */
export function useDefaultPaymentMethod(enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.oneTap.paymentMethod,
    queryFn: async ({ signal }) => {
      const method = await fetchDefaultPaymentMethod(signal);
      rememberPublishableKey(method.publishable_key);
      return method;
    },
    enabled,
    staleTime: 5 * 60_000,
    retry: (failureCount, error) => !(error instanceof ApiError && error.status < 500) && failureCount < 2,
  });
}
