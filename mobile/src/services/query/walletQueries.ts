import {queryOptions} from '@tanstack/react-query';
import {assertAccountSessionBoundary} from '../../constants/helpers';
import {getCoinTasks, getWallet} from '../roknApi';
import {captureScopedBoundary} from './accountQueryScope';
import {accountQueryKeys} from './queryKeys';
import {restoreBeforeFailing} from './queryPersistence';

/**
 * The account wallet and reward tasks. Rewards and Course details observe the
 * same wallet entry, so a settlement refreshes both screens at once. Screens
 * own when to refresh (focus, return to the app, settlements); the device
 * snapshot has no age limit, as before, and is replaced by every read.
 */
export const walletQueryOptions = (scope: string | null) =>
  queryOptions({
    queryKey: accountQueryKeys(scope).wallet(),
    queryFn: restoreBeforeFailing(async () => {
      const boundary = await captureScopedBoundary(scope!);
      const wallet = await getWallet();
      assertAccountSessionBoundary(boundary);
      return wallet;
    }),
    staleTime: 0,
    gcTime: Number.POSITIVE_INFINITY,
    refetchOnWindowFocus: false,
    refetchOnMount: false,
  });

export const coinTasksQueryOptions = (scope: string | null) =>
  queryOptions({
    queryKey: accountQueryKeys(scope).coinTasks(),
    queryFn: restoreBeforeFailing(async () => {
      const boundary = await captureScopedBoundary(scope!);
      const tasks = await getCoinTasks();
      assertAccountSessionBoundary(boundary);
      return tasks;
    }),
    staleTime: 0,
    gcTime: Number.POSITIVE_INFINITY,
    refetchOnWindowFocus: false,
    refetchOnMount: false,
  });
