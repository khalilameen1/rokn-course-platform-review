import {useFocusEffect} from '@react-navigation/native';
import {useQuery, useQueryClient} from '@tanstack/react-query';
import {useCallback, useMemo, useRef, useState} from 'react';

import {assertAccountSessionBoundary} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {friendlyNetworkMessage} from '../../services/networkExperience';
import {
  getLearningDashboard,
  hasSession,
  type LearningDashboard,
} from '../../services/roknApi';
import {
  captureScopedBoundary,
  useAccountQueryScope,
} from '../../services/query/accountQueryScope';
import {isRestoredSnapshot} from '../../services/query/queryClient';
import {accountQueryKeys} from '../../services/query/queryKeys';
import {restoreBeforeFailing} from '../../services/query/queryPersistence';
import {learnerCopy} from '../../constants/learnerCopy';

const EMPTY_PANELS = {
  paths: [],
  badges: [],
  activityDays: [],
  currentStreakDays: 0,
} satisfies Omit<LearningDashboard, 'courses'>;

const isAccountChange = (error: unknown) =>
  error instanceof Error && error.message === 'ACCOUNT_CHANGED_DURING_REQUEST';

/**
 * ركني's learning dashboard as one account-scoped query.
 *
 * - The authoritative network read always starts; the device snapshot (6 h)
 *   only improves first paint while it runs.
 * - Courses are delivered into the query as soon as they arrive
 *   (`onCoursesReady`), before profile, streak and paths settle.
 * - Every focus of ركني and every return to the app refreshes (TanStack
 *   React Native guide, "Refresh on Screen focus"); a refresh joins one
 *   already running instead of starting another.
 */
export const useMyCornerData = (identityKey: string) => {
  const client = useQueryClient();
  const appIsActive = useAppForegroundState();
  const {scope, failed: scopeFailed} = useAccountQueryScope(identityKey);
  const keys = useMemo(() => accountQueryKeys(scope), [scope]);
  const dashboardKey = useMemo(() => keys.learningDashboard(), [keys]);
  /** Serial of the latest read; its courses are the fresh ownership facts. */
  const readSerialRef = useRef(0);
  const coursesReadSerialRef = useRef(0);
  const [coursesReadSerial, setCoursesReadSerial] = useState(0);

  const session = useQuery({
    queryKey: keys.session(),
    queryFn: () => hasSession(),
    enabled: scope !== null,
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
  const signedIn = session.data === true;

  const dashboard = useQuery({
    queryKey: dashboardKey,
    queryFn: restoreBeforeFailing(async () => {
      // Synchronous with the fetch start, before React sees `isFetching`.
      const read = ++readSerialRef.current;
      const boundary = await captureScopedBoundary(scope!);
      return getLearningDashboard({
        previous: client.getQueryData<LearningDashboard>(dashboardKey) ?? null,
        onCoursesReady: courses => {
          try {
            assertAccountSessionBoundary(boundary);
          } catch {
            return;
          }
          client.setQueryData<LearningDashboard>(dashboardKey, current => ({
            ...EMPTY_PANELS,
            ...current,
            courses,
            partialError: undefined,
          }));
          if (readSerialRef.current === read) {
            coursesReadSerialRef.current = read;
            setCoursesReadSerial(read);
          }
        },
      });
    }),
    // A manual query (TanStack "Disabling/Pausing Queries"): ركني reads on
    // every focus, foreground return and retry (below), never on its own.
    enabled: false,
    staleTime: 0,
  });

  const refetchSession = session.refetch;
  const refetchDashboard = dashboard.refetch;
  // A retry button rendered before blur or unmount must not read afterwards.
  const focusedRef = useRef(false);
  const reload = useCallback(() => {
    if (scope === null || !focusedRef.current) return;
    void refetchSession({cancelRefetch: false}).then(result => {
      if (result.data !== true) return;
      // Focus, foreground and retry share one read. Once that read has
      // delivered the courses, an explicit refresh replaces it instead of
      // waiting for its slower secondary panels.
      const coursesDelivered =
        coursesReadSerialRef.current === readSerialRef.current &&
        client.isFetching({queryKey: dashboardKey, exact: true}) > 0;
      void refetchDashboard({cancelRefetch: coursesDelivered});
    });
  }, [client, dashboardKey, refetchDashboard, refetchSession, scope]);

  useFocusEffect(
    useCallback(() => {
      focusedRef.current = true;
      if (appIsActive) reload();
      return () => {
        focusedRef.current = false;
      };
    }, [appIsActive, reload]),
  );

  const dashboardQuery = client
    .getQueryCache()
    .find({queryKey: dashboardKey, exact: true});
  const data = signedIn ? dashboard.data ?? null : null;
  const error = dashboard.isError ? dashboard.error : null;
  const fetching = dashboard.isFetching;
  const coursesFreshThisRead =
    coursesReadSerial > 0 && coursesReadSerial === readSerialRef.current;
  const learningOwnershipFresh = Boolean(
    data &&
      !isRestoredSnapshot(dashboardQuery) &&
      (fetching ? coursesFreshThisRead : !error || coursesFreshThisRead),
  );

  let dashboardError = '';
  if (scopeFailed) {
    dashboardError = `تعذّر تجهيز بيانات ركني\n${learnerCopy.retry}`;
  } else if (session.isError && !session.isFetching) {
    dashboardError = `${friendlyNetworkMessage(
      session.error,
      'كورساتك',
    )}\nتقدمك محفوظ`;
  } else if (signedIn && !fetching && error) {
    dashboardError = isAccountChange(error)
      ? 'تغيّر الحساب\nافتح ركني من جديد'
      : coursesFreshThisRead
      ? 'تعذّر تحديث بعض بيانات تقدمك\nكورساتك جاهزة للاستكمال'
      : data
      ? 'نعرض آخر تقدم محفوظ\nسنحدّثه عند عودة الاتصال'
      : `${friendlyNetworkMessage(error, 'كورساتك')}\nتقدمك محفوظ`;
  } else if (signedIn && !fetching && data?.partialError) {
    dashboardError = data.partialError;
  }

  return {
    dashboard: data,
    dashboardError,
    dashboardLoading:
      signedIn && fetching && !(coursesFreshThisRead && data !== null),
    learningOwnershipFresh,
    owned: scope !== null || scopeFailed,
    reload,
    serverSession: scope === null ? null : session.data ?? null,
  };
};
