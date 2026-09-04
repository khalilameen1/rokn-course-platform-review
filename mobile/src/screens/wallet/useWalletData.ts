import {useCallback, useEffect, useRef, useState} from 'react';
import {useFocusEffect} from '@react-navigation/native';
import {AppState} from 'react-native';
import type {CoinPackage} from '../../services/api/coinPackageMapper';
import {
  getCoinPackages,
  getCoinTasks,
  getWallet,
  hasSession,
  type CoinTask,
  type WalletSnapshot,
} from '../../services/roknApi';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {settleWithin} from '../../utils/settleWithin';
import {
  readWalletCache,
  saveWalletCache,
  type WalletCache,
} from './walletCache';

export type WalletAreaStatus = 'idle' | 'loading' | 'ready' | 'error';

export const useWalletData = (identityKey: string) => {
  const [wallet, setWallet] = useState<WalletSnapshot | null>(null);
  const [packages, setPackages] = useState<CoinPackage[]>([]);
  const [tasks, setTasks] = useState<CoinTask[]>([]);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [walletStatus, setWalletStatus] = useState<WalletAreaStatus>('idle');
  const [packagesStatus, setPackagesStatus] =
    useState<WalletAreaStatus>('idle');
  const [tasksStatus, setTasksStatus] = useState<WalletAreaStatus>('idle');
  const [manualRefreshing, setManualRefreshing] = useState(false);

  const requestGenerationRef = useRef(0);
  const refreshFlightRef = useRef<Promise<void> | null>(null);
  const queuedRefreshRef = useRef<Promise<void> | null>(null);
  const manualRefreshRef = useRef<symbol | null>(null);
  const ownerRef = useRef(identityKey);
  const walletRef = useRef<WalletSnapshot | null>(null);
  const packagesRef = useRef<CoinPackage[]>([]);
  const tasksRef = useRef<CoinTask[]>([]);
  const packagesKnownRef = useRef(false);
  const tasksKnownRef = useRef(false);

  walletRef.current = wallet;
  packagesRef.current = packages;
  tasksRef.current = tasks;

  const ownsBoundary = useCallback((boundary: AccountSessionBoundary) => {
    try {
      assertAccountSessionBoundary(boundary);
      return true;
    } catch {
      return false;
    }
  }, []);

  const updateTask = useCallback(
    (taskId: string, update: Partial<Pick<CoinTask, 'status' | 'url'>>) => {
      const next = tasksRef.current.map(task =>
        task.id === taskId ? {...task, ...update} : task,
      );
      tasksRef.current = next;
      setTasks(next);
    },
    [],
  );

  const performRefresh = useCallback(async () => {
    const requestGeneration = ++requestGenerationRef.current;
    setWalletStatus(walletRef.current ? 'ready' : 'loading');
    setPackagesStatus(packagesKnownRef.current ? 'ready' : 'loading');
    setTasksStatus(tasksKnownRef.current ? 'ready' : 'loading');

    let boundary: AccountSessionBoundary;
    try {
      boundary = await captureAccountSessionBoundary();
    } catch {
      if (requestGeneration === requestGenerationRef.current) {
        setWalletStatus('error');
        setPackagesStatus('error');
        setTasksStatus('error');
      }
      return;
    }

    let sessionAvailable = false;
    try {
      sessionAvailable = await hasSession();
    } catch {
      if (
        requestGeneration === requestGenerationRef.current &&
        ownsBoundary(boundary)
      ) {
        setWalletStatus('error');
        setPackagesStatus('error');
        setTasksStatus('error');
      }
      return;
    }

    const requestOwnsData = () =>
      requestGeneration === requestGenerationRef.current &&
      ownsBoundary(boundary);
    if (!requestOwnsData()) return;

    setServerSession(sessionAvailable);
    if (!sessionAvailable) {
      setWalletStatus('idle');
      setPackagesStatus('idle');
      setTasksStatus('idle');
      return;
    }

    let walletReadFailed = false;
    let packagesReadFailed = false;
    let tasksReadFailed = false;
    const walletRequest = getWallet().then(
      value => {
        if (requestOwnsData()) {
          walletRef.current = value;
          setWallet(value);
          setWalletStatus('ready');
        }
        return value;
      },
      error => {
        walletReadFailed = true;
        if (requestOwnsData()) setWalletStatus('error');
        throw error;
      },
    );
    const packagesRequest = getCoinPackages().then(
      value => {
        if (requestOwnsData()) {
          packagesKnownRef.current = true;
          packagesRef.current = value;
          setPackages(value);
          setPackagesStatus('ready');
        }
        return value;
      },
      error => {
        packagesReadFailed = true;
        if (requestOwnsData()) setPackagesStatus('error');
        throw error;
      },
    );
    const tasksRequest = getCoinTasks().then(
      value => {
        if (requestOwnsData()) {
          tasksKnownRef.current = true;
          tasksRef.current = value;
          setTasks(value);
          setTasksStatus('ready');
        }
        return value;
      },
      error => {
        tasksReadFailed = true;
        if (requestOwnsData()) setTasksStatus('error');
        throw error;
      },
    );
    const remoteReads = Promise.allSettled([
      walletRequest,
      packagesRequest,
      tasksRequest,
    ]);

    const cached = await settleWithin(readWalletCache(boundary), null);
    if (!requestOwnsData()) return;
    if (cached?.wallet && !walletRef.current) {
      walletRef.current = cached.wallet;
      setWallet(cached.wallet);
      setWalletStatus(walletReadFailed ? 'error' : 'ready');
    }
    if (cached?.packages && !packagesKnownRef.current) {
      packagesKnownRef.current = true;
      packagesRef.current = cached.packages;
      setPackages(cached.packages);
      setPackagesStatus(packagesReadFailed ? 'error' : 'ready');
    }
    if (cached?.tasks && !tasksKnownRef.current) {
      tasksKnownRef.current = true;
      tasksRef.current = cached.tasks;
      setTasks(cached.tasks);
      setTasksStatus(tasksReadFailed ? 'error' : 'ready');
    }

    await remoteReads;
    if (!requestOwnsData()) return;
    const nextCache: WalletCache = {
      version: 2,
      ...(walletRef.current ? {wallet: walletRef.current} : {}),
      ...(packagesKnownRef.current ? {packages: packagesRef.current} : {}),
      ...(tasksKnownRef.current ? {tasks: tasksRef.current} : {}),
    };
    void saveWalletCache(boundary, nextCache).catch(() => undefined);
  }, [ownsBoundary]);

  const refresh = useCallback(() => {
    if (refreshFlightRef.current) return refreshFlightRef.current;
    let flight: Promise<void>;
    flight = performRefresh().finally(() => {
      if (refreshFlightRef.current === flight) refreshFlightRef.current = null;
    });
    refreshFlightRef.current = flight;
    return flight;
  }, [performRefresh]);

  const refreshAfterCurrent = useCallback(() => {
    const current = refreshFlightRef.current;
    if (!current) return refresh();
    if (queuedRefreshRef.current) return queuedRefreshRef.current;
    let queued: Promise<void>;
    queued = current.finally(() => {
      if (queuedRefreshRef.current !== queued) return;
      queuedRefreshRef.current = null;
      return refresh();
    });
    queuedRefreshRef.current = queued;
    return queued;
  }, [refresh]);

  const refreshManually = useCallback(async () => {
    const operation = Symbol('wallet-manual-refresh');
    manualRefreshRef.current = operation;
    setManualRefreshing(true);
    try {
      await refreshAfterCurrent();
    } finally {
      if (manualRefreshRef.current === operation) {
        manualRefreshRef.current = null;
        setManualRefreshing(false);
      }
    }
  }, [refreshAfterCurrent]);

  const invalidatePackages = useCallback(
    async (boundary: AccountSessionBoundary) => {
      if (!ownsBoundary(boundary)) return;
      packagesKnownRef.current = false;
      packagesRef.current = [];
      setPackages([]);
      setPackagesStatus('loading');
      await saveWalletCache(boundary, {
        version: 2,
        ...(walletRef.current ? {wallet: walletRef.current} : {}),
        ...(tasksKnownRef.current ? {tasks: tasksRef.current} : {}),
      }).catch(() => undefined);
    },
    [ownsBoundary],
  );

  useEffect(() => {
    requestGenerationRef.current += 1;
    refreshFlightRef.current = null;
    queuedRefreshRef.current = null;
    manualRefreshRef.current = null;
    ownerRef.current = identityKey;
    walletRef.current = null;
    packagesRef.current = [];
    tasksRef.current = [];
    packagesKnownRef.current = false;
    tasksKnownRef.current = false;
    setServerSession(null);
    setWallet(null);
    setPackages([]);
    setTasks([]);
    setWalletStatus('idle');
    setPackagesStatus('idle');
    setTasksStatus('idle');
    setManualRefreshing(false);
    void refresh();
  }, [identityKey, refresh]);

  useFocusEffect(
    useCallback(() => {
      void refresh();
      const subscription = AppState.addEventListener('change', state => {
        if (state === 'active') void refresh();
      });
      // A screen blur does not cancel or forget a live account-bound read.
      // Its result is still useful on return and the account boundary rejects
      // it if ownership actually changed.
      return () => subscription.remove();
    }, [refresh]),
  );

  return {
    identityKey,
    invalidatePackages,
    manualRefreshing,
    ownerReady: ownerRef.current === identityKey,
    ownsBoundary,
    packages,
    packagesStatus,
    refresh,
    refreshAfterCurrent,
    refreshManually,
    serverSession,
    tasks,
    tasksStatus,
    updateTask,
    wallet,
    walletStatus,
  };
};

export type WalletData = ReturnType<typeof useWalletData>;
