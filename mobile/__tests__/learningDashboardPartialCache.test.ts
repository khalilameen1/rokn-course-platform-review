import AsyncStorage from '@react-native-async-storage/async-storage';

const mockGet = jest.fn();
const mockGetLearningCourses = jest.fn();
const mockPeekSession = jest.fn(() => ({
  ready: true,
  session: {user: {id: 44}, api_token: 'user-token'},
  epoch: 7,
}));

jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
  digestStringAsync: jest.fn(async () => 'a'.repeat(64)),
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/services/api/courses', () => ({
  getLearningCourses: (...args: unknown[]) => mockGetLearningCourses(...args),
}));
jest.mock('../src/services/secureSession', () => {
  const actual = jest.requireActual('../src/services/secureSession');
  return {...actual, peekSecureSession: () => mockPeekSession()};
});

import {getLearningDashboard} from '../src/services/api/learning';

describe('learning dashboard partial cache', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
  });

  it('keeps the last complete secondary panels and does not overwrite that cache with partial empties', async () => {
    const cacheKey = `@rokn/learning-dashboard/v3:user-${'a'.repeat(24)}`;
    const cached = {
      version: 3,
      savedAt: Date.now(),
      dashboard: {
        courses: [{id: '1', title: 'قديم', progress: 20, completedSections: 1, totalSections: 5, category: 'freelance', accessType: 'paid', chatAvailable: false, certificateAvailable: true}],
        paths: [{id: '8', title: 'المسار', upcomingLevels: [], progress: 40, remainingToNextLevel: 60, completedSections: 2, totalSections: 5}],
        badges: [{id: '9', title: 'شارة'}],
        activityDays: ['2026-09-01'],
        currentStreakDays: 3,
      },
    };
    const serialized = JSON.stringify(cached);
    await AsyncStorage.setItem(cacheKey, serialized);
    mockGetLearningCourses.mockResolvedValue([
      {id: '2', title: 'حديث', progress: 10, completedSections: 1, totalSections: 10, category: 'technology'},
    ]);
    mockGet.mockRejectedValue(new Error('secondary endpoint unavailable'));

    await expect(getLearningDashboard()).resolves.toMatchObject({
      courses: [{id: '2', title: 'حديث'}],
      paths: cached.dashboard.paths,
      badges: cached.dashboard.badges,
      activityDays: cached.dashboard.activityDays,
      currentStreakDays: 3,
      partialError: expect.any(String),
    });
    await expect(AsyncStorage.getItem(cacheKey)).resolves.toBe(serialized);
  });
});
