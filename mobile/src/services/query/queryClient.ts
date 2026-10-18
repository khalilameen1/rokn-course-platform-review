import {
  QueryClient,
  type Query,
  type QueryClientConfig,
} from '@tanstack/react-query';

/**
 * Data hydrated from the device snapshot, keyed by the exact `dataUpdatedAt`
 * it was restored with. A later successful fetch changes `dataUpdatedAt`, so
 * the mark expires by itself; no screen has to clear it.
 */
const restoredSnapshots = new WeakMap<Query, number>();

export const markRestoredSnapshot = (query: Query) => {
  if (query.state.dataUpdatedAt > 0) {
    restoredSnapshots.set(query, query.state.dataUpdatedAt);
  }
};

/** True while a query still shows what an earlier app process saved. */
export const isRestoredSnapshot = (query: Query | undefined) =>
  Boolean(
    query &&
      query.state.dataUpdatedAt > 0 &&
      restoredSnapshots.get(query) === query.state.dataUpdatedAt,
  );

/**
 * Rokn defaults on top of TanStack Query v5 "Important Defaults":
 * - `retry: false`: the shared HTTP client already replays transient read
 *   failures inside its own deadline (`roknNetworkRetryDeadlineAt`); a second
 *   retry layer would hold the visible error/offline copy for much longer.
 * - `networkMode: 'offlineFirst'`: the request is always attempted, so an
 *   offline learner gets the existing offline copy over the device snapshot
 *   instead of a query silently paused by `onlineManager`.
 * - A device snapshot only improves first paint; it never counts as fresh for
 *   a new process, so a mounted screen always asks the server once.
 */
export const roknQueryClientConfig = (): QueryClientConfig => ({
  defaultOptions: {
    queries: {
      retry: false,
      networkMode: 'offlineFirst',
      refetchOnMount: query => (isRestoredSnapshot(query) ? 'always' : true),
    },
    mutations: {
      retry: false,
      networkMode: 'offlineFirst',
    },
  },
});

export const createRoknQueryClient = () =>
  new QueryClient(roknQueryClientConfig());

/** The app's single client. Screens reach it through `useQueryClient()`. */
export const queryClient = createRoknQueryClient();
