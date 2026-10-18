import AsyncStorage from '@react-native-async-storage/async-storage';
import {createAsyncStoragePersister} from '@tanstack/query-async-storage-persister';
import type {DehydratedState, Query, QueryClient} from '@tanstack/react-query';
import {
  persistQueryClientRestore,
  persistQueryClientSave,
  persistQueryClientSubscribe,
  type PersistedClient,
  type Persister,
} from '@tanstack/react-query-persist-client';
import {roknApiUrl} from '../../constants/apiBaseUrl';
import {captureAccountSessionBoundary} from '../../constants/helpers';
import {isServerTimestampFresh, serverNowMs} from '../../utils/serverClock';
import {settleWithin} from '../../utils/settleWithin';
import {markRestoredSnapshot} from './queryClient';
import {PUBLIC_QUERY_SCOPE} from './queryKeys';
import {
  persistedQueryFamily,
  persistedQueryPolicy,
} from './persistedQueryPolicies';

/**
 * Device snapshot of selected reads, built on TanStack Query's
 * `persistQueryClientRestore` / `persistQueryClientSubscribe` and
 * `createAsyncStoragePersister` (the same pieces `PersistQueryClientProvider`
 * composes; see its source in @tanstack/react-query-persist-client).
 *
 * Rokn differs from the provider in one way: the snapshot is split by owner.
 * - Public queries live under one unscoped key, like the old catalogue cache.
 * - Each account scope has its own key ending in `:<scope>`, so the existing
 *   `clearAccountScopedStorage(scope)` on logout / account replacement removes
 *   it together with every other account-owned record. A write that lands
 *   after its scope stopped being current is skipped (or undone), so a late
 *   throttled save can never recreate a logged-out account's snapshot.
 */
export const QUERY_CACHE_SCHEMA_VERSION = 1;
export const QUERY_CACHE_BUSTER = `${roknApiUrl}#query-cache-v${QUERY_CACHE_SCHEMA_VERSION}`;
export const PUBLIC_QUERY_CACHE_KEY = `@rokn/query-cache/public/v${QUERY_CACHE_SCHEMA_VERSION}:${encodeURIComponent(
  roknApiUrl,
)}`;
export const ACCOUNT_QUERY_CACHE_KEY = `@rokn/query-cache/account/v${QUERY_CACHE_SCHEMA_VERSION}`;
export const accountQueryCacheKey = (scope: string) =>
  `${ACCOUNT_QUERY_CACHE_KEY}:${scope}`;

/** A slow or locked device store may improve first paint, never hold it. */
const RESTORE_BUDGET_MS = 750;

type StorageLike = {
  getItem: (key: string) => Promise<string | null>;
  setItem: (key: string, value: string) => Promise<void>;
  removeItem: (key: string) => Promise<void>;
};

type DehydratedQuery = DehydratedState['queries'][number] & {
  /**
   * `dataUpdatedAt` on the server-corrected clock. The previous caches
   * stamped `savedAt` with `serverNowMs()` and read it back with
   * `isServerTimestampFresh`, so editing the device clock could neither
   * extend a snapshot's life nor make a future-dated one look fresh.
   */
  serverUpdatedAt?: number;
};

/**
 * Errors that prove the last good data is wrong for this key (a retired
 * course, a changed published revision). The previous caches deleted their
 * record on these answers; the snapshot now simply stops carrying the entry.
 */
const snapshotDiscardingErrors = new WeakSet<object>();

export const discardSnapshotWith = <T>(error: T): T => {
  if (error && typeof error === 'object') snapshotDiscardingErrors.add(error);
  return error;
};

const shouldPersistQuery = (query: Query) =>
  query.state.data !== undefined &&
  !(
    query.state.status === 'error' &&
    query.state.error !== null &&
    snapshotDiscardingErrors.has(query.state.error as object)
  ) &&
  persistedQueryPolicy(query.queryKey) !== null;

/** Persist the last good data only: no in-flight or error bookkeeping. */
const settledSnapshot = (query: DehydratedQuery): DehydratedQuery | null => {
  const policy = persistedQueryPolicy(query.queryKey);
  if (!policy) return null;
  const data = policy.dehydrate
    ? policy.dehydrate(query.state.data)
    : query.state.data;
  const rest = {...query};
  delete rest.promise;
  return {
    ...rest,
    serverUpdatedAt: serverNowMs() - (Date.now() - query.state.dataUpdatedAt),
    state: {
      ...query.state,
      data,
      status: 'success',
      fetchStatus: 'idle',
      error: null,
      errorUpdatedAt: 0,
      errorUpdateCount: 0,
      fetchFailureCount: 0,
      fetchFailureReason: null,
      isInvalidated: false,
      fetchMeta: null,
    },
  };
};

const withinFamilyLimits = (queries: DehydratedQuery[]) => {
  const byFamily = new Map<string, DehydratedQuery[]>();
  queries.forEach(query => {
    const family = persistedQueryFamily(query.queryKey);
    byFamily.set(family, [...(byFamily.get(family) ?? []), query]);
  });
  return [...byFamily.values()].flatMap(family => {
    const limit = persistedQueryPolicy(family[0].queryKey)?.maxEntries;
    if (!limit || family.length <= limit) return family;
    return [...family]
      .sort((a, b) => b.state.dataUpdatedAt - a.state.dataUpdatedAt)
      .slice(0, limit);
  });
};

export const serializePersistedClient = (client: PersistedClient) =>
  JSON.stringify({
    ...client,
    clientState: {
      mutations: [],
      queries: withinFamilyLimits(
        client.clientState.queries
          .map(settledSnapshot)
          .filter((query): query is DehydratedQuery => query !== null),
      ),
    },
  } satisfies PersistedClient);

/**
 * Applies each family's age limit and validator. Restored entries are
 * reported so the client can treat them as an earlier process's snapshot.
 */
export const deserializePersistedClient = (value: string): PersistedClient => {
  const parsed = JSON.parse(value) as PersistedClient;
  const queries: DehydratedQuery[] = Array.isArray(parsed?.clientState?.queries)
    ? parsed.clientState.queries
    : [];
  return {
    ...parsed,
    clientState: {
      mutations: [],
      queries: queries.flatMap(query => {
        const policy = Array.isArray(query?.queryKey)
          ? persistedQueryPolicy(query.queryKey)
          : null;
        if (
          !policy ||
          !(Number(query?.state?.dataUpdatedAt) > 0) ||
          !isServerTimestampFresh(query.serverUpdatedAt, policy.maxAgeMs)
        ) {
          return [];
        }
        const data = policy.revive(query.state.data, query.queryKey);
        return data === null ? [] : [{...query, state: {...query.state, data}}];
      }),
    },
  };
};

const scopeIsCurrent = async (scope: string) =>
  (await captureAccountSessionBoundary().catch(() => null))?.scope === scope;

/** Writes only while `scope` still owns the session; undoes a racing write. */
const accountScopedStorage = (
  storage: StorageLike,
  scope: string,
): StorageLike => ({
  getItem: key => storage.getItem(key),
  removeItem: key => storage.removeItem(key),
  setItem: async (key, value) => {
    if (!(await scopeIsCurrent(scope))) return;
    await storage.setItem(key, value);
    // Logout may have cleared this key while the native write was pending.
    if (!(await scopeIsCurrent(scope))) await storage.removeItem(key);
  },
});

export type QueryPersistence = {
  restorePublic: () => Promise<void>;
  activateAccountScope: (scope: string) => Promise<void>;
  /** Settles when `owner`'s snapshot restore settles (within its budget). */
  restored: (owner: string) => Promise<void>;
  dispose: () => void;
};

const controllers = new WeakMap<QueryClient, QueryPersistence>();

export const getQueryPersistence = (client: QueryClient) =>
  controllers.get(client);

export const attachQueryPersistence = (
  client: QueryClient,
  {
    storage = AsyncStorage as unknown as StorageLike,
    throttleTime = 1000,
    restoreBudgetMs = RESTORE_BUDGET_MS,
  }: {
    storage?: StorageLike;
    throttleTime?: number;
    restoreBudgetMs?: number;
  } = {},
): QueryPersistence => {
  const existing = controllers.get(client);
  if (existing) return existing;

  const persister = (key: string, store: StorageLike): Persister =>
    createAsyncStoragePersister({
      storage: store,
      key,
      throttleTime,
      serialize: serializePersistedClient,
      deserialize: deserializePersistedClient,
    });
  const publicPersister = persister(PUBLIC_QUERY_CACHE_KEY, storage);
  const accountPersisters = new Map<string, Persister>();
  const accountPersister = (scope: string) => {
    let value = accountPersisters.get(scope);
    if (!value) {
      value = persister(
        accountQueryCacheKey(scope),
        accountScopedStorage(storage, scope),
      );
      accountPersisters.set(scope, value);
    }
    return value;
  };

  /**
   * The previous caches never delayed the authoritative read: the network
   * request starts at once and the device copy only improves first paint.
   * So restore runs beside the queries, with two guards:
   * - an entry the server already answered (data or error) is not replaced;
   *   a fast failure keeps its error and offline copy;
   * - a device read slower than the budget is ignored once it finally
   *   returns, so a late snapshot cannot replace newer screen state.
   */
  const restore = async (target: Persister) => {
    let restored: PersistedClient | undefined;
    let expired = false;
    const budget = setTimeout(() => {
      expired = true;
    }, restoreBudgetMs);
    const observed: Persister = {
      ...target,
      restoreClient: async () => {
        const value = await target.restoreClient();
        if (!value || expired) return undefined;
        restored = {
          ...value,
          clientState: {
            ...value.clientState,
            queries: value.clientState.queries.filter(dehydrated => {
              const current = client
                .getQueryCache()
                .get(dehydrated.queryHash) as Query | undefined;
              return (
                !current ||
                (current.state.status === 'pending' &&
                  current.state.data === undefined)
              );
            }),
          },
        };
        return restored;
      },
    };
    try {
      await persistQueryClientRestore({
        queryClient: client,
        persister: observed,
        buster: QUERY_CACHE_BUSTER,
        // Each family enforces its own age while deserializing.
        maxAge: Number.POSITIVE_INFINITY,
        hydrateOptions: {
          // Restored reads stay in memory as long as the snapshot would have.
          defaultOptions: {queries: {gcTime: Number.POSITIVE_INFINITY}},
        },
      }).catch(() => undefined);
    } finally {
      clearTimeout(budget);
    }
    restored?.clientState.queries.forEach(dehydrated => {
      const query = client.getQueryCache().get(dehydrated.queryHash) as
        | Query
        | undefined;
      if (query?.state.dataUpdatedAt === dehydrated.state.dataUpdatedAt) {
        markRestoredSnapshot(query);
      }
    });
  };

  let disposed = false;
  let publicUnsubscribe: (() => void) | null = null;
  let accountUnsubscribe: (() => void) | null = null;
  let activeScope: string | null = null;
  let publicRestore: Promise<void> | null = null;
  const accountRestores = new Map<string, Promise<void>>();

  const restorePublicOnce = async () => {
    await settleWithin(restore(publicPersister), undefined, restoreBudgetMs);
    if (disposed || publicUnsubscribe) return;
    const options = {
      queryClient: client,
      persister: publicPersister,
      buster: QUERY_CACHE_BUSTER,
      dehydrateOptions: {
        shouldDehydrateQuery: (query: Query) =>
          query.queryKey[0] === PUBLIC_QUERY_SCOPE && shouldPersistQuery(query),
      },
    };
    publicUnsubscribe = persistQueryClientSubscribe(options);
    // Reads that settled while restoring are saved now, not on next change.
    void persistQueryClientSave(options).catch(() => undefined);
  };

  const controller: QueryPersistence = {
    // Once per process: the first caller (a failing read or the provider)
    // starts it, every later caller joins it.
    restorePublic: () => {
      publicRestore ??= restorePublicOnce();
      return publicRestore;
    },
    restored: owner =>
      owner === PUBLIC_QUERY_SCOPE
        ? controller.restorePublic()
        : accountRestores.get(owner) ?? Promise.resolve(),
    activateAccountScope: async scope => {
      if (disposed) return;
      accountUnsubscribe?.();
      accountUnsubscribe = null;
      activeScope = scope;
      const target = accountPersister(scope);
      const restoring = settleWithin(
        restore(target),
        undefined,
        restoreBudgetMs,
      );
      accountRestores.set(scope, restoring);
      await restoring;
      if (disposed || activeScope !== scope) return;
      const options = {
        queryClient: client,
        persister: target,
        buster: QUERY_CACHE_BUSTER,
        dehydrateOptions: {
          shouldDehydrateQuery: (query: Query) =>
            query.queryKey[0] === scope && shouldPersistQuery(query),
        },
      };
      accountUnsubscribe = persistQueryClientSubscribe(options);
      void persistQueryClientSave(options).catch(() => undefined);
    },
    dispose: () => {
      disposed = true;
      publicUnsubscribe?.();
      accountUnsubscribe?.();
      publicUnsubscribe = null;
      accountUnsubscribe = null;
      controllers.delete(client);
    },
  };
  controllers.set(client, controller);
  return controller;
};

/**
 * One rule for every persisted read: a first read that fails while its
 * owner's device snapshot is still being read waits for that restore (same
 * 750 ms budget) before reporting the failure. Offline at cold start the
 * request fails fastest, and the saved copy must then appear with the
 * screen's existing offline note; the error alone shows only when there is
 * no restorable copy. Reads outside the persisted families fail at once.
 */
export const restoreBeforeFailing =
  <TContext extends {client: QueryClient; queryKey: readonly unknown[]}, T>(
    queryFn: (context: TContext) => Promise<T>,
  ) =>
  async (context: TContext): Promise<T> => {
    try {
      return await queryFn(context);
    } catch (error) {
      if (persistedQueryPolicy(context.queryKey) !== null) {
        await getQueryPersistence(context.client)
          ?.restored(String(context.queryKey[0]))
          .catch(() => undefined);
      }
      throw error;
    }
  };
