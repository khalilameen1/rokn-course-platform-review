import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {useIsFocused} from '@react-navigation/native';
import {Alert} from 'react-native';
import {
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {
  claimCoinTask,
  startCoinTask,
  type CoinTask,
} from '../../services/roknApi';
import {openExternalUrlOnce} from '../../services/systemActions';
import {trustedExternalTaskUrl} from '../../services/externalTaskUrlPolicy';
import {errorCode, learnerErrorMessage} from '../../utils/errorPayload';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import type {WalletData} from './useWalletData';

const isCoinGuideTask = (task: CoinTask) =>
  task.actionKey.toLowerCase().includes('coin_guide');

const isWhatsAppTask = (task: CoinTask) => task.actionKey === 'link_whatsapp';

const isSocialTask = (task: CoinTask) => {
  const action = task.actionKey.toLowerCase();
  return ['instagram', 'tiktok', 'facebook', 'youtube'].some(channel =>
    action.includes(channel),
  );
};

export const walletTaskActionLabel = (
  task: CoinTask,
  openingNeedsRetry: boolean,
) => {
  if (task.status === 'claimed') return 'تم الاستلام';
  if (task.status === 'ready_to_claim') {
    return !isWhatsAppTask(task) && openingNeedsRetry && task.url
      ? 'فتح'
      : 'استلام';
  }
  if (openingNeedsRetry) return 'فتح';
  if (task.status === 'started' && isWhatsAppTask(task)) return 'فتح';
  if (task.status === 'started') return 'استلام';
  if (isCoinGuideTask(task)) return 'اعرف أكثر';
  if (isWhatsAppTask(task)) return 'اربط';
  if (task.actionKey.toLowerCase().includes('youtube')) return 'اشتراك';
  if (isSocialTask(task)) return 'متابعة';
  return 'ابدأ';
};

type WalletTaskData = Pick<
  WalletData,
  'identityKey' | 'ownsBoundary' | 'refreshAfterCurrent' | 'updateTask'
>;

type TaskPresentationVisit = {
  identityKey: string;
  focused: boolean;
  foreground: boolean;
};

export const useWalletTasks = (
  data: WalletTaskData,
  showCoinRules: () => void,
) => {
  const {identityKey, ownsBoundary, refreshAfterCurrent, updateTask} = data;
  const focused = useIsFocused();
  const foreground = useAppForegroundState();
  const presentationVisit = useMemo<TaskPresentationVisit>(
    () => ({identityKey, focused, foreground}),
    [identityKey, focused, foreground],
  );
  const presentationRef = useRef(presentationVisit);
  presentationRef.current = presentationVisit;
  const mountedRef = useRef(false);
  const [loadingOperations, setLoadingOperations] = useState<
    Record<string, string>
  >({});
  const [openRetryIds, setOpenRetryIds] = useState<string[]>([]);
  const flightsRef = useRef(new Set<string>());
  const ownerRef = useRef(identityKey);
  ownerRef.current = identityKey;

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  // A server receipt belongs to the account. A link/modal/error belongs only
  // to the visit that requested it, even after leaving and returning here.
  const ownsTaskBoundary = useCallback(
    (boundary: AccountSessionBoundary) =>
      mountedRef.current && ownsBoundary(boundary),
    [ownsBoundary],
  );
  const ownsPresentation = useCallback(
    (visit: TaskPresentationVisit) =>
      mountedRef.current &&
      presentationRef.current === visit &&
      visit.focused &&
      visit.foreground,
    [],
  );

  useEffect(() => {
    flightsRef.current.clear();
    setLoadingOperations({});
    setOpenRetryIds([]);
  }, [identityKey]);

  const setOpeningNeedsRetry = useCallback(
    (taskId: string, needsRetry: boolean) => {
      setOpenRetryIds(current =>
        needsRetry
          ? current.includes(taskId)
            ? current
            : [...current, taskId]
          : current.filter(id => id !== taskId),
      );
    },
    [],
  );

  const openTaskDestination = useCallback(
    async (
      task: CoinTask,
      boundary: AccountSessionBoundary,
      visit: TaskPresentationVisit,
      url?: string,
    ) => {
      if (!ownsTaskBoundary(boundary)) return false;
      if (!ownsPresentation(visit)) {
        // The server started the attempt, but no OS hand-off happened. Let
        // the next explicit tap reopen it instead of implying a completed visit.
        setOpeningNeedsRetry(task.id, true);
        return false;
      }
      const trustedUrl = trustedExternalTaskUrl(url);
      if (!trustedUrl) {
        setOpeningNeedsRetry(task.id, true);
        Alert.alert(
          isWhatsAppTask(task) ? 'ربط واتساب غير متاح' : 'تعذّر فتح المهمة',
          'رابط المهمة غير متاح',
        );
        return false;
      }
      try {
        await openExternalUrlOnce(trustedUrl);
        if (!ownsTaskBoundary(boundary)) return false;
        // A successful hand-off normally backgrounds Rokn. That must not
        // turn a legitimate opening into a failed/retry state.
        setOpeningNeedsRetry(task.id, false);
        return true;
      } catch (error: unknown) {
        if (!ownsTaskBoundary(boundary)) return false;
        setOpeningNeedsRetry(task.id, true);
        if (!ownsPresentation(visit)) return false;
        Alert.alert(
          isWhatsAppTask(task) ? 'تعذّر فتح واتساب' : 'تعذّر فتح المهمة',
          learnerErrorMessage(error, 'تحقق من الاتصال\nثم حاول مرة أخرى'),
        );
        return false;
      }
    },
    [ownsPresentation, ownsTaskBoundary, setOpeningNeedsRetry],
  );

  const applyTaskStart = useCallback(
    async (
      task: CoinTask,
      boundary: AccountSessionBoundary,
      visit: TaskPresentationVisit,
      started: Awaited<ReturnType<typeof startCoinTask>>,
    ) => {
      if (started.status === 'claimed') {
        updateTask(task.id, {status: 'claimed'});
        await refreshAfterCurrent();
        return;
      }

      updateTask(task.id, {
        status: started.status,
        url: started.url,
      });
      if (isCoinGuideTask(task)) {
        if (ownsPresentation(visit)) showCoinRules();
        return;
      }
      if (started.status === 'ready_to_claim' && isWhatsAppTask(task)) {
        return;
      }
      if (task.requiresExternalVisit || isWhatsAppTask(task)) {
        await openTaskDestination(task, boundary, visit, started.url);
      }
    },
    [
      openTaskDestination,
      ownsPresentation,
      refreshAfterCurrent,
      showCoinRules,
      updateTask,
    ],
  );

  const startAndOpenTask = useCallback(
    async (
      task: CoinTask,
      boundary: AccountSessionBoundary,
      visit: TaskPresentationVisit,
    ) => {
      try {
        const started = await startCoinTask(task, boundary);
        if (!ownsTaskBoundary(boundary)) return;
        await applyTaskStart(task, boundary, visit, started);
      } catch (error: unknown) {
        if (!ownsTaskBoundary(boundary)) return;
        if (errorCode(error) === 'task_unavailable') {
          // The campaign can end while its card remains on screen. Reconcile
          // that terminal answer behind any older read already in progress.
          void refreshAfterCurrent();
        }
        if (!ownsPresentation(visit)) return;
        Alert.alert(
          isWhatsAppTask(task) ? 'تعذّر فتح واتساب' : 'تعذّر بدء المهمة',
          learnerErrorMessage(error, 'تحقق من الاتصال\nثم حاول مرة أخرى'),
        );
      }
    },
    [applyTaskStart, ownsPresentation, ownsTaskBoundary, refreshAfterCurrent],
  );

  const resumeExternalTask = useCallback(
    async (
      task: CoinTask,
      boundary: AccountSessionBoundary,
      visit: TaskPresentationVisit,
    ) => {
      try {
        // The stored URL is only display recovery. Re-enter the server-owned
        // attempt before opening it so a removed or completed task cannot
        // reopen an obsolete destination.
        const resumed = await startCoinTask(task, boundary);
        if (!ownsTaskBoundary(boundary)) return;
        await applyTaskStart(task, boundary, visit, resumed);
      } catch (error: unknown) {
        if (!ownsTaskBoundary(boundary)) return;
        if (errorCode(error) === 'task_unavailable') {
          void refreshAfterCurrent();
        }
        setOpeningNeedsRetry(task.id, true);
        if (!ownsPresentation(visit)) return;
        Alert.alert(
          isWhatsAppTask(task) ? 'تعذّر فتح واتساب' : 'تعذّر فتح المهمة',
          learnerErrorMessage(error, 'تحقق من الاتصال\nثم حاول مرة أخرى'),
        );
      }
    },
    [
      applyTaskStart,
      ownsPresentation,
      ownsTaskBoundary,
      refreshAfterCurrent,
      setOpeningNeedsRetry,
    ],
  );

  const claimTask = useCallback(
    async (
      task: CoinTask,
      boundary: AccountSessionBoundary,
      visit: TaskPresentationVisit,
    ) => {
      try {
        await claimCoinTask(task, boundary);
        if (!ownsTaskBoundary(boundary)) return;
        updateTask(task.id, {status: 'claimed'});
        // The mutation response is not the full financial breakdown. Reload
        // the server snapshot instead of adding a local delta that can be
        // applied twice after a foreground refresh.
        await refreshAfterCurrent();
      } catch (error: unknown) {
        if (!ownsTaskBoundary(boundary)) return;
        void refreshAfterCurrent();
        if (!ownsPresentation(visit)) return;
        Alert.alert(
          'تعذّر تأكيد المكافأة',
          learnerErrorMessage(error, 'حدّث رصيدك قبل المحاولة مرة أخرى'),
        );
      }
    },
    [ownsPresentation, ownsTaskBoundary, refreshAfterCurrent, updateTask],
  );

  const runTask = useCallback(
    async (
      task: CoinTask,
      boundary: AccountSessionBoundary,
      visit: TaskPresentationVisit,
    ) => {
      if (!ownsTaskBoundary(boundary)) return;
      const openingNeedsRetry = openRetryIds.includes(task.id);
      if (
        (task.status === 'started' && isWhatsAppTask(task)) ||
        (openingNeedsRetry &&
          Boolean(task.url) &&
          !(task.status === 'ready_to_claim' && isWhatsAppTask(task)))
      ) {
        await resumeExternalTask(task, boundary, visit);
      } else if (task.status === 'available') {
        await startAndOpenTask(task, boundary, visit);
      } else {
        await claimTask(task, boundary, visit);
      }
    },
    [
      claimTask,
      openRetryIds,
      ownsTaskBoundary,
      resumeExternalTask,
      startAndOpenTask,
    ],
  );

  const handleTask = useCallback(
    async (task: CoinTask) => {
      const operationOwner = identityKey;
      const visit = presentationVisit;
      if (!ownsPresentation(visit)) return;
      let boundary: AccountSessionBoundary;
      try {
        boundary = await captureAccountSessionBoundary();
      } catch {
        return;
      }
      if (!ownsTaskBoundary(boundary) || !ownsPresentation(visit)) return;
      const operationKey = `${boundary.scope}:${boundary.epoch}:${task.id}`;
      if (task.status === 'claimed' || flightsRef.current.has(operationKey)) {
        return;
      }

      flightsRef.current.add(operationKey);
      setLoadingOperations(current => ({...current, [task.id]: operationKey}));
      try {
        await runTask(task, boundary, visit);
      } finally {
        if (mountedRef.current && ownerRef.current === operationOwner) {
          // Ending this request releases its own spinner even when its secure
          // session expired. It cannot release a newer request for this task.
          setLoadingOperations(current => {
            if (current[task.id] !== operationKey) return current;
            const next = {...current};
            delete next[task.id];
            return next;
          });
        }
        flightsRef.current.delete(operationKey);
      }
    },
    [
      identityKey,
      ownsPresentation,
      ownsTaskBoundary,
      presentationVisit,
      runTask,
    ],
  );

  const taskActionLabel = useCallback(
    (task: CoinTask) =>
      walletTaskActionLabel(task, openRetryIds.includes(task.id)),
    [openRetryIds],
  );

  return {
    handleTask,
    loadingIds: Object.keys(loadingOperations),
    taskActionLabel,
  };
};
