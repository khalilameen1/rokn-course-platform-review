import React from 'react';
import {Alert} from 'react-native';
import mockAsyncStorage from '@react-native-async-storage/async-storage';
import TestRenderer, {act} from 'react-test-renderer';

const mockGet = jest.fn();
const mockPost = jest.fn();
const mockOpenExternalUrlOnce = jest.fn(async () => undefined);
let mockBoundary = {scope: 'user-1', epoch: 1};
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  accountScopedStorageKey: async (key: string, boundary = mockBoundary) =>
    `${key}:${boundary.scope}`,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  getItem: async (key: string) => {
    const value = await mockAsyncStorage.getItem(key);
    return value ? JSON.parse(value) : null;
  },
  saveItem: async (key: string, value: unknown) =>
    mockAsyncStorage.setItem(key, JSON.stringify(value)),
}));
jest.mock('../src/services/roknApi', () =>
  jest.requireActual('../src/services/api/coinTasks'),
);
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: (...args: []) => mockOpenExternalUrlOnce(...args),
}));

import {
  claimCoinTask,
  getCoinTasks,
  startCoinTask,
  type CoinTask,
} from '../src/services/api/coinTasks';
import {useWalletTasks} from '../src/screens/wallet/useWalletTasks';
import {
  readWalletCache,
  saveWalletCache,
} from '../src/screens/wallet/walletCache';
import {subscribeWalletSettlements} from '../src/services/walletSettlement';

const oldUrl = 'https://www.instagram.com/old-campaign/';
const currentUrl = 'https://www.instagram.com/current-campaign/';
const task: CoinTask = {
  id: 'production-3',
  serverId: '3',
  title: 'تابع ركن',
  description: '',
  reward: 7,
  status: 'available',
  actionKey: 'follow_instagram',
  requiresExternalVisit: true,
  url: oldUrl,
};
const response = (data: unknown) => ({data: {data}});
const catalogue = (status = 'started', url: string | undefined = currentUrl) =>
  response([
    {
      id: 3,
      title_ar: task.title,
      coins_amount: 7,
      action_key: task.actionKey,
      requires_external_visit: true,
      task_state: status,
      action_url: url,
    },
  ]);
const start = () =>
  response({
    attempt_id: 13,
    task_state: 'started',
    action_url: currentUrl,
  });
const claim = () =>
  response({
    task_state: 'claimed',
    new_balance: 27,
    earned_amount: 7,
  });
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('task commands use server evidence, wallet cache owns display recovery', () => {
  const nativeGet = mockAsyncStorage.getItem as jest.Mock;
  const nativeSet = mockAsyncStorage.setItem as jest.Mock;
  const updateTask = jest.fn();
  const refreshAfterCurrent = jest.fn(async () => undefined);
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let actions!: ReturnType<typeof useWalletTasks>;
  const Harness = () => {
    actions = useWalletTasks(
      {
        identityKey: mockBoundary.scope,
        ownsBoundary: boundary =>
          boundary.scope === mockBoundary.scope &&
          boundary.epoch === mockBoundary.epoch,
        updateTask,
        refreshAfterCurrent,
      },
      () => undefined,
    );
    return null;
  };
  const expectNoTaskStorage = () => {
    expect(nativeGet).not.toHaveBeenCalled();
    expect(nativeSet).not.toHaveBeenCalled();
    expect(mockAsyncStorage.removeItem).not.toHaveBeenCalled();
  };
  beforeEach(() => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
    nativeGet.mockRejectedValue(new Error('native-storage-unavailable'));
    nativeSet.mockRejectedValue(new Error('native-storage-unavailable'));
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
  });

  it.each(['available', 'started', 'ready_to_claim', 'claimed'])(
    'reads the complete %s catalogue without a second URL store',
    async status => {
      mockGet.mockResolvedValue(catalogue(status));
      await expect(getCoinTasks()).resolves.toEqual([
        expect.objectContaining({status, url: currentUrl}),
      ]);
      expectNoTaskStorage();
    },
  );

  it('does not substitute an obsolete stored URL when the server omits it', async () => {
    nativeGet.mockResolvedValue(JSON.stringify({3: oldUrl}));
    mockGet.mockResolvedValue(catalogue('started', ''));
    await expect(getCoinTasks()).resolves.toEqual([
      expect.objectContaining({status: 'started', url: undefined}),
    ]);
    expectNoTaskStorage();
  });

  it('opens the fresh destination once and releases the button without task storage', async () => {
    mockPost.mockResolvedValue(start());
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      await Promise.all([actions.handleTask(task), actions.handleTask(task)]);
    });
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledTimes(1);
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledWith(currentUrl);
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(updateTask).toHaveBeenCalledWith(task.id, {
      status: 'started',
      url: currentUrl,
    });
    expect(actions.loadingIds).toEqual([]);
    expect(Alert.alert).not.toHaveBeenCalled();
    expectNoTaskStorage();
  });

  it('cannot open the old card destination when a fresh start omits its required URL', async () => {
    mockPost.mockResolvedValue(
      response({attempt_id: 13, task_state: 'started'}),
    );
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => actions.handleTask(task));
    expect(mockOpenExternalUrlOnce).not.toHaveBeenCalled();
    expect(updateTask).not.toHaveBeenCalled();
    expect(Alert.alert).toHaveBeenCalledTimes(1);
    expect(actions.loadingIds).toEqual([]);
    expectNoTaskStorage();
  });

  it('delivers confirmed claims without waiting for optional display persistence', async () => {
    mockPost.mockResolvedValue(claim());
    const observed = jest.fn();
    const stop = subscribeWalletSettlements(observed);
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      await act(async () =>
        actions.handleTask({...task, status: 'ready_to_claim'}),
      );
      expect(observed).toHaveBeenCalledTimes(1);
      expect(updateTask).toHaveBeenCalledWith(task.id, {status: 'claimed'});
      expect(refreshAfterCurrent).toHaveBeenCalledTimes(1);
      expect(actions.loadingIds).toEqual([]);
      expect(Alert.alert).not.toHaveBeenCalled();
      expectNoTaskStorage();
    } finally {
      stop();
    }
  });

  it('cannot let an older catalogue delete a newer attempt destination', async () => {
    const pending = deferred<ReturnType<typeof response>>();
    const legacyRecord = JSON.stringify({3: oldUrl});
    nativeGet.mockResolvedValue(legacyRecord);
    mockGet.mockReturnValueOnce(pending.promise);
    mockPost.mockResolvedValue(start());
    const loading = getCoinTasks();
    await act(async () => undefined);
    await expect(startCoinTask(task)).resolves.toEqual({
      status: 'started',
      url: currentUrl,
    });
    pending.resolve(catalogue('available', oldUrl));
    await loading;
    await act(async () => undefined);
    expectNoTaskStorage();
    mockGet.mockResolvedValue(catalogue());
    await expect(getCoinTasks()).resolves.toEqual([
      expect.objectContaining({status: 'started', url: currentUrl}),
    ]);
  });

  it.each(['read', 'start', 'claim'] as const)(
    'rejects a late %s result after the same account signs in again',
    async operation => {
      const pending = deferred<ReturnType<typeof response>>();
      mockGet.mockReturnValue(pending.promise);
      mockPost.mockReturnValue(pending.promise);
      const running = (
        operation === 'read'
          ? getCoinTasks()
          : operation === 'start'
          ? startCoinTask(task)
          : claimCoinTask(task)
      ).catch(error => error);
      await act(async () => undefined);
      mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
      pending.resolve(
        operation === 'read'
          ? catalogue()
          : operation === 'start'
          ? start()
          : claim(),
      );
      await expect(running).resolves.toMatchObject({
        message: 'ACCOUNT_CHANGED_DURING_REQUEST',
      });
      expectNoTaskStorage();
    },
  );

  it('starts a different account while an old account request remains suspended', async () => {
    const pending = deferred<ReturnType<typeof response>>();
    mockPost
      .mockReturnValueOnce(pending.promise)
      .mockResolvedValueOnce(start());
    const previous = startCoinTask(task).catch(error => error);
    await act(async () => undefined);
    mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
    try {
      await expect(startCoinTask(task)).resolves.toEqual({
        status: 'started',
        url: currentUrl,
      });
    } finally {
      pending.resolve(start());
      await expect(previous).resolves.toMatchObject({
        message: 'ACCOUNT_CHANGED_DURING_REQUEST',
      });
    }
    expect(mockPost).toHaveBeenCalledTimes(2);
    expectNoTaskStorage();
  });

  it('recovers the full task from the existing wallet snapshot and still reauthorizes opening', async () => {
    const disk = new Map<string, string>();
    nativeGet.mockImplementation(async (key: string) => disk.get(key) ?? null);
    nativeSet.mockImplementation(async (key: string, value: string) => {
      disk.set(key, value);
    });
    const cached = {
      ...task,
      actionKey: 'link_whatsapp',
      status: 'started' as const,
    };
    await saveWalletCache(mockBoundary, {version: 2, tasks: [cached]});
    const recovered = await readWalletCache(mockBoundary);
    expect(recovered?.tasks).toEqual([cached]);
    expect([...disk.keys()]).toEqual(['@rokn/wallet-cache/v2:user-1']);
    mockPost.mockResolvedValue(start());
    nativeGet.mockClear();
    nativeSet.mockClear();
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => actions.handleTask(recovered!.tasks![0]));
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledWith(currentUrl);
    expectNoTaskStorage();
  });
});
