import {useQueryClient, type QueryClient} from '@tanstack/react-query';
import {useCallback, useEffect, useSyncExternalStore} from 'react';
import {
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {getQueryPersistence} from './queryPersistence';
import {isAccountScopedQueryKey} from './queryKeys';

type ScopeSnapshot = {
  identityKey: string | null;
  scope: string | null;
  failed: boolean;
};

type ScopeStore = {
  subscribe: (listener: () => void) => () => void;
  getSnapshot: () => ScopeSnapshot;
  request: (identityKey: string, force?: boolean) => void;
};

const stores = new WeakMap<QueryClient, ScopeStore>();

/**
 * Removes every query owned by another account scope. Removal cancels their
 * in-flight requests too, so a late response cannot land in the cache.
 */
export const removeOtherAccountQueries = (
  client: QueryClient,
  currentScope: string | null,
) =>
  client.removeQueries({
    predicate: query =>
      isAccountScopedQueryKey(query.queryKey) &&
      query.queryKey[0] !== currentScope,
  });

const createScopeStore = (client: QueryClient): ScopeStore => {
  let snapshot: ScopeSnapshot = {identityKey: null, scope: null, failed: false};
  let requestId = 0;
  const listeners = new Set<() => void>();
  const publish = (next: ScopeSnapshot) => {
    snapshot = next;
    listeners.forEach(listener => listener());
  };

  const resolve = async (identityKey: string, request: number) => {
    let boundary: AccountSessionBoundary | null = null;
    for (let attempt = 0; attempt < 3 && !boundary; attempt += 1) {
      boundary = await captureAccountSessionBoundary().catch(() => null);
      if (request !== requestId) return;
    }
    if (!boundary) {
      publish({identityKey, scope: null, failed: true});
      return;
    }
    removeOtherAccountQueries(client, boundary.scope);
    // The owner's device snapshot restores beside its first reads; it never
    // delays them (see queryPersistence `restore`).
    void getQueryPersistence(client)?.activateAccountScope(boundary.scope);
    publish({identityKey, scope: boundary.scope, failed: false});
  };

  return {
    subscribe: listener => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    getSnapshot: () => snapshot,
    request: (identityKey, force = false) => {
      if (
        !force &&
        snapshot.identityKey === identityKey &&
        (snapshot.scope !== null || !snapshot.failed)
      ) {
        return;
      }
      const request = ++requestId;
      publish({identityKey, scope: null, failed: false});
      void resolve(identityKey, request);
    },
  };
};

export const accountScopeStore = (client: QueryClient) => {
  let store = stores.get(client);
  if (!store) {
    store = createScopeStore(client);
    stores.set(client, store);
  }
  return store;
};

/** Root-level hint: resolve the signed-in owner even before a screen asks. */
export const requestAccountQueryScope = (
  client: QueryClient,
  identityKey: string,
) => accountScopeStore(client).request(identityKey);

/**
 * The account storage scope that owns user queries for `identityKey`.
 * `scope` is null while it resolves or after an identity change, so a screen
 * rendered for a new account never reads the previous account's entries.
 */
export const useAccountQueryScope = (identityKey: string) => {
  const client = useQueryClient();
  const store = accountScopeStore(client);
  const snapshot = useSyncExternalStore(store.subscribe, store.getSnapshot);
  useEffect(() => store.request(identityKey), [identityKey, store]);
  const retry = useCallback(
    () => store.request(identityKey, true),
    [identityKey, store],
  );
  const owned = snapshot.identityKey === identityKey;
  return {
    scope: owned ? snapshot.scope : null,
    failed: owned && snapshot.failed,
    retry,
  };
};

/**
 * Captures the session owner for one fetch and refuses to run for a scope
 * that is no longer current (helpers.ts boundary contract).
 */
export const captureScopedBoundary = async (scope: string) => {
  const boundary = await captureAccountSessionBoundary();
  if (boundary.scope !== scope) {
    throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }
  return boundary;
};
