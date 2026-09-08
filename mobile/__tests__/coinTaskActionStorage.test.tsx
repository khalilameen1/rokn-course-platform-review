import React from 'react';
import {Alert} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
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
}));
jest.mock('../src/services/roknApi', () =>
  jest.requireActual('../src/services/api/coinTasks'),
);
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: (...args: []) => mockOpenExternalUrlOnce(...args),
}));

import {
  getCoinTasks,
  startCoinTask,
  type CoinTask,
} from '../src/services/api/coinTasks';
import {useWalletTasks} from '../src/screens/wallet/useWalletTasks';
import {subscribeWalletSettlements} from '../src/services/walletSettlement';

const actionKey = (scope = mockBoundary.scope) =>
  `@rokn/coin-task-actions/v1:${scope}`;
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
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('optional task action URL persistence never owns server completion', () => {
  const disk = new Map<string, string>();
  const nativeGet = AsyncStorage.getItem as jest.Mock;
  const nativeSet = AsyncStorage.setItem as jest.Mock;
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
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  const advanceBudget = async () => {
    await act(async () => {
      await jest.advanceTimersByTimeAsync(750);
    });
  };

  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
    disk.clear();
    nativeGet.mockImplementation(async (key: string) => disk.get(key) ?? null);
    nativeSet.mockImplementation(async (key: string, value: string) => {
      disk.set(key, value);
    });
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
    jest.useRealTimers();
  });

  it('returns the fresh catalogue when optional remembered URL reading never settles', async () => {
    const read = deferred<string | null>();
    nativeGet.mockReturnValueOnce(read.promise);
    mockGet.mockResolvedValue(
      response([
        {
          id: 3,
          title_ar: task.title,
          coins_amount: 7,
          action_key: task.actionKey,
          requires_external_visit: true,
          task_state: 'started',
          action_url: currentUrl,
        },
      ]),
    );
    const settled = jest.fn();
    const loading = getCoinTasks().then(settled);
    try {
      await advanceBudget();
      expect(settled).toHaveBeenCalledWith([
        expect.objectContaining({
          id: task.id,
          status: 'started',
          url: currentUrl,
        }),
      ]);
      expect(mockGet).toHaveBeenCalledTimes(1);
    } finally {
      read.resolve(JSON.stringify({3: oldUrl}));
      await loading;
    }
  });

  it('opens the server-authorized current destination once while its optional cache write is suspended', async () => {
    const write = deferred<void>();
    nativeSet.mockImplementationOnce(async (key: string, value: string) => {
      await write.promise;
      disk.set(key, value);
    });
    mockPost.mockResolvedValue(
      response({attempt_id: 13, task_state: 'started', action_url: currentUrl}),
    );
    await mount();
    let opening!: Promise<void>;
    await act(async () => {
      opening = actions.handleTask(task);
      await actions.handleTask(task);
    });
    try {
      expect(actions.loadingIds).toEqual([task.id]);
      expect(nativeSet).toHaveBeenCalledTimes(1);
      await advanceBudget();
      expect(mockOpenExternalUrlOnce).toHaveBeenCalledWith(currentUrl);
      expect(mockOpenExternalUrlOnce).toHaveBeenCalledTimes(1);
      expect(actions.loadingIds).toEqual([]);
      expect(updateTask).toHaveBeenCalledWith(task.id, {
        status: 'started',
        url: currentUrl,
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(Alert.alert).not.toHaveBeenCalled();
    } finally {
      write.resolve();
      await act(async () => opening);
    }
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledTimes(1);
  });

  it('cannot use an old card URL instead of the destination required from a fresh start', async () => {
    disk.set(actionKey(), JSON.stringify({3: oldUrl}));
    mockPost.mockResolvedValue(
      response({attempt_id: 13, task_state: 'started'}),
    );
    await mount();
    await act(async () => actions.handleTask(task));
    expect(mockOpenExternalUrlOnce).not.toHaveBeenCalled();
    expect(updateTask).not.toHaveBeenCalled();
    expect(nativeSet).not.toHaveBeenCalled();
    expect(Alert.alert).toHaveBeenCalledTimes(1);
    expect(actions.loadingIds).toEqual([]);
    expect(mockPost).toHaveBeenCalledTimes(1);
  });

  it.each(['read', 'write'])(
    'marks a confirmed claim and releases the button when optional URL cleanup %s is suspended',
    async stage => {
      const read = deferred<string | null>();
      const write = deferred<void>();
      const cached = JSON.stringify({3: oldUrl});
      disk.set(actionKey(), cached);
      if (stage === 'read') nativeGet.mockReturnValueOnce(read.promise);
      else
        nativeSet.mockImplementationOnce(async (key: string, value: string) => {
          await write.promise;
          disk.set(key, value);
        });
      mockPost.mockResolvedValue(
        response({task_state: 'claimed', new_balance: 27, earned_amount: 7}),
      );
      const observed = jest.fn();
      const stop = subscribeWalletSettlements(observed);
      await mount();
      let claiming!: Promise<void>;
      await act(async () => {
        claiming = actions.handleTask({...task, status: 'ready_to_claim'});
      });
      try {
        expect(observed).toHaveBeenCalledTimes(1);
        expect(actions.loadingIds).toEqual([task.id]);
        await advanceBudget();
        expect(updateTask).toHaveBeenCalledWith(task.id, {status: 'claimed'});
        expect(refreshAfterCurrent).toHaveBeenCalledTimes(1);
        expect(actions.loadingIds).toEqual([]);
        expect(mockPost).toHaveBeenCalledTimes(1);
        expect(Alert.alert).not.toHaveBeenCalled();
      } finally {
        read.resolve(cached);
        write.resolve();
        await act(async () => claiming);
        stop();
      }
      expect(observed).toHaveBeenCalledTimes(1);
      expect(refreshAfterCurrent).toHaveBeenCalledTimes(1);
    },
  );

  it('keeps late native writes ordered after their callers have stopped waiting', async () => {
    const write = deferred<void>();
    const newerUrl = 'https://www.instagram.com/updated-campaign/';
    nativeSet.mockImplementationOnce(async (key: string, value: string) => {
      await write.promise;
      disk.set(key, value);
    });
    mockPost
      .mockResolvedValueOnce(
        response({
          attempt_id: 13,
          task_state: 'started',
          action_url: currentUrl,
        }),
      )
      .mockResolvedValueOnce(
        response({attempt_id: 13, task_state: 'started', action_url: newerUrl}),
      );
    const first = startCoinTask(task);
    try {
      await advanceBudget();
      await expect(first).resolves.toEqual({
        status: 'started',
        url: currentUrl,
      });
      const second = startCoinTask(task);
      await advanceBudget();
      await expect(second).resolves.toEqual({status: 'started', url: newerUrl});
      expect(nativeSet).toHaveBeenCalledTimes(1);
      expect(disk.has(actionKey())).toBe(false);
      expect(mockPost).toHaveBeenCalledTimes(2);
    } finally {
      write.resolve();
      await act(async () => {
        await first;
        await jest.advanceTimersByTimeAsync(0);
      });
    }
    expect(nativeSet).toHaveBeenCalledTimes(2);
    expect(JSON.parse(disk.get(actionKey())!)).toEqual({3: newerUrl});
    expect(mockOpenExternalUrlOnce).not.toHaveBeenCalled();
  });

  it.each(['user-1', 'user-2'])(
    'cannot return an old catalogue after the session changes to %s during its cache budget',
    async scope => {
      const read = deferred<string | null>();
      nativeGet.mockReturnValueOnce(read.promise);
      mockGet.mockResolvedValue(response([]));
      const loading = getCoinTasks().catch(error => error);
      try {
        await act(async () => undefined);
        expect(nativeGet).toHaveBeenCalledTimes(1);
        mockBoundary = {scope, epoch: mockBoundary.epoch + 1};
        await advanceBudget();
        await expect(loading).resolves.toMatchObject({
          message: 'ACCOUNT_CHANGED_DURING_REQUEST',
        });
      } finally {
        read.resolve(null);
        await loading;
      }
    },
  );

  it.each(['user-1', 'user-2'])(
    'never opens the old attempt when its session changes to %s while remembering its URL',
    async scope => {
      const write = deferred<void>();
      const oldKey = actionKey();
      nativeSet.mockImplementationOnce(async (key: string, value: string) => {
        await write.promise;
        disk.set(key, value);
      });
      mockPost.mockResolvedValue(
        response({
          attempt_id: 13,
          task_state: 'started',
          action_url: currentUrl,
        }),
      );
      await mount();
      let opening!: Promise<void>;
      await act(async () => {
        opening = actions.handleTask(task);
      });
      try {
        expect(nativeSet).toHaveBeenCalledTimes(1);
        mockBoundary = {scope, epoch: mockBoundary.epoch + 1};
        await act(async () => renderer!.update(<Harness />));
        await advanceBudget();
        await opening;
        expect(mockOpenExternalUrlOnce).not.toHaveBeenCalled();
        expect(updateTask).not.toHaveBeenCalled();
        expect(refreshAfterCurrent).not.toHaveBeenCalled();
        expect(Alert.alert).not.toHaveBeenCalled();
        expect(actions.loadingIds).toEqual([]);
      } finally {
        write.resolve();
        await act(async () => {
          await opening;
          await jest.advanceTimersByTimeAsync(0);
        });
      }
      expect(nativeSet).toHaveBeenCalledTimes(1);
      expect(nativeSet).toHaveBeenCalledWith(
        oldKey,
        JSON.stringify({3: currentUrl}),
      );
      expect(mockOpenExternalUrlOnce).not.toHaveBeenCalled();
    },
  );

  it('allows another account to finish its start without releasing the previous raw cache write', async () => {
    const write = deferred<void>();
    const oldKey = actionKey();
    const secondUrl = 'https://www.instagram.com/second-account/';
    nativeSet.mockImplementationOnce(async (key: string, value: string) => {
      await write.promise;
      disk.set(key, value);
    });
    mockPost
      .mockResolvedValueOnce(
        response({
          attempt_id: 13,
          task_state: 'started',
          action_url: currentUrl,
        }),
      )
      .mockResolvedValueOnce(
        response({
          attempt_id: 21,
          task_state: 'started',
          action_url: secondUrl,
        }),
      );
    const first = startCoinTask(task);
    try {
      await advanceBudget();
      await first;
      mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
      await mount();
      let opening!: Promise<void>;
      await act(async () => {
        opening = actions.handleTask(task);
      });
      await advanceBudget();
      await opening;
      expect(mockOpenExternalUrlOnce).toHaveBeenCalledWith(secondUrl);
      expect(actions.loadingIds).toEqual([]);
      expect(nativeSet).toHaveBeenCalledTimes(1);
    } finally {
      write.resolve();
      await act(async () => {
        await first;
        await jest.advanceTimersByTimeAsync(0);
      });
    }
    expect(JSON.parse(disk.get(oldKey)!)).toEqual({3: currentUrl});
    expect(JSON.parse(disk.get(actionKey())!)).toEqual({3: secondUrl});
    expect(nativeSet).toHaveBeenCalledTimes(2);
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledTimes(1);
  });
});
