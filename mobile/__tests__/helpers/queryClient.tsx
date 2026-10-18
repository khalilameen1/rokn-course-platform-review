/* eslint-env jest */
import {
  notifyManager,
  QueryClient,
  QueryClientProvider,
} from '@tanstack/react-query';
import React, {type ReactNode} from 'react';
import {roknQueryClientConfig} from '../../src/services/query/queryClient';
import {attachQueryPersistence} from '../../src/services/query/queryPersistence';
import {RoknQueryProvider} from '../../src/services/query/RoknQueryProvider';

// TanStack batches observer notifications with setTimeout(0). Many suites use
// Jest fake timers; deliver notifications on the (unfaked) promise queue so
// `act(async () => …)` flushes them like any other async state update.
notifyManager.setScheduler(callback => {
  void Promise.resolve().then(callback);
});

/**
 * TanStack Query testing guide: a fresh client per test, retries off, and an
 * infinite gcTime so a cache entry never disappears between assertions.
 */
export const createTestQueryClient = () => {
  const config = roknQueryClientConfig();
  return new QueryClient({
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
};

export const queryWrapper = (client: QueryClient = createTestQueryClient()) => {
  const Wrapper = ({children}: {children: ReactNode}) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return Wrapper;
};

/** Wrap a rendered element in a QueryClientProvider. */
export const withQueryClient = (
  element: React.ReactElement,
  client: QueryClient = createTestQueryClient(),
) => <QueryClientProvider client={client}>{element}</QueryClientProvider>;

/**
 * The app's real root provider (persistence, account scope, unavailable
 * course channel) around a test client. Persistence writes immediately.
 */
export const withAppQueryProvider = (
  element: React.ReactElement,
  client: QueryClient,
  identityKey = 'guest',
) => {
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
