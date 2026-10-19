/* eslint-env jest */
import {
  notifyManager,
  QueryClient,
  QueryClientProvider,
} from '@tanstack/react-query';
import React, {type ReactNode} from 'react';
import {roknQueryClientConfig} from '../../src/services/query/queryClient';
import {
  attachQueryPersistence,
  getQueryPersistence,
} from '../../src/services/query/queryPersistence';
import {RoknQueryProvider} from '../../src/services/query/RoknQueryProvider';

// TanStack batches observer notifications with setTimeout(0). Many suites use
// Jest fake timers; deliver notifications on the (unfaked) promise queue so
// `act(async () => …)` flushes them like any other async state update.
notifyManager.setScheduler(callback => {
  void Promise.resolve().then(callback);
});

const testClients = new Set<QueryClient>();

// Explicit query gcTime overrides the client default. Unmounted catalogue
// observers therefore leave two-hour timers unless their owning cache is
// cleared. Dispose persistence first so teardown cannot create new writes.
// https://tanstack.com/query/latest/docs/framework/react/guides/testing
afterAll(() => {
  for (const client of testClients) {
    getQueryPersistence(client)?.dispose();
    client.clear();
  }
  testClients.clear();
});

/**
 * TanStack Query testing guide: a fresh client per test, retries off, and an
 * infinite gcTime so a cache entry never disappears between assertions.
 */
export const createTestQueryClient = () => {
  const config = roknQueryClientConfig();
  const client = new QueryClient({
    ...config,
    defaultOptions: {
      ...config.defaultOptions,
      queries: {
        ...config.defaultOptions?.queries,
        retry: false,
        gcTime: Number.POSITIVE_INFINITY,
      },
    },
  });
  testClients.add(client);
  return client;
};

export const queryWrapper = (client: QueryClient = createTestQueryClient()) => {
  testClients.add(client);
  const Wrapper = ({children}: {children: ReactNode}) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return Wrapper;
};

/** Wrap a rendered element in a QueryClientProvider. */
export const withQueryClient = (
  element: React.ReactElement,
  client: QueryClient = createTestQueryClient(),
) => {
  // Explicit clients (including the application singleton) need the same
  // persistence/cache teardown as clients created by this test helper.
  testClients.add(client);
  return <QueryClientProvider client={client}>{element}</QueryClientProvider>;
};

/**
 * The app's real root provider (persistence, account scope, unavailable
 * course channel) around a test client. Persistence writes immediately.
 */
export const withAppQueryProvider = (
  element: React.ReactElement,
  client: QueryClient,
  identityKey = 'guest',
) => {
  testClients.add(client);
  attachQueryPersistence(client, {throttleTime: 0});
  return (
    <RoknQueryProvider client={client} identityKey={identityKey}>
      {element}
    </RoknQueryProvider>
  );
};

/** Lets throttled persister writes and AsyncStorage promises settle. */
export const flushQueryPersistence = () =>
  new Promise<void>(resolve => setTimeout(resolve, 25));
