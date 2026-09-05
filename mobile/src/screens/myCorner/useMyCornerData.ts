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
      const stillOwned = (
        boundary: Awaited<ReturnType<typeof captureAccountSessionBoundary>>,
      ) => {
        if (!active || ownerRef.current !== identityKey) return false;
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

      void (async () => {
        setLearningOwnershipFresh(false);
        const boundary = await captureAccountSessionBoundary().catch(
          () => null,
        );
        const sessionAvailable = await hasSession();
        if (!active) return;
        if (!boundary) {
          setServerSession(sessionAvailable);
          setDashboardLoading(false);
          setLearningOwnershipFresh(false);
          if (sessionAvailable) {
            setDashboardError('تعذّر تجهيز بيانات ركني\nحاول مرة أخرى');
          }
          return;
        }
        if (!stillOwned(boundary)) return;
        setServerSession(sessionAvailable);
        if (!sessionAvailable) {
          setDashboard(null);
          setLearningOwnershipFresh(false);
          setDashboardError('');
          return;
        }

        // Start the authoritative network read before touching device storage.
        // A slow or damaged cache may improve first paint but never owns it.
        const freshDashboardRequest = getLearningDashboard().then(
          value => ({ok: true as const, value}),
          error => ({ok: false as const, error}),
        );
        const cached = await settleWithin(getCachedLearningDashboard(), null);
        if (!stillOwned(boundary)) return;
        if (cached) setDashboard(cached);
        setDashboardLoading(!cached);
        try {
          const result = await freshDashboardRequest;
          if (!result.ok) throw result.error;
          const fresh = result.value;
          if (stillOwned(boundary)) {
            setDashboard(fresh);
            setLearningOwnershipFresh(true);
            setDashboardError(fresh.partialError || '');
          }
        } catch (error) {
          if (stillOwned(boundary)) {
            setLearningOwnershipFresh(false);
            setDashboardError(
              cached
                ? 'نعرض آخر تقدم محفوظ\nسنحدّثه عند عودة الاتصال'
                : `${friendlyNetworkMessage(error, 'كورساتك')}\nتقدمك محفوظ`,
            );
          }
        } finally {
          if (stillOwned(boundary)) setDashboardLoading(false);
        }
      })().catch(error => {
        if (!active) return;
        setLearningOwnershipFresh(false);
        setDashboardLoading(false);
        setDashboardError(
          `${friendlyNetworkMessage(error, 'كورساتك')}\nتقدمك محفوظ`,
        );
      });

      return () => {
        active = false;
      };
    }, [appIsActive, identityKey]),
  );

  return {
    dashboard,
    dashboardError,
    dashboardLoading,
    learningOwnershipFresh,
    owned: ownerRef.current === identityKey,
    serverSession,
  };
};
