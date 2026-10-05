export {};

type Boundary = {scope: string; epoch: number};
type Timer = {identifier: string; content: {data: {rokn_reminder_id: string}}};
let mockBoundary: Boundary = {scope: 'guest', epoch: 1};
let mockNative = true;
let mockEnabled = true;
let mockToken = '';
let mockReadGate: Promise<void> | undefined;
let mockScheduleGate: Promise<void> | undefined;
const mockTimers = new Map<number, Timer>();
const mockCancelGates = new Map<number, Promise<void>>();
const mockCancelFailures = new Set<number>();
const mockEvents: string[] = [];

const mockCancel = jest.fn(async (id: number) => {
  mockEvents.push(`cancel-start:${id}`);
  await mockCancelGates.get(id);
  if (mockCancelFailures.has(id)) throw new Error('OS_CANCEL_FAILED');
  mockTimers.delete(id);
  mockEvents.push(`cancel-end:${id}`);
});
const mockSchedule = jest.fn(async (id: number) => {
  mockEvents.push(`schedule-start:${id}`);
  await mockScheduleGate;
  mockTimers.set(id, {
    identifier: `timer-${id}`,
    content: {data: {rokn_reminder_id: `rokn-local-${id}`}},
  });
  mockEvents.push(`schedule-end:${id}`);
  return true;
});
const mockRead = jest.fn(async (key: string) => {
  if (key.startsWith('PREF_NOTIFICATIONS')) {
    const enabled = mockEnabled;
    const gate = mockReadGate;
    mockReadGate = undefined;
    await gate;
    return enabled;
  }
  return key === 'USER_DATA' ? {api_token: mockToken} : null;
});

jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
  NativeModules: {
    get RoknReminders() {
      return mockNative ? {schedule: mockSchedule, cancel: mockCancel} : undefined;
    },
  },
}));
jest.mock('expo-notifications', () => ({
  SchedulableTriggerInputTypes: {DATE: 'date'},
  getAllScheduledNotificationsAsync: async () => [...mockTimers.values()],
  cancelScheduledNotificationAsync: async (identifier: string) => {
    const id = Number(identifier.replace('timer-', ''));
    await mockCancel(id);
  },
  scheduleNotificationAsync: async (request: Timer) => {
    const id = Number(request.content.data.rokn_reminder_id.replace('rokn-local-', ''));
    await mockSchedule(id);
    return `timer-${id}`;
  },
}));
jest.mock('../src/constants/helpers', () => ({
  AsyncKeys: {USER_DATA: 'USER_DATA'},
  accountScopedStorageKey: async (key: string, boundary: Boundary) =>
    `${key}:${boundary.scope}`,
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: Boundary) => {
    if (boundary.scope !== mockBoundary.scope || boundary.epoch !== mockBoundary.epoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  extractApiToken: (session: {api_token?: string}) => session?.api_token || '',
  getItem: (key: string) => mockRead(key),
  saveItem: jest.fn(),
}));

const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(accept => {resolve = accept;});
  return {promise, resolve};
};
const drain = async () => {
  for (let index = 0; index < 60; index += 1) await Promise.resolve();
};

describe.each(['native', 'expo'])('%s local reminder mutation ownership', adapter => {
  let service: typeof import('../src/services/smartReminders');
  beforeEach(() => {
    jest.resetModules();
    mockNative = adapter === 'native';
    mockBoundary = {scope: 'guest', epoch: 1};
    mockEnabled = true;
    mockToken = '';
    mockReadGate = undefined;
    mockScheduleGate = undefined;
    mockTimers.clear();
    mockCancelGates.clear();
    mockCancelFailures.clear();
    mockEvents.length = 0;
    mockRead.mockClear();
    mockCancel.mockClear();
    mockSchedule.mockClear();
    service = require('../src/services/smartReminders');
  });

  it('does not recreate a timer whose preference read began before cancellation', async () => {
    const reading = deferred();
    mockReadGate = reading.promise;
    const scheduling = service.scheduleNextLearningReminder({courseId: '7'});
    await drain();
    expect(mockRead).toHaveBeenCalled();
    await service.cancelLearningReminders();
    reading.resolve();
    await expect(scheduling).resolves.toBe(false);
    expect(mockSchedule).not.toHaveBeenCalled();
    expect(mockTimers.size).toBe(0);
  });

  it('removes a timer which lands after opt-out and completes cancellation afterward', async () => {
    const os = deferred();
    mockScheduleGate = os.promise;
    const scheduling = service.scheduleNextLearningReminder({courseId: '7'});
    await drain();
    expect(mockSchedule.mock.calls[0][0]).toBe(8101);
    mockEnabled = false;
    let completed = false;
    const cancellation = service.cancelLearningReminders().then(() => {completed = true;});
    await drain();
    expect(completed).toBe(false);
    os.resolve();
    await expect(scheduling).resolves.toBe(false);
    await cancellation;
    expect(mockTimers.size).toBe(0);
  });

  it('finishes old account cleanup before allowing the new account timer to land', async () => {
    const os = deferred();
    mockScheduleGate = os.promise;
    const old = service.scheduleNextLearningReminder({courseId: '7'})
      .catch(error => error.message);
    await drain();
    expect(mockSchedule).toHaveBeenCalledTimes(1);
    mockBoundary = {scope: 'guest', epoch: 2};
    const cancellation = service.cancelLearningReminders();
    const next = service.scheduleNextLearningReminder({courseId: '8'});
    await drain();
    expect(mockSchedule).toHaveBeenCalledTimes(1);
    os.resolve();
    await expect(old).resolves.toBe('ACCOUNT_CHANGED_DURING_REQUEST');
    await cancellation;
    await expect(next).resolves.toBe(true);
    expect(mockTimers.has(8101)).toBe(true);
    expect(mockEvents[mockEvents.length - 1]).toBe('schedule-end:8101');
  });

  it('keeps the queue occupied until every cancellation settles even when one fails', async () => {
    await service.scheduleNextLearningReminder({courseId: '7'});
    await service.scheduleCoinRewardNotification({amount: 20});
    const os = deferred();
    mockCancelGates.set(8101, os.promise);
    mockCancelFailures.add(8103);
    const cancellation = service.cancelLearningReminders().catch(error => error.message);
    const next = service.scheduleNextLearningReminder({courseId: '8'});
    await drain();
    expect(mockSchedule).toHaveBeenCalledTimes(2);
    expect(mockEvents).toContain('cancel-start:8103');
    os.resolve();
    await expect(cancellation).resolves.toBe('OS_CANCEL_FAILED');
    await expect(next).resolves.toBe(true);
    expect(mockTimers.has(8101)).toBe(true);
  });

  it('rereads the saved opt-out before queued OS admission', async () => {
    await service.scheduleNextLearningReminder({courseId: '7'});
    mockSchedule.mockClear();
    const os = deferred();
    mockCancelGates.set(8101, os.promise);
    const cancellation = service.cancelLearningReminders();
    const scheduling = service.scheduleNextLearningReminder({courseId: '7'});
    await drain();
    mockEnabled = false;
    os.resolve();
    await cancellation;
    await expect(scheduling).resolves.toBe(false);
    expect(mockSchedule).not.toHaveBeenCalled();
  });

  it('does not cancel a preview or unrelated provider notification', async () => {
    mockTimers.set(8191, {
      identifier: 'timer-8191', content: {data: {rokn_reminder_id: 'rokn-local-8191'}},
    });
    mockTimers.set(9101, {
      identifier: 'timer-9101', content: {data: {rokn_reminder_id: 'provider-8101'}},
    });
    await service.cancelLearningReminders();
    expect(mockTimers.has(8191)).toBe(true);
    expect(mockTimers.has(9101)).toBe(true);
  });

  it('keeps authenticated learning scheduling exclusively on the backend', async () => {
    mockToken = 'authenticated';
    await expect(service.scheduleNextLearningReminder({courseId: '7'})).resolves.toBe(false);
    expect(mockSchedule).not.toHaveBeenCalled();
  });
});
