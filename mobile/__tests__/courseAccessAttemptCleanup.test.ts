import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  clearCoursePurchaseAttemptKey,
  clearCourseUpgradeAttemptKey,
  getOrCreateCoursePurchaseAttemptKey,
  getOrCreateCourseUpgradeAttemptKey,
} from '../src/services/api/courseAccessAttemptStore';

const mockRecords = new Map<string, string>();
let mockEpoch = 1;
jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: jest.fn(async (key: string) => mockRecords.get(key) ?? null),
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(async (key: string) => `${key}:learner`),
  assertAccountSessionBoundary: jest.fn((boundary: {epoch: number}) => {
    if (boundary.epoch !== mockEpoch) throw new Error('ACCOUNT_CHANGED');
  }),
  saveItem: jest.fn(async (key: string, value: unknown) => {
    mockRecords.set(key, JSON.stringify(value));
    return true;
  }),
  removeItem: jest.fn(async (key: string) => mockRecords.delete(key)),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => '11111111-1111-4111-8111-111111111111',
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));

describe('confirmed course access cleanup', () => {
  const boundary = {epoch: 1, scope: 'learner'};
  const purchase = {courseId: 42, accessPlanCode: 'guided', couponCode: ''};
  const upgrade = {courseId: 42, targetPlanCode: 'mentor', expectedPrice: 100};
  const cases = [
    {
      name: 'purchase',
      start: () => getOrCreateCoursePurchaseAttemptKey(purchase, boundary),
      clear: (key: string) =>
        clearCoursePurchaseAttemptKey(purchase, key, boundary),
    },
    {
      name: 'upgrade',
      start: () => getOrCreateCourseUpgradeAttemptKey(upgrade, boundary),
      clear: (key: string) =>
        clearCourseUpgradeAttemptKey(upgrade, key, boundary),
    },
  ];

  beforeEach(() => {
    mockRecords.clear();
    mockEpoch = 1;
    jest.clearAllMocks();
  });

  it.each(cases)(
    'does not replace a confirmed $name with a storage error',
    async entry => {
      const identity = await entry.start();
      (AsyncStorage.getItem as jest.Mock).mockRejectedValueOnce(
        new Error('disk I/O'),
      );
      await expect(entry.clear(identity)).resolves.toBeUndefined();
      expect(await entry.start()).toBe(identity);
      expect(mockRecords.size).toBe(1);
      await entry.clear(identity);
      expect(mockRecords.size).toBe(0);
    },
  );

  it.each(cases)(
    'never adopts an old $name after the account changes during cleanup',
    async entry => {
      const identity = await entry.start();
      (AsyncStorage.getItem as jest.Mock).mockImplementationOnce(async () => {
        mockEpoch = 2;
        throw new Error('disk I/O');
      });
      await expect(entry.clear(identity)).rejects.toThrow('ACCOUNT_CHANGED');
    },
  );
});
