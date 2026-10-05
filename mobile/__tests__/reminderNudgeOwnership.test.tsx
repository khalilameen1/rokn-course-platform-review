import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {
  AppState,
  type AppStateStatus,
  Linking,
  Text,
} from 'react-native';

let mockForeground = true;
let mockAuthenticated = true;
let mockRenderPrimer = false;
let mockBoundary = {scope: 'learner-a', epoch: 1};
const mockGetEnabled = jest.fn();
const mockGetSeen = jest.fn();
const mockSaveSeen = jest.fn();
const mockEnable = jest.fn();
const mockSetEnabled = jest.fn();
const mockUpdate = jest.fn();
const mockRegister = jest.fn();
const mockSchedule = jest.fn();

jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => mockForeground,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
  getItem: (...args: unknown[]) => mockGetSeen(...args),
  saveItem: (...args: unknown[]) => mockSaveSeen(...args),
}));
jest.mock('../src/services/smartReminders', () => ({
  areSmartRemindersSupported: () => true,
  getSmartRemindersEnabled: (...args: unknown[]) => mockGetEnabled(...args),
  enableSmartReminders: (...args: unknown[]) => mockEnable(...args),
  setSmartRemindersEnabled: (...args: unknown[]) => mockSetEnabled(...args),
  scheduleNextLearningReminder: (...args: unknown[]) => mockSchedule(...args),
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => mockAuthenticated,
  updateNotificationStatus: (...args: unknown[]) => mockUpdate(...args),
}));
jest.mock('../src/services/pushDeviceRegistration', () => ({
  registerPushDeviceIfEligible: (...args: unknown[]) => mockRegister(...args),
}));

jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 12, bottom: 8, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('react-native-svg', () => ({
  __esModule: true,
  default: 'Svg',
  Circle: 'Circle',
  Path: 'Path',
}));
jest.mock('../src/assets/SVG', () => ({MoreBellIcon: () => null}));
jest.mock('../src/components/ui/RoknCoin', () => ({RoknCoinStack: () => null}));

import {useReminderNudge} from '../src/screens/reels/useReminderNudge';
import NotificationPermissionPrimer from '../src/components/ui/NotificationPermissionPrimer';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('optional reminder presentation owns its route and account', () => {
  const originalNativeState = AppState.currentState;
  let input: Parameters<typeof useReminderNudge>[0];
  let api!: ReturnType<typeof useReminderNudge>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Harness = () => {
    api = useReminderNudge(input);
    return mockRenderPrimer ? (
      <NotificationPermissionPrimer
        visible={api.reminderNudgeVisible}
        onEnable={api.enableRemindersFromNudge}
        onClose={api.closeReminderNudge}
      />
    ) : null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  const render = async (next: Partial<typeof input> = {}) => {
    input = {...input, ...next};
    await act(async () => {
      renderer!.update(<Harness />);
    });
  };
  const offer = async () => {
    await act(async () => {
      api.maybeOfferReminders();
    });
  };

  beforeEach(() => {
    jest.resetAllMocks();
    mockForeground = true;
    mockAuthenticated = true;
    mockRenderPrimer = false;
    AppState.currentState = 'active';
    mockBoundary = {scope: 'learner-a', epoch: 1};
    input = {
      active: true,
      blocked: false,
      contextKey: 'reel-1:settled',
      scopeKey: 'learner-a:course-1:learning',
      courseId: 'course-1',
      courseTitle: 'كورس',
    };
    mockGetEnabled.mockResolvedValue(false);
    mockGetSeen.mockResolvedValue(null);
    mockSaveSeen.mockResolvedValue(true);
    mockEnable.mockResolvedValue(true);
    mockSetEnabled.mockResolvedValue(true);
    mockUpdate.mockImplementation(async enabled => enabled);
    mockRegister.mockResolvedValue(true);
    mockSchedule.mockResolvedValue(true);
  });
  afterEach(async () => {
    if (renderer)
      await act(async () => {
        renderer!.unmount();
      });
    renderer = undefined;
    AppState.currentState = originalNativeState;
    jest.restoreAllMocks();
  });

  it('joins repeated offers while reading and presents once without requesting OS permission', async () => {
    const read = deferred<boolean>();
    mockGetEnabled.mockReturnValueOnce(read.promise);
    await mount();
    await offer();
    await offer();
    expect(mockGetEnabled).toHaveBeenCalledTimes(1);
    expect(api.reminderNudgeVisible).toBe(false);
    await act(async () => {
      read.resolve(false);
    });
    expect(api.reminderNudgeVisible).toBe(true);
    await offer();
    expect(mockGetEnabled).toHaveBeenCalledTimes(1);
    expect(mockEnable).not.toHaveBeenCalled();
    expect(mockUpdate).not.toHaveBeenCalled();
  });

  it.each(['covered route', 'another overlay', 'background'])(
    'does not start an optional read for %s',
    async reason => {
      if (reason === 'covered route') input.active = false;
      if (reason === 'another overlay') input.blocked = true;
      if (reason === 'background') mockForeground = false;
      await mount();
      await offer();
      expect(mockGetEnabled).not.toHaveBeenCalled();
      expect(api.reminderNudgeVisible).toBe(false);
    },
  );

  it.each(['covered route', 'another overlay', 'background'])(
    'drops a pending offer when %s intervenes instead of draining it later',
    async reason => {
      const read = deferred<boolean>();
      mockGetEnabled.mockReturnValueOnce(read.promise);
      await mount();
      await offer();
      if (reason === 'background') mockForeground = false;
      await render(
        reason === 'covered route'
          ? {active: false}
          : reason === 'another overlay'
          ? {blocked: true}
          : {},
      );
      mockForeground = true;
      await render({active: true, blocked: false});
      await act(async () => {
        read.resolve(false);
      });
      expect(api.reminderNudgeVisible).toBe(false);
      await offer();
      expect(api.reminderNudgeVisible).toBe(true);
    },
  );

  it.each(['already enabled', 'already dismissed'])(
    'does not present when %s',
    async reason => {
      if (reason === 'already enabled') mockGetEnabled.mockResolvedValue(true);
      else mockGetSeen.mockResolvedValue(true);
      await mount();
      await offer();
      expect(api.reminderNudgeVisible).toBe(false);
    },
  );

  it('permits a later real completion after an optional read failed', async () => {
    mockGetEnabled.mockRejectedValueOnce(new Error('storage unavailable'));
    await mount();
    await offer();
    expect(api.reminderNudgeVisible).toBe(false);
    await offer();
    expect(api.reminderNudgeVisible).toBe(true);
  });

  it('retires account reads and callbacks rather than closing the next account presentation', async () => {
    const read = deferred<boolean>();
    mockGetEnabled.mockReturnValueOnce(read.promise);
    await mount();
    const old = api;
    await offer();
    mockBoundary = {scope: 'learner-b', epoch: 2};
    await render({scopeKey: 'learner-b:course-1:learning'});
    await offer();
    expect(api.reminderNudgeVisible).toBe(true);
    await act(async () => {
      read.resolve(false);
      old.maybeOfferReminders();
      old.closeReminderNudge();
    });
    expect(api.reminderNudgeVisible).toBe(true);
    expect(mockSaveSeen).not.toHaveBeenCalled();
    expect(mockGetEnabled).toHaveBeenCalledTimes(2);
  });

  it('hides an existing primer when its route is covered without a queued redisplay', async () => {
    await mount();
    await offer();
    const old = api;
    await render({active: false});
    expect(api.reminderNudgeVisible).toBe(false);
    await render({active: true});
    await offer();
    await act(async () => {
      old.closeReminderNudge();
    });
    expect(api.reminderNudgeVisible).toBe(false);
    expect(mockSaveSeen).not.toHaveBeenCalled();
  });

  it('persists dismissal for the displayed boundary without changing notification preferences', async () => {
    await mount();
    await offer();
    await act(async () => {
      api.closeReminderNudge();
    });
    expect(api.reminderNudgeVisible).toBe(false);
    expect(mockSaveSeen).toHaveBeenCalledWith(
      '@rokn/reminders/nudge-seen/v1:learner-a',
      true,
    );
    expect(mockUpdate).not.toHaveBeenCalled();
    expect(mockEnable).not.toHaveBeenCalled();
  });

  it('joins double activation and retains the primer during OS permission/settings backgrounding', async () => {
    const permission = deferred<boolean>();
    mockEnable.mockReturnValueOnce(permission.promise);
    await mount();
    await offer();
    let first!: Promise<boolean>;
    let second!: Promise<boolean>;
    await act(async () => {
      first = api.enableRemindersFromNudge();
      second = api.enableRemindersFromNudge();
    });
    expect(first).toBe(second);
    expect(mockEnable).toHaveBeenCalledTimes(1);
    mockForeground = false;
    await render();
    expect(api.reminderNudgeVisible).toBe(true);
    mockForeground = true;
    await render();
    await act(async () => {
      permission.resolve(true);
      await first;
    });
    expect(await first).toBe(true);
    expect(mockUpdate).toHaveBeenCalledWith(true, {
      scope: 'learner-a',
      epoch: 1,
    });
    expect(mockRegister).toHaveBeenCalledWith({
      requestPermission: false,
      ownerBoundary: mockBoundary,
    });
    expect(mockSchedule).toHaveBeenCalledWith(
      {courseId: 'course-1', courseTitle: 'كورس'},
      mockBoundary,
    );
  });

  it('does not persist settings from an OS result after the account was replaced', async () => {
    const permission = deferred<boolean>();
    mockEnable.mockReturnValueOnce(permission.promise);
    await mount();
    await offer();
    let result!: Promise<boolean>;
    await act(async () => {
      result = api.enableRemindersFromNudge();
    });
    const failure = result.catch(error => error);
    mockBoundary = {scope: 'learner-b', epoch: 2};
    await render({
      scopeKey: 'learner-b:course-2:learning',
      courseId: 'course-2',
    });
    await act(async () => {
      permission.resolve(true);
      await failure;
    });
    expect((await failure).message).toBe('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(mockSetEnabled).not.toHaveBeenCalled();
    expect(mockUpdate).not.toHaveBeenCalled();
    expect(mockRegister).not.toHaveBeenCalled();
  });

  it.each(['project-2:settled', 'reel-2:settled', 'reel-1:paging'])(
    'retires a pending completion read when feed context changes to %s',
    async contextKey => {
      const read = deferred<boolean>();
      mockGetEnabled.mockReturnValueOnce(read.promise);
      await mount();
      await offer();
      await render({contextKey});
      await render({contextKey: 'reel-1:settled'});
      await act(async () => {
        read.resolve(false);
      });
      expect(api.reminderNudgeVisible).toBe(false);
      await offer();
      expect(api.reminderNudgeVisible).toBe(true);
    },
  );

  it('finishes account activation after focus loss while token registration is pending', async () => {
    const token = deferred<boolean>();
    mockRegister.mockReturnValueOnce(token.promise);
    await mount();
    await offer();
    let result!: Promise<boolean>;
    await act(async () => {
      result = api.enableRemindersFromNudge();
    });
    expect(mockUpdate).toHaveBeenCalledWith(true, mockBoundary);
    await render({active: false});
    await act(async () => {
      token.resolve(true);
      await result;
    });
    expect(await result).toBe(true);
    expect(mockSchedule).toHaveBeenCalledWith(
      {courseId: 'course-1', courseTitle: 'كورس'},
      mockBoundary,
    );
    expect(api.reminderNudgeVisible).toBe(false);
  });

  it('compensates a failed server write after local enable even if the route became covered', async () => {
    const remote = deferred<boolean>();
    mockUpdate.mockReturnValueOnce(remote.promise);
    await mount();
    await offer();
    let result!: Promise<boolean>;
    await act(async () => {
      result = api.enableRemindersFromNudge();
    });
    expect(mockSetEnabled).toHaveBeenCalledWith(true, mockBoundary);
    await render({active: false});
    // A negative server acknowledgement is the same failed activation path as
    // rejection, without creating an unhandled promise in this timing fixture.
    const failure = result.catch(error => error);
    await act(async () => {
      remote.resolve(false);
      await failure;
    });
    expect((await failure).message).toBe(
      'NOTIFICATION_PREFERENCE_NOT_CONFIRMED',
    );
    expect(mockSetEnabled).toHaveBeenLastCalledWith(false, mockBoundary);
    expect(mockUpdate).toHaveBeenLastCalledWith(false, mockBoundary);
    expect(mockRegister).not.toHaveBeenCalled();
    expect(api.reminderNudgeVisible).toBe(false);
  });

  it('accepts settings return from native AppState before the foreground hook rerenders', async () => {
    await mount();
    await offer();
    mockForeground = false;
    AppState.currentState = 'background';
    await render();
    // NotificationPermissionPrimer's native listener sees active first.
    AppState.currentState = 'active';
    await act(async () => {
      expect(await api.enableRemindersFromNudge()).toBe(true);
    });
    expect(mockEnable).toHaveBeenCalledTimes(1);
    expect(mockRegister).toHaveBeenCalledTimes(1);
  });

  it('keeps the existing server/token compensation and permits retry after a failed activation', async () => {
    const error = new Error('server unavailable');
    mockUpdate.mockRejectedValueOnce(error);
    await mount();
    await offer();
    let failure: unknown;
    await act(async () => {
      failure = await api.enableRemindersFromNudge().catch(caught => caught);
    });
    expect(failure).toBe(error);
    expect(mockSetEnabled).toHaveBeenLastCalledWith(false, mockBoundary);
    expect(mockUpdate).toHaveBeenLastCalledWith(false, mockBoundary);
    expect(mockRegister).not.toHaveBeenCalled();
    expect(api.reminderNudgeVisible).toBe(true);
    await act(async () => {
      expect(await api.enableRemindersFromNudge()).toBe(true);
    });
    expect(mockRegister).toHaveBeenCalledTimes(1);
  });

  it('resolves false only for real OS denial without writing preferences', async () => {
    mockEnable.mockResolvedValueOnce(false);
    await mount();
    await offer();
    await act(async () => {
      expect(await api.enableRemindersFromNudge()).toBe(false);
    });
    expect(mockSetEnabled).not.toHaveBeenCalled();
    expect(mockUpdate).not.toHaveBeenCalled();
    expect(mockRegister).not.toHaveBeenCalled();
    expect(mockSchedule).not.toHaveBeenCalled();
  });

  it('rejects a local write failure instead of claiming activation and permits retry', async () => {
    mockSetEnabled.mockResolvedValueOnce(false);
    await mount();
    await offer();
    let failure: unknown;
    await act(async () => {
      failure = await api.enableRemindersFromNudge().catch(error => error);
    });
    expect(failure).toEqual(
      new Error('NOTIFICATION_PREFERENCE_STORAGE_FAILED'),
    );
    expect(mockUpdate).not.toHaveBeenCalled();
    expect(mockRegister).not.toHaveBeenCalled();
    expect(mockSchedule).not.toHaveBeenCalled();
    await act(async () => {
      expect(await api.enableRemindersFromNudge()).toBe(true);
    });
    expect(mockRegister).toHaveBeenCalledTimes(1);
  });

  it('does not describe an unavailable presentation as denied OS permission', async () => {
    await mount();
    await act(async () => {
      await expect(api.enableRemindersFromNudge()).rejects.toThrow(
        'REMINDER_PRESENTATION_RETIRED',
      );
    });
    expect(mockEnable).not.toHaveBeenCalled();
    expect(mockSetEnabled).not.toHaveBeenCalled();
  });

  it('binds guest settings return to primer retry on failed storage and closes only after successful retry', async () => {
    const listeners = new Set<(state: AppStateStatus) => void>();
    jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((_event, listener) => {
        listeners.add(listener);
        return {
          remove: () => {
            listeners.delete(listener);
          },
        };
      });
    jest.spyOn(Linking, 'openSettings').mockResolvedValue(undefined);
    mockAuthenticated = false;
    mockRenderPrimer = true;
    await mount();
    await offer();
    const press = async (label: string) => {
      const button = renderer!.root
        // Find the actual accessible CTA, not RN's memoized export identity.
        .findAllByProps({accessibilityRole: 'button'})
        .find(node =>
          node.findAllByType(Text).some(text => text.props.children === label),
        )!;
      await act(async () => {
        button.props.onPress();
      });
    };
    mockEnable.mockResolvedValueOnce(false);
    await press('فعّل الإشعارات');
    await press('فتح إعدادات الهاتف');
    mockSetEnabled.mockResolvedValueOnce(false);
    AppState.currentState = 'background';
    await act(async () => {
      for (const listener of Array.from(listeners)) listener('background');
    });
    mockForeground = false;
    await render();
    // Native return precedes the foreground hook update on real devices.
    AppState.currentState = 'active';
    await act(async () => {
      for (const listener of Array.from(listeners)) listener('active');
    });
    expect(
      renderer!.root.findAllByType(Text).map(node => node.props.children),
    ).toContain('تعذّر تفعيل الإشعارات');
    expect(api.reminderNudgeVisible).toBe(true);
    expect(mockUpdate).not.toHaveBeenCalled();
    expect(mockRegister).not.toHaveBeenCalled();
    expect(mockSchedule).not.toHaveBeenCalled();
    mockForeground = true;
    await render();
    await press('حاول مرة أخرى');
    expect(mockSetEnabled).toHaveBeenCalledTimes(2);
    expect(mockSetEnabled).toHaveBeenLastCalledWith(true, mockBoundary);
    expect(mockSchedule).toHaveBeenCalledTimes(1);
    expect(api.reminderNudgeVisible).toBe(false);
    expect(mockSaveSeen).toHaveBeenCalledTimes(1);
    expect(mockUpdate).not.toHaveBeenCalled();
    expect(mockRegister).not.toHaveBeenCalled();
    expect(Linking.openSettings).toHaveBeenCalledTimes(1);
  });
});
