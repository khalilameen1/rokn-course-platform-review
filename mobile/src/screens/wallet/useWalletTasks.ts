import {useCallback, useEffect, useRef, useState} from 'react';
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
import {learnerErrorMessage} from '../../utils/errorPayload';
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
  if (isSocialTask(task)) return 'تابع';
  return 'ابدأ';
};

type WalletTaskData = Pick<
  WalletData,
  'identityKey' | 'ownsBoundary' | 'refreshAfterCurrent' | 'updateTask'
>;

export const useWalletTasks = (
  data: WalletTaskData,
  showCoinRules: () => void,
) => {
  const {identityKey, ownsBoundary, refreshAfterCurrent, updateTask} = data;
  const [loadingIds, setLoadingIds] = useState<string[]>([]);
  const [openRetryIds, setOpenRetryIds] = useState<string[]>([]);
  const flightsRef = useRef(new Set<string>());
  const ownerRef = useRef(identityKey);

  useEffect(() => {
    ownerRef.current = identityKey;
    flightsRef.current.clear();
    setLoadingIds([]);
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
    async (task: CoinTask, boundary: AccountSessionBoundary, url?: string) => {
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
        if (!ownsBoundary(boundary)) return false;
        setOpeningNeedsRetry(task.id, false);
        return true;
      } catch (error: unknown) {
        if (!ownsBoundary(boundary)) return false;
        setOpeningNeedsRetry(task.id, true);
        Alert.alert(
          isWhatsAppTask(task) ? 'تعذّر فتح واتساب' : 'تعذّر فتح المهمة',
          learnerErrorMessage(error, 'تحقق من الاتصال\nثم حاول مرة أخرى'),
        );
        return false;
      }
    },
    [ownsBoundary, setOpeningNeedsRetry],
  );

  const applyTaskStart = useCallback(
    async (
      task: CoinTask,
      boundary: AccountSessionBoundary,
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
        showCoinRules();
        return;
      }
      if (started.status === 'ready_to_claim' && isWhatsAppTask(task)) {
        return;
      }
      if (task.requiresExternalVisit || isWhatsAppTask(task)) {
        await openTaskDestination(task, boundary, started.url);
      }
    },
    [
      openTaskDestination,
      refreshAfterCurrent,
      showCoinRules,
      updateTask,
    ],
  );

  const startAndOpenTask = useCallback(
    async (task: CoinTask, boundary: AccountSessionBoundary) => {
      try {
        const started = await startCoinTask(task, boundary);
        if (!ownsBoundary(boundary)) return;
        await applyTaskStart(task, boundary, started);
      } catch (error: unknown) {
        if (!ownsBoundary(boundary)) return;
        Alert.alert(
          isWhatsAppTask(task) ? 'تعذّر فتح واتساب' : 'تعذّر بدء المهمة',
          learnerErrorMessage(error, 'تحقق من الاتصال\nثم حاول مرة أخرى'),
        );
      }
    },
    [
      applyTaskStart,
      ownsBoundary,
    ],
  );

  const resumeExternalTask = useCallback(
    async (task: CoinTask, boundary: AccountSessionBoundary) => {
      try {
        // The stored URL is only display recovery. Re-enter the server-owned
        // attempt before opening it so a removed or completed task cannot
        // reopen an obsolete destination.
        const resumed = await startCoinTask(task, boundary);
        if (!ownsBoundary(boundary)) return;
        await applyTaskStart(task, boundary, resumed);
      } catch (error: unknown) {
        if (!ownsBoundary(boundary)) return;
        setOpeningNeedsRetry(task.id, true);
        Alert.alert(
          isWhatsAppTask(task) ? 'تعذّر فتح واتساب' : 'تعذّر فتح المهمة',
          learnerErrorMessage(error, 'تحقق من الاتصال\nثم حاول مرة أخرى'),
        );
      }
    },
    [
      applyTaskStart,
      ownsBoundary,
      setOpeningNeedsRetry,
    ],
  );

  const claimTask = useCallback(
    async (task: CoinTask, boundary: AccountSessionBoundary) => {
      try {
        await claimCoinTask(task, boundary);
        if (!ownsBoundary(boundary)) return;
        updateTask(task.id, {status: 'claimed'});
        // The mutation response is not the full financial breakdown. Reload
        // the server snapshot instead of adding a local delta that can be
        // applied twice after a foreground refresh.
        await refreshAfterCurrent();
      } catch (error: unknown) {
        if (!ownsBoundary(boundary)) return;
        void refreshAfterCurrent();
        Alert.alert(
          'تعذّر تأكيد المكافأة',
          learnerErrorMessage(error, 'حدّث المحفظة قبل المحاولة مرة أخرى'),
        );
      }
    },
    [ownsBoundary, refreshAfterCurrent, updateTask],
  );

  const runTask = useCallback(
    async (task: CoinTask, boundary: AccountSessionBoundary) => {
      if (!ownsBoundary(boundary)) return;
      const openingNeedsRetry = openRetryIds.includes(task.id);
      if (
        (task.status === 'started' && isWhatsAppTask(task)) ||
        (openingNeedsRetry &&
          Boolean(task.url) &&
          !(task.status === 'ready_to_claim' && isWhatsAppTask(task)))
      ) {
        await resumeExternalTask(task, boundary);
      } else if (task.status === 'available') {
        await startAndOpenTask(task, boundary);
      } else {
        await claimTask(task, boundary);
      }
    },
    [
      claimTask,
      openRetryIds,
      ownsBoundary,
      resumeExternalTask,
      startAndOpenTask,
    ],
  );

  const handleTask = useCallback(
    async (task: CoinTask) => {
      const operationOwner = identityKey;
      let boundary: AccountSessionBoundary;
      try {
        boundary = await captureAccountSessionBoundary();
      } catch {
        return;
      }
      const operationKey = `${boundary.scope}:${task.id}`;
      if (task.status === 'claimed' || flightsRef.current.has(operationKey)) {
        return;
      }

      flightsRef.current.add(operationKey);
      setLoadingIds(current =>
        current.includes(task.id) ? current : [...current, task.id],
      );
      try {
        await runTask(task, boundary);
      } finally {
        if (ownerRef.current === operationOwner) {
          setLoadingIds(current => current.filter(id => id !== task.id));
        }
        flightsRef.current.delete(operationKey);
      }
    },
    [identityKey, runTask],
  );

  const taskActionLabel = useCallback(
    (task: CoinTask) =>
      walletTaskActionLabel(task, openRetryIds.includes(task.id)),
    [openRetryIds],
  );

  return {handleTask, loadingIds, taskActionLabel};
};
