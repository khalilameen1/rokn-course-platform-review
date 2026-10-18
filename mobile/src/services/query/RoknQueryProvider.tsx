import {QueryClientProvider, type QueryClient} from '@tanstack/react-query';
import React, {useEffect, useState, type ReactNode} from 'react';
import {subscribeToUnavailableCourses} from '../api/courseAvailability';
import {requestAccountQueryScope} from './accountQueryScope';
import {removeUnavailableCourse} from './catalogueQueries';
import {queryClient as appQueryClient} from './queryClient';
import {attachQueryPersistence} from './queryPersistence';
import {installReactNativeQueryManagers} from './reactNativeQueryManagers';

type Props = {
  children: ReactNode;
  /** Current session owner (`sessionIdentityKey`). */
  identityKey: string;
  client?: QueryClient;
};

/**
 * App root for TanStack Query, composed from the same pieces as
 * `PersistQueryClientProvider` (restore, then subscribe). Unlike that
 * provider it does not hold queries while restoring: the authoritative read
 * always starts first, as Rokn's previous caches required. The account
 * snapshot is restored per scope when `useAccountQueryScope` resolves an
 * owner, and identity changes are resolved here so the previous account's
 * queries are removed even when no screen asks.
 */
export const RoknQueryProvider = ({
  children,
  identityKey,
  client = appQueryClient,
}: Props) => {
  // Attach during render: child effects run before this component's effects
  // and may already ask for their account scope.
  const [persistence] = useState(() => attachQueryPersistence(client));

  useEffect(() => installReactNativeQueryManagers(), []);

  // A details read answered 404/410: the course leaves every catalogue list
  // and the device snapshot, whichever screen is mounted.
  useEffect(
    () =>
      subscribeToUnavailableCourses(courseId => {
        void removeUnavailableCourse(client, courseId);
      }),
    [client],
  );

  useEffect(() => {
    void persistence.restorePublic();
  }, [persistence]);

  useEffect(
    () => () => {
      persistence.dispose();
    },
    [persistence],
  );

  useEffect(() => {
    requestAccountQueryScope(client, identityKey);
  }, [client, identityKey]);

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
};
