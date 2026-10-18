import {queryOptions} from '@tanstack/react-query';
import {getPortfolioProfile, getProfile} from '../roknApi';
import {captureScopedBoundary} from './accountQueryScope';
import {accountQueryKeys} from './queryKeys';

/**
 * The أنا tab's two independent reads. Identity (name, avatar, headline) and
 * the reviewed sharing status settle on their own: neither delays the other
 * or clears its error, and only the portfolio response can authorize
 * sharing. Both read on mount and on every focus; neither is kept on device.
 */
export const accountProfileQueryOptions = (scope: string | null) =>
  queryOptions({
    queryKey: accountQueryKeys(scope).accountProfile(),
    queryFn: async () => getProfile(await captureScopedBoundary(scope!)),
    staleTime: 0,
    refetchOnWindowFocus: false,
  });

export const portfolioProfileQueryOptions = (scope: string | null) =>
  queryOptions({
    queryKey: accountQueryKeys(scope).portfolioProfile(),
    queryFn: async () =>
      getPortfolioProfile(await captureScopedBoundary(scope!)),
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
