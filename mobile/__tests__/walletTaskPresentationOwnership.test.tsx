import React from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockFocused = true;
let mockForeground = true;
let mockBoundary = {scope: 'user-1', epoch: 1};
const mockCaptureBoundary = jest.fn();
const mockPost = jest.fn();
const mockOpenExternalUrlOnce = jest.fn();

jest.mock('@react-navigation/native', () => ({
  useIsFocused: () => mockFocused,
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => mockForeground,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: () => mockCaptureBoundary(),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockPost(...args)},
}));
// Keep the real task command contract, session guards and settlement publisher.
jest.mock('../src/services/roknApi', () =>
  jest.requireActual('../src/services/api/coinTasks'),
);
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: (...args: unknown[]) => mockOpenExternalUrlOnce(...args),
}));
// Decorative leaves only; the real task row/button determines disabled state.
jest.mock('../src/components/ui/TaskBrandIcon', () => () => null);
jest.mock('../src/components/ui/RoknCoin', () => ({
  __esModule: true,
  default: () => null,
  CoinAmount: () => null,
}));
jest.mock('../src/assets/SVG', () => ({
  AccordionArrowDown: () => null,
  AccordionArrowUp: () => null,
}));

import type {CoinTask} from '../src/services/api/coinTasks';
import {useWalletTasks} from '../src/screens/wallet/useWalletTasks';
import {subscribeWalletSettlements} from '../src/services/walletSettlement';
import {RewardsTaskList} from '../src/screens/wallet/RewardsTaskList';
import type {WalletController} from '../src/screens/wallet/useWalletController';

const task: CoinTask = {
  id: 'production-3',
  serverId: '3',
  title: 'تابع ركن',
  description: '',
  reward: 7,
  status: 'available',
  actionKey: 'follow_instagram',
  requiresExternalVisit: true,
  url: 'https://www.instagram.com/old-campaign/',
};
const currentUrl = 'https://www.instagram.com/current-campaign/';
const readyTask: CoinTask = {
  ...task,
  status: 'ready_to_claim',
  url: currentUrl,
};
const response = (data: Record<string, unknown>) => ({data: {data}});
const startResponse = (status = 'started', url = currentUrl) =>
  response({attempt_id: 'attempt-3', task_state: status, action_url: url});
const claimResponse = () =>
  response({task_state: 'claimed', new_balance: 27, earned_amount: 7});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};

describe('wallet task commands and their initiating presentation visit', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let actions!: ReturnType<typeof useWalletTasks>;
  const updateTask = jest.fn();
  const refreshAfterCurrent = jest.fn(async () => undefined);
  const showCoinRules = jest.fn();
  const settled = jest.fn();
  let stopSettlement: (() => void) | undefined;

  const Harness = ({
    identityKey,
    showButton = false,
  }: {
    identityKey: string;
    showButton?: boolean;
  }) => {
    actions = useWalletTasks(
      {
        identityKey,
        ownsBoundary: boundary =>
          boundary.scope === mockBoundary.scope &&
          boundary.epoch === mockBoundary.epoch,
        updateTask,
        refreshAfterCurrent,
      },
      showCoinRules,
    );
    if (!showButton) return null;
    const controller: WalletController = {
      displayedBalance: 20,
      displayedRewardBalance: 20,
      displayedCoinRules: [],
      displayedTasks: [task],
      displayedTransactions: [],
      handleTask: actions.handleTask,
      manualRefreshing: false,
      ownerReady: true,
      refreshWallet: refreshAfterCurrent,
      refreshWalletManually: refreshAfterCurrent,
      serverSession: true,
      setWalletModal: () => undefined,
      taskActionLabel: actions.taskActionLabel,
      taskLoadingIds: actions.loadingIds,
      tasksStatus: 'ready',
      usingRemoteWallet: true,
      walletModal: null,
      walletStatus: 'ready',
    };
    return <RewardsTaskList controller={controller} stacked={false} />;
  };
  const render = async (showButton = false) => {
    await act(async () => {
      const screen = (
        <Harness identityKey={mockBoundary.scope} showButton={showButton} />
      );
      if (renderer) renderer.update(screen);
      else renderer = TestRenderer.create(screen);
    });
  };
  const begin = async (selected = task) => {
    let running!: Promise<void>;
    await act(async () => {
      running = actions.handleTask(selected);
    });
    return {running};
  };
  const expectQuiet = () => {
    expect(mockOpenExternalUrlOnce).not.toHaveBeenCalled();
    expect(showCoinRules).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  };
  beforeEach(() => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
    mockFocused = true;
    mockForeground = true;
    mockCaptureBoundary.mockReset();
    mockCaptureBoundary.mockImplementation(async () => ({...mockBoundary}));
    mockPost.mockReset();
    mockOpenExternalUrlOnce.mockReset();
    mockOpenExternalUrlOnce.mockResolvedValue(undefined);
    refreshAfterCurrent.mockResolvedValue(undefined);
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    stopSettlement = subscribeWalletSettlements(settled);
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    stopSettlement?.();
    jest.restoreAllMocks();
  });

  it('opens the fresh server URL once for a visible double tap', async () => {
    mockPost.mockResolvedValue(startResponse());
    await render();
    await act(async () => {
      await Promise.all([actions.handleTask(task), actions.handleTask(task)]);
    });
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledTimes(1);
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledWith(currentUrl);
    expect(actions.loadingIds).toEqual([]);
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it.each(['leave', 'leave and return', 'background and return'])(
    'settles an old start without presenting it after %s, then reopens only on a new tap',
    async transition => {
      const reply = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValueOnce(reply.promise);
      await render();
      const {running} = await begin();
      if (transition === 'background and return') mockForeground = false;
      else mockFocused = false;
      await render();
      if (transition !== 'leave') {
        mockFocused = true;
        mockForeground = true;
        await render();
      }
      await act(async () => {
        reply.resolve(startResponse('ready_to_claim'));
        await running;
      });
      expectQuiet();
      expect(updateTask).toHaveBeenCalledWith(task.id, {
        status: 'ready_to_claim',
        url: currentUrl,
      });
      expect(actions.taskActionLabel(readyTask)).toBe('فتح');
      expect(actions.loadingIds).toEqual([]);
      expect(settled).not.toHaveBeenCalled();
      if (transition === 'leave') {
        mockFocused = true;
        await render();
      }
      expect(mockPost).toHaveBeenCalledTimes(1);
      mockPost.mockResolvedValue(startResponse('ready_to_claim'));
      await act(async () => actions.handleTask(readyTask));
      expect(mockPost).toHaveBeenCalledTimes(2);
      expect(mockPost).toHaveBeenLastCalledWith(
        'coin-earning-methods/3/start',
        {
          supports_ready_claim: true,
        },
      );
      expect(mockOpenExternalUrlOnce).toHaveBeenCalledWith(currentUrl);
      expect(actions.taskActionLabel(readyTask)).toBe('استلام');
      expect(settled).not.toHaveBeenCalled();
    },
  );

  it('does not turn the background transition from a successful OS hand-off into a retry', async () => {
    const opening = deferred<void>();
    mockPost.mockResolvedValue(startResponse('ready_to_claim'));
    mockOpenExternalUrlOnce.mockReturnValue(opening.promise);
    await render();
    const {running} = await begin();
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledWith(currentUrl);
    mockForeground = false;
    await render();
    await act(async () => {
      opening.resolve(undefined);
      await running;
    });
    expect(actions.taskActionLabel(readyTask)).toBe('استلام');
    expect(actions.loadingIds).toEqual([]);
    expect(Alert.alert).not.toHaveBeenCalled();
    expect(mockPost).toHaveBeenCalledTimes(1);
  });

  it('keeps a failed OS hand-off retryable without a global alert over another page', async () => {
    const opening = deferred<void>();
    mockPost.mockResolvedValue(startResponse('ready_to_claim'));
    mockOpenExternalUrlOnce.mockReturnValue(opening.promise);
    await render();
    const {running} = await begin();
    mockFocused = false;
    await render();
    await act(async () => {
      opening.reject(new Error('OS refused destination'));
      await running;
    });
    expect(Alert.alert).not.toHaveBeenCalled();
    expect(actions.taskActionLabel(readyTask)).toBe('فتح');
    expect(actions.loadingIds).toEqual([]);
  });

  it('updates a guide attempt without opening its rules after leaving', async () => {
    const reply = deferred<ReturnType<typeof response>>();
    const guide = {
      ...task,
      actionKey: 'coin_guide',
      requiresExternalVisit: false,
      url: undefined,
    };
    mockPost.mockReturnValue(reply.promise);
    await render();
    const {running} = await begin(guide);
    mockFocused = false;
    await render();
    await act(async () => {
      reply.resolve(startResponse('ready_to_claim', ''));
      await running;
    });
    expectQuiet();
    expect(updateTask).toHaveBeenCalledWith(task.id, {
      status: 'ready_to_claim',
      url: undefined,
    });
  });

  it.each(['starting', 'resuming'])(
    'quietly reconciles a retired campaign while %s away from the page',
    async mode => {
      const reply = deferred<ReturnType<typeof response>>();
      const selected: CoinTask =
        mode === 'resuming'
          ? {...task, actionKey: 'link_whatsapp', status: 'started'}
          : task;
      mockPost.mockReturnValue(reply.promise);
      await render();
      const {running} = await begin(selected);
      mockFocused = false;
      await render();
      await act(async () => {
        reply.reject({
          response: {status: 404, data: {code: 'task_unavailable'}},
        });
        await running;
      });
      expectQuiet();
      expect(refreshAfterCurrent).toHaveBeenCalledTimes(1);
      expect(updateTask).not.toHaveBeenCalled();
      expect(actions.loadingIds).toEqual([]);
    },
  );

  it.each(['leave', 'background', 'unmount'])(
    'publishes a real confirmed claim after %s without a second credit or old message',
    async transition => {
      const reply = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValue(reply.promise);
      await render();
      const {running} = await begin(readyTask);
      if (transition === 'unmount') {
        await act(async () => renderer!.unmount());
        renderer = undefined;
      } else {
        if (transition === 'leave') mockFocused = false;
        else mockForeground = false;
        await render();
      }
      await act(async () => {
        reply.resolve(claimResponse());
        await running;
      });
      expectQuiet();
      expect(settled).toHaveBeenCalledTimes(1);
      expect(settled).toHaveBeenCalledWith(mockBoundary);
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(mockPost).toHaveBeenCalledWith('claim-coins', {method_id: 3});
      if (transition === 'unmount') {
        expect(updateTask).not.toHaveBeenCalled();
        expect(refreshAfterCurrent).not.toHaveBeenCalled();
      } else {
        expect(updateTask).toHaveBeenCalledWith(task.id, {status: 'claimed'});
        expect(refreshAfterCurrent).toHaveBeenCalledTimes(1);
        expect(actions.loadingIds).toEqual([]);
      }
    },
  );

  it('refreshes a failed claim after leaving without claiming local coins or showing an old error', async () => {
    const reply = deferred<ReturnType<typeof response>>();
    mockPost.mockReturnValue(reply.promise);
    await render();
    const {running} = await begin(readyTask);
    mockFocused = false;
    await render();
    await act(async () => {
      reply.reject(new Error('lost response'));
      await running;
    });
    expectQuiet();
    expect(refreshAfterCurrent).toHaveBeenCalledTimes(1);
    expect(updateTask).not.toHaveBeenCalled();
    expect(settled).not.toHaveBeenCalled();
    expect(actions.loadingIds).toEqual([]);
  });

  it.each(['start', 'claim'])(
    'cannot mutate the replacement account with an old %s response',
    async operation => {
      const reply = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValue(reply.promise);
      await render();
      const {running} = await begin(operation === 'start' ? task : readyTask);
      mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
      await render();
      await act(async () => {
        reply.resolve(
          operation === 'start' ? startResponse() : claimResponse(),
        );
        await running;
      });
      expectQuiet();
      expect(updateTask).not.toHaveBeenCalled();
      expect(refreshAfterCurrent).not.toHaveBeenCalled();
      expect(settled).not.toHaveBeenCalled();
      expect(actions.loadingIds).toEqual([]);
    },
  );

  it('lets a new session start without the obsolete session releasing its busy slot', async () => {
    const oldReply = deferred<ReturnType<typeof response>>();
    const newReply = deferred<ReturnType<typeof response>>();
    mockPost
      .mockReturnValueOnce(oldReply.promise)
      .mockReturnValueOnce(newReply.promise);
    await render();
    const previous = await begin();
    mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
    await render();
    const current = await begin();
    expect(mockPost).toHaveBeenCalledTimes(2);
    await act(async () => {
      oldReply.resolve(startResponse());
      await previous.running;
    });
    expectQuiet();
    expect(actions.loadingIds).toEqual([task.id]);
    // A tap while the new flight is live must still join the new busy slot.
    await act(async () => actions.handleTask(task));
    expect(mockPost).toHaveBeenCalledTimes(2);
    await act(async () => {
      newReply.resolve(startResponse());
      await current.running;
    });
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledTimes(1);
    expect(actions.loadingIds).toEqual([]);
  });

  it('releases the actual task button after same-account session replacement without a newer flight', async () => {
    const reply = deferred<ReturnType<typeof response>>();
    mockPost.mockReturnValueOnce(reply.promise);
    await render(true);
    // The installed Pressable export is memo-wrapped. Select the actual
    // accessible task control, retaining its real handler and busy state.
    const button = () => renderer!.root.findByProps({
      accessibilityRole: 'button',
      accessibilityLabel: 'متابعة تابع ركن',
    });
    expect(button().props.disabled).toBe(false);
    await act(async () => button().props.onPress());
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(button().props.disabled).toBe(true);
    expect(button().props.accessibilityState).toEqual({
      busy: true,
      disabled: true,
    });
    // Profile save replaces the secure session without changing account identity.
    mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
    mockFocused = false;
    await render(true);
    mockFocused = true;
    await render(true);
    await act(async () => {
      reply.resolve(startResponse());
    });
    expectQuiet();
    expect(updateTask).not.toHaveBeenCalled();
    expect(button().props.disabled).toBe(false);
    expect(button().props.accessibilityState).toEqual({
      busy: false,
      disabled: false,
    });
    mockPost.mockResolvedValue(startResponse());
    await act(async () => button().props.onPress());
    expect(mockPost).toHaveBeenCalledTimes(2);
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledTimes(1);
    expect(button().props.disabled).toBe(false);
  });

  it('does not dispatch a command when its account capture finishes after leaving', async () => {
    const boundary = deferred<typeof mockBoundary>();
    mockCaptureBoundary.mockReturnValueOnce(boundary.promise);
    await render();
    const {running} = await begin();
    mockFocused = false;
    await render();
    mockFocused = true;
    await render();
    await act(async () => {
      boundary.resolve({...mockBoundary});
      await running;
    });
    expectQuiet();
    expect(mockPost).not.toHaveBeenCalled();
    expect(actions.loadingIds).toEqual([]);
    mockPost.mockResolvedValue(startResponse());
    await act(async () => actions.handleTask(task));
    expect(mockPost).toHaveBeenCalledTimes(1);
  });
});
