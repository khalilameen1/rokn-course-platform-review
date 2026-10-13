import {useFocusEffect} from '@react-navigation/native';
import {useCallback, useEffect, useRef, useState} from 'react';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {friendlyNetworkMessage} from '../../services/networkExperience';
import {
  getCachedLearningDashboard,
  getLearningDashboard,
  hasSession,
  type LearningDashboard,
} from '../../services/roknApi';
import {settleWithin} from '../../utils/settleWithin';

export const useMyCornerData = (identityKey: string) => {
  const appIsActive = useAppForegroundState();
  const ownerRef = useRef(identityKey);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [dashboard, setDashboard] = useState<LearningDashboard | null>(null);
  const [learningOwnershipFresh, setLearningOwnershipFresh] = useState(false);
  const [dashboardError, setDashboardError] = useState('');
  const [dashboardLoading, setDashboardLoading] = useState(false);
  const reloadRef = useRef<(() => void) | null>(null);
  const reload = useCallback(() => reloadRef.current?.(), []);

  useEffect(() => {
    ownerRef.current = identityKey;
    setServerSession(null);
    setDashboard(null);
    setLearningOwnershipFresh(false);
    setDashboardError('');
    setDashboardLoading(false);
  }, [identityKey]);

  useFocusEffect(
    useCallback(() => {
      if (!appIsActive) return () => undefined;
      let active = true;
      let loading = false;
      let readGeneration = 0;
      const stillOwned = (
        boundary: Awaited<ReturnType<typeof captureAccountSessionBoundary>>,
        generation: number,
      ) => {
        if (
          !active ||
          generation !== readGeneration ||
          ownerRef.current !== identityKey
        ) {
          return false;
        }
        try {
          assertAccountSessionBoundary(boundary);
          return true;
        } catch {
          setLearningOwnershipFresh(false);
          setDashboardLoading(false);
          setDashboardError('تغيّر الحساب\nافتح ركني من جديد');
          return false;
        }
      };

      const load = async (generation: number) => {
        const boundary = await captureAccountSessionBoundary().catch(
          () => null,
        );
        const sessionAvailable = await hasSession();
        if (!active || generation !== readGeneration) return;
        if (!boundary) {
          setServerSession(sessionAvailable);
          setDashboardLoading(false);
          setLearningOwnershipFresh(false);
          if (sessionAvailable) {
            setDashboardError('تعذّر تجهيز بيانات ركني\nحاول مرة أخرى');
          }
          return;
        }
        if (!stillOwned(boundary, generation)) return;
        setServerSession(sessionAvailable);
        if (!sessionAvailable) {
          setDashboard(null);
          setLearningOwnershipFresh(false);
          setDashboardError('');
          return;
        }

        // Start the authoritative network read before touching device storage.
        // A slow or damaged cache may improve first paint but never owns it.
        let coursesDelivered = false;
        const freshDashboardRequest = getLearningDashboard({
          onCoursesReady: courses => {
            if (!stillOwned(boundary, generation)) return;
            coursesDelivered = true;
            setDashboard(current => ({
              paths: [],
              badges: [],
              activityDays: [],
              currentStreakDays: 0,
              ...current,
              courses,
              partialError: undefined,
            }));
            setLearningOwnershipFresh(true);
            setDashboardError('');
            setDashboardLoading(false);
            // A later explicit refresh may replace this read while secondary
            // panels finish. Its generation owns both UI and cache delivery.
            loading = false;
          },
        }).then(
          value => ({ok: true as const, value}),
          error => ({ok: false as const, error}),
        );
        const cached = await settleWithin(getCachedLearningDashboard(), null);
        if (!stillOwned(boundary, generation)) return;
        if (cached) setDashboard(current => current || cached);
        try {
          const result = await freshDashboardRequest;
          if (!result.ok) throw result.error;
          const fresh = result.value;
          if (stillOwned(boundary, generation)) {
            setDashboard(fresh);
            setLearningOwnershipFresh(true);
            setDashboardError(fresh.partialError || '');
          }
        } catch (error) {
          if (stillOwned(boundary, generation)) {
            setLearningOwnershipFresh(coursesDelivered);
            setDashboardError(
              coursesDelivered
                ? 'تعذّر تحديث بعض بيانات تقدمك\nكورساتك جاهزة للاستكمال'
                : cached
                ? 'نعرض آخر تقدم محفوظ\nسنحدّثه عند عودة الاتصال'
                : `${friendlyNetworkMessage(error, 'كورساتك')}\nتقدمك محفوظ`,
            );
          }
        }
      };

      // Focus, foreground and explicit retry share one screen-owned read.
      const refresh = () => {
        if (!active || loading) return;
        const generation = ++readGeneration;
        loading = true;
        setDashboardLoading(true);
        setDashboardError('');
        setLearningOwnershipFresh(false);
        void load(generation)
          .catch(error => {
            if (!active || generation !== readGeneration) return;
            setLearningOwnershipFresh(false);
            setDashboardError(
              `${friendlyNetworkMessage(error, 'كورساتك')}\nتقدمك محفوظ`,
            );
          })
          .finally(() => {
            if (generation !== readGeneration) return;
            loading = false;
            if (active) setDashboardLoading(false);
          });
      };
      reloadRef.current = refresh;
      refresh();

      return () => {
        active = false;
        if (reloadRef.current === refresh) reloadRef.current = null;
      };
    }, [appIsActive, identityKey]),
  );

  return {
    dashboard,
    dashboardError,
    dashboardLoading,
    learningOwnershipFresh,
    owned: ownerRef.current === identityKey,
    reload,
    serverSession,
  };
};
