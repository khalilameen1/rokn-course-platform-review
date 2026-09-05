import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockClaimCoinTask = jest.fn();
const mockStartCoinTask = jest.fn();
const mockOpenExternalUrlOnce = jest.fn();

jest.mock('../src/services/roknApi', () => ({
  claimCoinTask: (...args: unknown[]) => mockClaimCoinTask(...args),
  startCoinTask: (...args: unknown[]) => mockStartCoinTask(...args),
}));

jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'account-a',
  })),
}));

jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: (...args: unknown[]) =>
    mockOpenExternalUrlOnce(...args),
}));

jest.mock('../src/services/externalTaskUrlPolicy', () => ({
  trustedExternalTaskUrl: (value?: string) => value,
}));

jest.mock('../src/utils/errorPayload', () => ({
  learnerErrorMessage: (_error: unknown, fallback: string) => fallback,
}));

import type {CoinTask} from '../src/services/roknApi';
import {
  useWalletTasks,
  walletTaskActionLabel,
} from '../src/screens/wallet/useWalletTasks';

const readyWhatsAppTask = {
  id: 'production-12',
  serverId: '12',
  title: 'اربط واتسابك بركن',
  description: '',
  reward: 15,
  status: 'ready_to_claim',
  actionKey: 'link_whatsapp',
  requiresExternalVisit: true,
} as CoinTask;

const availableWhatsAppTask = {
  ...readyWhatsAppTask,
  status: 'available',
} as CoinTask;

const availableSocialTask = {
  ...readyWhatsAppTask,
  id: 'production-13',
  serverId: '13',
  title: 'تابع ركن',
  status: 'available',
  actionKey: 'follow_instagram',
} as CoinTask;

const availableGuideTask = {
  ...readyWhatsAppTask,
  id: 'production-14',
  serverId: '14',
  title: 'دليل العملات',
  status: 'available',
  actionKey: 'coin_guide',
  requiresExternalVisit: false,
} as CoinTask;

describe('wallet ready-to-claim task', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockClaimCoinTask.mockResolvedValue({
      balance: 115,
      amount: 15,
    });
  });

  it('labels and claims verified WhatsApp instead of reopening it', async () => {
    const refreshAfterCurrent = jest.fn(async () => undefined);
    const updateTask = jest.fn();
    let controller!: ReturnType<typeof useWalletTasks>;
    const Harness = () => {
      controller = useWalletTasks(
        {
          identityKey: 'account-a',
          ownsBoundary: () => true,
          refreshAfterCurrent,
          updateTask,
        },
        jest.fn(),
      );
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });

    expect(walletTaskActionLabel(readyWhatsAppTask, false)).toBe('استلام');
    await act(async () => {
      await controller.handleTask(readyWhatsAppTask);
    });

    expect(mockClaimCoinTask).toHaveBeenCalledTimes(1);
    expect(mockStartCoinTask).not.toHaveBeenCalled();
    expect(mockOpenExternalUrlOnce).not.toHaveBeenCalled();
    expect(updateTask).toHaveBeenCalledWith(readyWhatsAppTask.id, {
      status: 'claimed',
    });
    expect(refreshAfterCurrent).toHaveBeenCalledTimes(1);
    await act(async () => renderer.unmount());
  });

  it('keeps verified WhatsApp ready to claim without reopening its generic URL', async () => {
    mockStartCoinTask.mockResolvedValue({
      status: 'ready_to_claim',
      url: 'https://wa.me/201000000000',
    });
    const refreshAfterCurrent = jest.fn(async () => undefined);
    const updateTask = jest.fn();
    let controller!: ReturnType<typeof useWalletTasks>;
    const Harness = () => {
      controller = useWalletTasks(
        {
          identityKey: 'account-a',
          ownsBoundary: () => true,
          refreshAfterCurrent,
          updateTask,
        },
        jest.fn(),
      );
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      await controller.handleTask(availableWhatsAppTask);
    });

    expect(mockStartCoinTask).toHaveBeenCalledTimes(1);
    expect(updateTask).toHaveBeenCalledWith(availableWhatsAppTask.id, {
      status: 'ready_to_claim',
      url: 'https://wa.me/201000000000',
    });
    expect(mockClaimCoinTask).not.toHaveBeenCalled();
    expect(mockOpenExternalUrlOnce).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });

  it('opens a social destination when immediate verification returns ready with a URL', async () => {
    mockStartCoinTask.mockResolvedValue({
      status: 'ready_to_claim',
      url: 'https://instagram.com/rokn.app',
    });
    mockOpenExternalUrlOnce.mockResolvedValue(undefined);
    const updateTask = jest.fn();
    let controller!: ReturnType<typeof useWalletTasks>;
    const Harness = () => {
      controller = useWalletTasks(
        {
          identityKey: 'account-a',
          ownsBoundary: () => true,
          refreshAfterCurrent: jest.fn(async () => undefined),
          updateTask,
        },
        jest.fn(),
      );
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      await controller.handleTask(availableSocialTask);
    });

    expect(updateTask).toHaveBeenCalledWith(availableSocialTask.id, {
      status: 'ready_to_claim',
      url: 'https://instagram.com/rokn.app',
    });
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledWith(
      'https://instagram.com/rokn.app',
    );
    expect(mockClaimCoinTask).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });

  it('reopens a failed ready social destination instead of claiming it', async () => {
    const readySocialTask = {
      ...availableSocialTask,
      status: 'ready_to_claim',
      url: 'https://instagram.com/rokn.app',
    } as CoinTask;
    mockStartCoinTask.mockResolvedValue({
      status: 'ready_to_claim',
      url: readySocialTask.url,
    });
    mockOpenExternalUrlOnce
      .mockRejectedValueOnce(new Error('could not open'))
      .mockResolvedValueOnce(undefined);
    let controller!: ReturnType<typeof useWalletTasks>;
    const Harness = () => {
      controller = useWalletTasks(
        {
          identityKey: 'account-a',
          ownsBoundary: () => true,
          refreshAfterCurrent: jest.fn(async () => undefined),
          updateTask: jest.fn(),
        },
        jest.fn(),
      );
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      await controller.handleTask(availableSocialTask);
    });
    expect(controller.taskActionLabel(readySocialTask)).toBe('فتح');
    await act(async () => {
      await controller.handleTask(readySocialTask);
    });

    expect(mockStartCoinTask).toHaveBeenCalledTimes(2);
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledTimes(2);
    expect(mockClaimCoinTask).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });

  it('ignores an old opening retry once WhatsApp is verified', async () => {
    const startedWhatsAppTask = {
      ...availableWhatsAppTask,
      status: 'started',
      url: 'https://wa.me/201000000000',
    } as CoinTask;
    mockStartCoinTask.mockResolvedValue({
      status: 'started',
      url: startedWhatsAppTask.url,
    });
    mockOpenExternalUrlOnce.mockRejectedValueOnce(new Error('could not open'));
    let controller!: ReturnType<typeof useWalletTasks>;
    const Harness = () => {
      controller = useWalletTasks(
        {
          identityKey: 'account-a',
          ownsBoundary: () => true,
          refreshAfterCurrent: jest.fn(async () => undefined),
          updateTask: jest.fn(),
        },
        jest.fn(),
      );
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      await controller.handleTask(startedWhatsAppTask);
    });
    const verifiedWhatsAppTask = {
      ...readyWhatsAppTask,
      url: 'https://wa.me/201000000000',
    } as CoinTask;
    expect(controller.taskActionLabel(verifiedWhatsAppTask)).toBe('استلام');
    await act(async () => {
      await controller.handleTask(verifiedWhatsAppTask);
    });

    expect(mockStartCoinTask).toHaveBeenCalledTimes(1);
    expect(mockClaimCoinTask).toHaveBeenCalledTimes(1);
    expect(mockOpenExternalUrlOnce).toHaveBeenCalledTimes(1);
    await act(async () => renderer.unmount());
  });

  it('opens the coin guide after a ready start but not again while claiming', async () => {
    mockStartCoinTask.mockResolvedValue({
      status: 'ready_to_claim',
      url: undefined,
    });
    const showCoinRules = jest.fn();
    let controller!: ReturnType<typeof useWalletTasks>;
    const Harness = () => {
      controller = useWalletTasks(
        {
          identityKey: 'account-a',
          ownsBoundary: () => true,
          refreshAfterCurrent: jest.fn(async () => undefined),
          updateTask: jest.fn(),
        },
        showCoinRules,
      );
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      await controller.handleTask(availableGuideTask);
    });
    expect(showCoinRules).toHaveBeenCalledTimes(1);

    await act(async () => {
      await controller.handleTask({
        ...availableGuideTask,
        status: 'ready_to_claim',
      });
    });
    expect(mockClaimCoinTask).toHaveBeenCalledTimes(1);
    expect(showCoinRules).toHaveBeenCalledTimes(1);
    await act(async () => renderer.unmount());
  });
});
