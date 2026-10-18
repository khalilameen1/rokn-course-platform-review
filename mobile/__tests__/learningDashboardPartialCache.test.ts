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

import {
  getLearningDashboard,
  type LearningDashboard,
} from '../src/services/api/learning';

describe('learning dashboard partial read', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
  });

  const previous = {
    courses: [{id: '1', title: 'قديم', progress: 20, completedSections: 1, totalSections: 5, category: 'freelance', accessType: 'paid', chatAvailable: false, certificateAvailable: true}],
    paths: [{id: '8', title: 'المسار', upcomingLevels: [], progress: 40, remainingToNextLevel: 60, completedSections: 2, totalSections: 5}],
    badges: [{id: '9', title: 'شارة'}],
    activityDays: ['2026-09-01'],
    currentStreakDays: 3,
  } as unknown as LearningDashboard;

  it('keeps the last known secondary panels instead of partial empties', async () => {
    mockGetLearningCourses.mockResolvedValue([
      {id: '2', title: 'حديث', progress: 10, completedSections: 1, totalSections: 10, category: 'technology'},
    ]);
    mockGet.mockRejectedValue(new Error('secondary endpoint unavailable'));
    const delivered = jest.fn();

    await expect(
      getLearningDashboard({previous, onCoursesReady: delivered}),
    ).resolves.toMatchObject({
      courses: [{id: '2', title: 'حديث'}],
      paths: previous.paths,
      badges: previous.badges,
      activityDays: previous.activityDays,
      currentStreakDays: 3,
      partialError: expect.stringContaining('نعرض آخر نسخة متاحة'),
    });
    // Courses are published before the secondary panels settle.
    expect(delivered).toHaveBeenCalledWith([
      expect.objectContaining({id: '2'}),
    ]);
    // The read itself never writes device storage; the query snapshot does.
    await expect(AsyncStorage.getAllKeys()).resolves.toEqual([]);
  });

  it('rejects before delivering courses when the learning library fails', async () => {
    mockGetLearningCourses.mockRejectedValue(new Error('offline'));
    mockGet.mockResolvedValue({data: {data: {}}});
    const delivered = jest.fn();
    await expect(
      getLearningDashboard({previous, onCoursesReady: delivered}),
    ).rejects.toThrow('offline');
    expect(delivered).not.toHaveBeenCalled();
  });
});
