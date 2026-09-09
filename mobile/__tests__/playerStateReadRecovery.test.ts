let mockAccountEpoch = 1;

jest.mock('../src/components/VideoPlayer/courseLearning/playback', () => ({
  resetPlaybackRuntimeState: jest.fn(),
}));
jest.mock(
  '../src/components/VideoPlayer/courseLearning/projectSubmissionOutbox',
  () => ({quiesceProjectSubmissionRuntime: jest.fn()}),
);
jest.mock('../src/services/learnerDraftFiles', () => ({
  clearAccountLearnerDraftFiles: jest.fn(),
}));

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
import {quiesceLearningRuntime} from '../src/components/VideoPlayer/courseLearning/learningRuntime';
import {
  clearLocalWatchHistory,
  migrateGuestLearningState,
  readPlayerState,
  updatePlayerState,
} from '../src/components/VideoPlayer/courseLearning/persistence';

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

  it('returns a compacted display without writing during the read', async () => {
    await AsyncStorage.setItem(KEY, JSON.stringify(oversizedState()));
    const originalSetItem = (
      AsyncStorage.setItem as jest.Mock
    ).getMockImplementation();
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockClear()
      .mockRejectedValue(new Error('DEVICE_FULL'));

    try {
      const state = await readPlayerState(undefined, boundary);
      expect(Object.keys(state.positions)).toHaveLength(300);
      expect(state.completedSections).toEqual(['section-1']);
      expect(AsyncStorage.setItem).not.toHaveBeenCalled();
    } finally {
      (AsyncStorage.setItem as jest.Mock).mockImplementation(originalSetItem);
    }
  });

  it('does not disguise an account switch during reading as empty state', async () => {
    await AsyncStorage.setItem(KEY, JSON.stringify(oversizedState()));
    const originalGetItem = (
      AsyncStorage.getItem as jest.Mock
    ).getMockImplementation();
    jest
      .spyOn(AsyncStorage, 'getItem')
      .mockImplementation(async (key: string) => {
        const result = await originalGetItem?.(key);
        mockAccountEpoch = 2;
        return result;
      });

    try {
      await expect(readPlayerState(undefined, boundary)).rejects.toThrow(
        'ACCOUNT_CHANGED_DURING_REQUEST',
      );
    } finally {
      (AsyncStorage.getItem as jest.Mock).mockImplementation(originalGetItem);
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

  it.each(['bookmark update', 'history clear'])(
    'does not replace unreadable durable data during %s',
    async action => {
      const original = JSON.stringify({
        ...oversizedState(),
        savedLessons: ['44'],
      });
      await AsyncStorage.setItem(KEY, original);
      const get = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;
      const set = jest.spyOn(AsyncStorage, 'setItem');
      set.mockClear();
      jest
        .spyOn(AsyncStorage, 'getItem')
        .mockRejectedValueOnce(new Error('LOCAL_READ_FAILED'));
      await expect(
        action === 'history clear'
          ? clearLocalWatchHistory(boundary)
          : updatePlayerState(
              state => ({
                ...state,
                savedLessons: [...state.savedLessons, '55'],
              }),
              undefined,
              boundary,
            ),
      ).rejects.toThrow('LOCAL_READ_FAILED');
      expect(set).not.toHaveBeenCalled();
      expect(await get(KEY)).toBe(original);
    },
  );

  it('keeps display usable without writing when the local read fails', async () => {
    jest.mocked(AsyncStorage.setItem).mockClear();
    jest
      .spyOn(AsyncStorage, 'getItem')
      .mockRejectedValueOnce(new Error('LOCAL_READ_FAILED'));

    const state = await readPlayerState(undefined, boundary);

    expect(state.positions).toEqual({});
    expect(state.savedLessons).toEqual([]);
    expect(AsyncStorage.setItem).not.toHaveBeenCalled();
  });

  it('starts a new record only after reading that it is absent', async () => {
    const next = await updatePlayerState(
      state => ({...state, savedLessons: ['55']}),
      undefined,
      boundary,
    );

    expect(next.savedLessons).toEqual(['55']);
    expect(JSON.parse((await AsyncStorage.getItem(KEY))!)).toEqual(next);
  });

  it('does not overwrite malformed durable JSON with the display fallback', async () => {
    const original = '{"positions":';
    await AsyncStorage.setItem(KEY, original);
    jest.mocked(AsyncStorage.setItem).mockClear();

    expect((await readPlayerState(undefined, boundary)).positions).toEqual({});
    await expect(
      updatePlayerState(
        state => ({...state, savedLessons: ['55']}),
        undefined,
        boundary,
      ),
    ).rejects.toThrow();

    expect(AsyncStorage.setItem).not.toHaveBeenCalled();
    expect(await AsyncStorage.getItem(KEY)).toBe(original);
  });

  it('allows a later update after a failed read and preserves the older record', async () => {
    await AsyncStorage.setItem(
      KEY,
      JSON.stringify({positions: {lesson: 12}, savedLessons: ['44']}),
    );
    jest
      .spyOn(AsyncStorage, 'getItem')
      .mockRejectedValueOnce(new Error('LOCAL_READ_FAILED'));
    const update = () =>
      updatePlayerState(
        state => ({...state, savedLessons: [...state.savedLessons, '55']}),
        undefined,
        boundary,
      );

    await expect(update()).rejects.toThrow('LOCAL_READ_FAILED');
    const recovered = await update();

    expect(recovered.positions).toEqual({lesson: 12});
    expect(recovered.savedLessons).toEqual(['44', '55']);
    expect(JSON.parse((await AsyncStorage.getItem(KEY))!)).toEqual(recovered);
  });

  it('does not let read-side compaction overwrite a newer saved state', async () => {
    await AsyncStorage.setItem(KEY, JSON.stringify(oversizedState()));
    const set = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
    let release!: () => void;
    const stalled = new Promise<void>(resolve => {
      release = resolve;
    });
    let intercepted = false;
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementation(async (key, value) => {
        if (
          !intercepted &&
          key === KEY &&
          !JSON.parse(value).savedLessons.includes('55')
        ) {
          intercepted = true;
          await stalled;
        }
        await set(key, value);
      });
    const reading = readPlayerState(undefined, boundary);
    try {
      for (let tick = 0; tick < 12; tick++) await Promise.resolve();
      await updatePlayerState(
        state => ({...state, savedLessons: ['55']}),
        undefined,
        boundary,
      );
      release();
      await reading;
      expect(
        JSON.parse((await AsyncStorage.getItem(KEY))!).savedLessons,
      ).toEqual(['55']);
    } finally {
      release();
      await reading;
    }
  });

  it('merges guest progress and a concurrent account bookmark in the same write order', async () => {
    const guestKey = '@rokn/course-player/v3:guest-before-login';
    await AsyncStorage.setItem(
      KEY,
      JSON.stringify({
        ...oversizedState(),
        positions: {existing: 9},
        lastWatchedAt: {},
        savedLessons: ['44'],
      }),
    );
    await AsyncStorage.setItem(
      guestKey,
      JSON.stringify({positions: {guest: 12}}),
    );
    const set = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
    let release!: () => void;
    const stalled = new Promise<void>(resolve => {
      release = resolve;
    });
    let intercepted = false;
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementation(async (key, value) => {
        const state = JSON.parse(value);
        if (!intercepted && key === KEY && state.positions.guest === 12) {
          intercepted = true;
          await stalled;
        }
        await set(key, value);
      });
    const migrating = migrateGuestLearningState('guest-before-login', boundary);
    try {
      for (let tick = 0; tick < 12; tick++) await Promise.resolve();
      expect(intercepted).toBe(true);
      const saving = updatePlayerState(
        state => ({
          ...state,
          savedLessons: [...state.savedLessons, '55'],
        }),
        undefined,
        boundary,
      );
      for (let tick = 0; tick < 24; tick++) await Promise.resolve();
      release();
      await Promise.all([migrating, saving]);
      const state = JSON.parse((await AsyncStorage.getItem(KEY))!);
      expect(state.savedLessons).toEqual(['44', '55']);
      expect(state.positions.guest).toBe(12);
    } finally {
      release();
      await migrating;
    }
  });

  it.each([false, true])(
    'retains native write ordering across runtime quiesce (new session: %s)',
    async renewed => {
      await AsyncStorage.setItem(KEY, JSON.stringify({positions: {lesson: 9}}));
      const set = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
      let release!: () => void;
      const stalled = new Promise<void>(resolve => {
        release = resolve;
      });
      let intercepted = false;
      jest
        .spyOn(AsyncStorage, 'setItem')
        .mockImplementation(async (key, value) => {
          if (!intercepted && key === KEY) {
            intercepted = true;
            await stalled;
          }
          await set(key, value);
        });
      const earlier = updatePlayerState(
        state => ({...state, positions: {...state.positions, lesson: 12}}),
        undefined,
        boundary,
      ).catch(error => error);
      try {
        for (let tick = 0; tick < 12; tick++) await Promise.resolve();
        expect(intercepted).toBe(true);
        quiesceLearningRuntime();
        if (renewed) mockAccountEpoch = 2;
        const later = updatePlayerState(
          state => ({...state, savedLessons: ['55']}),
          undefined,
          {...boundary, epoch: mockAccountEpoch},
        );
        for (let tick = 0; tick < 24; tick++) await Promise.resolve();
        release();
        const [first, last] = await Promise.all([earlier, later]);
        if (renewed) {
          expect(first.message).toBe('ACCOUNT_CHANGED_DURING_REQUEST');
        }
        expect(last.savedLessons).toEqual(['55']);
        expect(JSON.parse((await AsyncStorage.getItem(KEY))!)).toEqual(last);
        expect(last.positions.lesson).toBe(12);
      } finally {
        release();
        await earlier;
      }
    },
  );
});
