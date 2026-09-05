const mockSchedule = jest.fn(async (..._args: unknown[]) => true);
const mockToken = jest.fn(() => '');
jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
  NativeModules: {
    RoknReminders: {schedule: (...args: unknown[]) => mockSchedule(...args)},
  },
}));
jest.mock('expo-notifications', () => ({}));
jest.mock('../src/constants/helpers', () => ({
  AsyncKeys: {USER_DATA: 'user'},
  accountScopedStorageKey: async (key: string) => key,
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: async () => ({scope: 'guest', epoch: 1}),
  extractApiToken: () => mockToken(),
  getItem: async (key: string) => (key === 'PREF_NOTIFICATIONS' ? true : null),
  saveItem: jest.fn(),
}));

import {scheduleNextLearningReminder} from '../src/services/smartReminders';
import {cleanUnicodeText} from '../src/utils/unicodeText';

describe('local learning reminder authored content', () => {
  beforeEach(() => {
    mockSchedule.mockClear();
    mockToken.mockReturnValue('');
  });

  it('keeps course and lesson titles unchanged in the actual OS scheduling call', async () => {
    await expect(
      scheduleNextLearningReminder({
        courseId: '7',
        courseTitle: 'ريلز 2026',
        nextReelTitle: 'Blender Studio 4',
      }),
    ).resolves.toBe(true);
    const [id, , body, , courseId, link] = mockSchedule.mock.calls[0];
    expect(id).toBe(8101);
    expect(cleanUnicodeText(body)).toBe(
      'ريلز 2026\nتوقفت عند Blender Studio 4\nأكمل عندما يناسبك',
    );
    expect(courseId).toBe('7');
    expect(link).toBe('rokn://course/7/watch');
  });

  it('continues to localize the app-owned streak count', async () => {
    await scheduleNextLearningReminder({courseId: '7', streakDays: 3});
    expect(mockSchedule.mock.calls[0][2]).toBe(
      'مقطع واحد يحافظ على استمرارية ٣ أيام',
    );
  });

  it('does not duplicate the server reminder for authenticated learners', async () => {
    mockToken.mockReturnValue('test-session');
    await expect(
      scheduleNextLearningReminder({courseId: '7', courseTitle: 'ريلز 2026'}),
    ).resolves.toBe(false);
    expect(mockSchedule).not.toHaveBeenCalled();
  });
});
