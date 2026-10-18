import {useCallback, useEffect, useRef, useState} from 'react';
import {useFocusEffect} from '@react-navigation/native';
import {
  useQuery,
  useQueryClient,
  type UseQueryResult,
} from '@tanstack/react-query';
import {AppState} from 'react-native';
import {hasSession, type CoinTask} from '../../services/roknApi';
import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {subscribeWalletSettlements} from '../../services/walletSettlement';
import {useAccountQueryScope} from '../../services/query/accountQueryScope';
import {accountQueryKeys} from '../../services/query/queryKeys';
import {
  coinTasksQueryOptions,
  walletQueryOptions,
} from '../../services/query/walletQueries';

export type WalletAreaStatus = 'idle' | 'loading' | 'ready' | 'error';

const NO_TASKS: CoinTask[] = [];

/**
 * Per-area status, as before: a known value stays `ready` while it refreshes,
 * a failed read is `error` (even over the saved copy, which stays visible),
 * and nothing is read without a session.
 */
const areaStatus = (
  signedIn: boolean,
  result: UseQueryResult<unknown>,
): WalletAreaStatus => {
  if (!signedIn) return 'idle';
  if (result.isFetching) return result.data !== undefined ? 'ready' : 'loading';
  if (result.isError) return 'error';
  return result.data !== undefined ? 'ready' : 'loading';
};

/**
 * Rewards wallet and tasks as two account-scoped queries.
 *
 * A credit can land on the server while a read is in flight (an external
 * task, a daily reward, a purchase). Returning to the screen or the app, and
 * every wallet settlement, therefore asks for a snapshot that starts *after*
 * that moment: `invalidateQueries` cancels the older read and starts a new
 * one, so its late response can never overwrite the newer balance.
 */
export const useWalletData = (identityKey: string) => {
  const client = useQueryClient();
  const {scope} = useAccountQueryScope(identityKey);
  const keys = accountQueryKeys(scope);
  const [manualRefreshing, setManualRefreshing] = useState(false);
  const mountedRef = useRef(false);
  const manualRefreshRef = useRef<symbol | null>(null);

  const session = useQuery({
    queryKey: keys.session(),
    queryFn: () => hasSession(),
    enabled: scope !== null,
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
  const signedIn = session.data === true;
  // Manual queries (TanStack "Disabling/Pausing Queries"): Rewards reads on
  // focus, foreground return, settlements and pull to refresh only.
  const wallet = useQuery({...walletQueryOptions(scope), enabled: false});
  const tasks = useQuery({...coinTasksQueryOptions(scope), enabled: false});

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const ownsBoundary = useCallback((boundary: AccountSessionBoundary) => {
    if (!mountedRef.current) return false;
    try {
      assertAccountSessionBoundary(boundary);
      return true;
    } catch {
      return false;
    }
  }, []);

  const updateTask = useCallback(
    (taskId: string, update: Partial<Pick<CoinTask, 'status' | 'url'>>) => {
      client.setQueryData<CoinTask[]>(
        accountQueryKeys(scope).coinTasks(),
        current =>
          current?.map(task =>
            task.id === taskId ? {...task, ...update} : task,
          ),
      );
    },
    [client, scope],
  );

  const refetchSession = session.refetch;
  const refetchWallet = wallet.refetch;
  const refetchTasks = tasks.refetch;
  const read = useCallback(
    async (cancelRefetch: boolean) => {
      if (!mountedRef.current || scope === null) return;
      const sessionResult = await refetchSession({cancelRefetch: false});
      if (!mountedRef.current || sessionResult.data !== true) return;
      const ownedKeys = accountQueryKeys(scope);
      await Promise.all(
        [
          [ownedKeys.wallet(), refetchWallet],
          [ownedKeys.coinTasks(), refetchTasks],
        ].map(async ([queryKey, refetch]) => {
          const state = client.getQueryState(queryKey as readonly unknown[]);
          const firstReadInFlight =
            state?.fetchStatus === 'fetching' && state.data === undefined;
          // TanStack only restarts a read that already has data; a first
          // read still in flight is followed by one more, as before.
          if (cancelRefetch && firstReadInFlight) {
            await (refetch as typeof refetchWallet)({cancelRefetch: false});
            if (!mountedRef.current) return;
          }
          await (refetch as typeof refetchWallet)({cancelRefetch});
        }),
      );
    },
    [client, refetchSession, refetchTasks, refetchWallet, scope],
  );

  /** Joins a read already in flight (initial focus, pull to refresh). */
  const refresh = useCallback(() => read(false), [read]);
  /** A snapshot that starts after now (credit may have landed meanwhile). */
  const refreshAfterCurrent = useCallback(() => read(true), [read]);

  useEffect(
    () =>
      subscribeWalletSettlements(boundary => {
        if (ownsBoundary(boundary)) void refreshAfterCurrent();
      }),
    [ownsBoundary, refreshAfterCurrent],
  );

  const refreshManually = useCallback(async () => {
    const operation = Symbol('wallet-manual-refresh');
    manualRefreshRef.current = operation;
    setManualRefreshing(true);
    try {
      await refreshAfterCurrent();
    } finally {
      if (manualRefreshRef.current === operation) {
        manualRefreshRef.current = null;
        if (mountedRef.current) setManualRefreshing(false);
      }
    }
  }, [refreshAfterCurrent]);

  useEffect(() => {
    manualRefreshRef.current = null;
    setManualRefreshing(false);
  }, [identityKey]);

  // TanStack React Native guide, "Refresh on Screen focus": the first focus
  // joins the mount read; every later focus and every return to the app
  // while focused asks for a fresh snapshot.
  const focusedBeforeRef = useRef(false);
  const refreshRef = useRef(refresh);
  const refreshAfterCurrentRef = useRef(refreshAfterCurrent);
  refreshRef.current = refresh;
  refreshAfterCurrentRef.current = refreshAfterCurrent;
  useFocusEffect(
    useCallback(() => {
      if (focusedBeforeRef.current) void refreshAfterCurrentRef.current();
      else void refreshRef.current();
      focusedBeforeRef.current = true;
      let previousState = AppState.currentState;
      const subscription = AppState.addEventListener('change', state => {
        const returnedToForeground =
          state === 'active' && previousState !== 'active';
        previousState = state;
        if (returnedToForeground) void refreshAfterCurrentRef.current();
      });
      return () => subscription.remove();
    }, []),
  );
  // A newly resolved owner reads at once, even before another focus.
  useEffect(() => {
    if (scope !== null) void refreshRef.current();
  }, [scope]);

  return {
    identityKey,
    manualRefreshing,
    ownerReady: scope !== null,
    ownsBoundary,
    refresh,
    refreshAfterCurrent,
    refreshManually,
    serverSession: scope === null ? null : session.data ?? null,
    tasks: signedIn ? tasks.data ?? NO_TASKS : NO_TASKS,
    tasksStatus: areaStatus(signedIn, tasks),
    updateTask,
    wallet: signedIn ? wallet.data ?? null : null,
    walletStatus: areaStatus(signedIn, wallet),
  };
};

export type WalletData = ReturnType<typeof useWalletData>;
