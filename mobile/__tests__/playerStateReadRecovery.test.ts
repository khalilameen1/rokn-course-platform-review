let mockAccountEpoch = 1;

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string, boundary?: {scope: string}) =>
      `${key}:${boundary?.scope || 'user-account-a'}`,
  ),
  assertAccountSessionBoundary: jest.fn((boundary: {epoch: number}) => {
    if (boundary.epoch !== mockAccountEpoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  }),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: mockAccountEpoch,
    scope: 'user-account-a',
  })),
  getCurrentAccountStorageScope: jest.fn(async () => 'user-account-a'),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {readPlayerState} from '../src/components/VideoPlayer/courseLearning/persistence';

const KEY = '@rokn/course-player/v3:user-account-a';
const boundary = {epoch: 1, scope: 'user-account-a'} as const;

const oversizedState = () => {
  const positions: Record<string, number> = {};
  const lastWatchedAt: Record<string, string> = {};
  for (let index = 0; index < 301; index += 1) {
    const key = `lesson-${index}`;
    positions[key] = index;
    lastWatchedAt[key] = new Date(
      Date.UTC(2026, 0, 1, 0, 0, index),
    ).toISOString();
  }
  return {
    positions,
    lastWatchedAt,
    completedSections: ['section-1'],
    savedLessons: [],
    savedFolderLessons: {},
    activityDays: [],
  };
};

describe('player state read recovery', () => {
  beforeEach(async () => {
    mockAccountEpoch = 1;
    await AsyncStorage.clear();
    jest.restoreAllMocks();
  });

  it('returns the valid compacted snapshot when its maintenance write fails', async () => {
    await AsyncStorage.setItem(KEY, JSON.stringify(oversizedState()));
    const originalSetItem = (
      AsyncStorage.setItem as jest.Mock
    ).getMockImplementation();
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockRejectedValue(new Error('DEVICE_FULL'));

    try {
      const state = await readPlayerState(undefined, boundary);
      expect(Object.keys(state.positions)).toHaveLength(300);
      expect(state.completedSections).toEqual(['section-1']);
    } finally {
      (AsyncStorage.setItem as jest.Mock).mockImplementation(originalSetItem);
    }
  });

  it('does not disguise an account switch during compaction as empty state', async () => {
    await AsyncStorage.setItem(KEY, JSON.stringify(oversizedState()));
    const originalSetItem = (
      AsyncStorage.setItem as jest.Mock
    ).getMockImplementation();
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementation(async (key: string, value: string) => {
        const result = await originalSetItem?.(key, value);
        mockAccountEpoch = 2;
        return result;
      });

    try {
      await expect(readPlayerState(undefined, boundary)).rejects.toThrow(
        'ACCOUNT_CHANGED_DURING_REQUEST',
      );
    } finally {
      (AsyncStorage.setItem as jest.Mock).mockImplementation(originalSetItem);
    }
  });

  it('drops device-only saved aliases while preserving remote memberships', async () => {
    await AsyncStorage.setItem(
      KEY,
      JSON.stringify({
        ...oversizedState(),
        savedLessons: ['44', 'local-demo', 55],
        savedFolderLessons: {
          '7': ['44', '45', 'local-demo'],
          'local-watch-later': ['46'],
        },
      }),
    );

    const state = await readPlayerState(undefined, boundary);

    expect(state.savedFolderLessons).toEqual({'7': ['44', '45']});
    expect(state.savedLessons).toEqual(['44', '45']);
  });
});
