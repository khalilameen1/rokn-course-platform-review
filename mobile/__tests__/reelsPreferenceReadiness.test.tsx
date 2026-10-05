import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetItem = jest.fn();
const mockProfile = jest.fn();
const mockSaveItem = jest.fn();
const mockRemoveItem = jest.fn();
const mockPlaybackUpdate = jest.fn();
let mockBoundary = {scope: 'learner-7', epoch: 1};
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
  getItem: (...args: unknown[]) => mockGetItem(...args),
  saveItem: (...args: unknown[]) => mockSaveItem(...args),
  removeItem: (...args: unknown[]) => mockRemoveItem(...args),
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => true,
  getProfile: (...args: unknown[]) => mockProfile(...args),
  updatePlaybackPreferences: (...args: unknown[]) =>
    mockPlaybackUpdate(...args),
}));
import {usePlaybackPreferences} from '../src/screens/reels/usePlaybackPreferences';
import {markPlaybackPreferenceMutation} from '../src/services/accountPreferenceWrites';
import {
  flushPlaybackPreferenceWrites,
  PENDING_PLAYBACK_PREFERENCES_KEY,
  savePlaybackPreferencePatch,
} from '../src/services/playbackPreferenceSync';

describe('bounded playback preference readiness', () => {
  let preferences: ReturnType<typeof usePlaybackPreferences>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Harness = () => {
    preferences = usePlaybackPreferences(mockBoundary.scope);
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  beforeEach(() => {
    jest.useFakeTimers();
    mockBoundary = {scope: 'learner-7', epoch: 1};
    mockGetItem.mockReset().mockResolvedValue(null);
    mockProfile.mockReset().mockResolvedValue(null);
    mockSaveItem.mockReset().mockResolvedValue(true);
    mockRemoveItem.mockReset().mockResolvedValue(true);
    mockPlaybackUpdate.mockReset().mockResolvedValue(undefined);
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it('uses a timely stored preference without awaiting the profile', async () => {
    mockGetItem.mockImplementation(async (key: string) =>
      key.startsWith('VIDEO_QUALITY') ? '720p' : 1.25,
    );
    mockProfile.mockReturnValue(new Promise(() => undefined));
    await mount();
    expect(preferences.playbackPreferencesReady).toBe(true);
    expect(preferences.selectedQuality).toBe('720p');
    expect(preferences.playbackSpeed).toBe(1.25);
  });

  it('allows conservative current playback if native preference reads never settle', async () => {
    mockGetItem.mockReturnValue(new Promise(() => undefined));
    await mount();
    expect(preferences.playbackPreferencesReady).toBe(false);
    await act(async () => {
      jest.advanceTimersByTime(250);
    });
    expect(preferences.playbackPreferencesReady).toBe(true);
    expect(preferences.dataSaver).toBe(true);
    expect(preferences.selectedQuality).toBe('360p');
  });

  it('does not overwrite a learner choice with a late profile response', async () => {
    let finishProfile!: (value: unknown) => void;
    mockProfile.mockReturnValue(
      new Promise(resolve => {
        finishProfile = resolve;
      }),
    );
    await mount();
    await act(async () => {
      preferences.changeQuality('480p');
    });
    await act(async () => {
      finishProfile({videoQualityPreference: '1080p', playbackSpeed: 2});
    });
    expect(preferences.selectedQuality).toBe('480p');
    expect(preferences.playbackSpeed).toBe(2);
  });

  it('serializes an in-flight profile save before a newer learner quality write', async () => {
    let finishOldSave!: (value: boolean) => void;
    const stored = new Map<string, unknown>();
    mockProfile.mockResolvedValue({
      videoQualityPreference: '1080p',
      playbackSpeed: 2,
    });
    mockSaveItem.mockImplementation((key: string, value: unknown) => {
      if (value === '1080p') {
        return new Promise<boolean>(resolve => {
          finishOldSave = result => {
            stored.set(key, value);
            resolve(result);
          };
        });
      }
      stored.set(key, value);
      return Promise.resolve(true);
    });
    await mount();
    expect(finishOldSave).toBeDefined();
    await act(async () => {
      preferences.changeQuality('480p');
    });
    expect(mockSaveItem.mock.calls.some(([, value]) => value === '480p')).toBe(
      false,
    );
    await act(async () => {
      finishOldSave(true);
    });
    expect(stored.get('VIDEO_QUALITY:learner-7')).toBe('480p');
    expect(preferences.selectedQuality).toBe('480p');
    expect(preferences.playbackSpeed).toBe(2);
  });

  it('a speed choice does not discard the eventual stored quality', async () => {
    let finishQuality!: (value: unknown) => void;
    mockGetItem.mockImplementation((key: string) =>
      key.startsWith('VIDEO_QUALITY')
        ? new Promise(resolve => {
            finishQuality = resolve;
          })
        : Promise.resolve(1),
    );
    await mount();
    await act(async () => {
      jest.advanceTimersByTime(250);
    });
    await act(async () => {
      preferences.changePlaybackSpeed(1.25);
    });
    await act(async () => {
      finishQuality('720p');
    });
    expect(preferences.selectedQuality).toBe('720p');
    expect(preferences.dataSaver).toBe(false);
    expect(preferences.playbackSpeed).toBe(1.25);
  });

  it('a quality choice does not discard the eventual stored speed', async () => {
    let finishSpeed!: (value: unknown) => void;
    mockGetItem.mockImplementation((key: string) =>
      key.startsWith('VIDEO_PLAYBACK_SPEED')
        ? new Promise(resolve => {
            finishSpeed = resolve;
          })
        : Promise.resolve('data_saver'),
    );
    await mount();
    await act(async () => {
      jest.advanceTimersByTime(250);
    });
    await act(async () => {
      preferences.changeQuality('480p');
    });
    await act(async () => {
      finishSpeed(1.5);
    });
    expect(preferences.selectedQuality).toBe('480p');
    expect(preferences.playbackSpeed).toBe(1.5);
    expect(preferences.dataSaver).toBe(false);
  });

  it.each(['timely', 'late'])(
    'does not apply a %s device quality read overtaken by Settings',
    async timing => {
      let finishQuality!: (value: unknown) => void;
      mockGetItem.mockImplementation((key: string) =>
        key.startsWith('VIDEO_QUALITY')
          ? new Promise(resolve => {
              finishQuality = resolve;
            })
          : Promise.resolve(1.5),
      );
      await mount();
      if (timing === 'late') {
        await act(async () => {
          jest.advanceTimersByTime(250);
        });
      }
      // The shared owner is used by Settings, not the hook-local changeQuality
      // callback. Its mutation must invalidate pending native reads here too.
      markPlaybackPreferenceMutation(mockBoundary, 'quality');
      await act(async () => {
        finishQuality('720p');
      });
      expect(preferences.selectedQuality).not.toBe('720p');
      expect(preferences.playbackSpeed).toBe(1.5);
      expect(preferences.playbackPreferencesReady).toBe(true);
    },
  );

  const journalStorage = () => {
    const stored = new Map<string, unknown>();
    mockGetItem.mockImplementation(
      async (key: string) => stored.get(key) ?? null,
    );
    mockSaveItem.mockImplementation(async (key: string, value: unknown) => {
      stored.set(key, value);
      return true;
    });
    mockRemoveItem.mockImplementation(async (key: string) => {
      stored.delete(key);
      return true;
    });
    return stored;
  };

  it('rehydrates offline quality and speed after remount rather than reverting to an old profile', async () => {
    journalStorage();
    mockPlaybackUpdate.mockRejectedValue(new Error('offline'));
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '480p', playbackSpeed: 1.5},
      mockBoundary,
    );
    await flushPlaybackPreferenceWrites(mockBoundary);
    mockProfile.mockResolvedValue({
      videoQualityPreference: 'auto',
      playbackSpeed: 1,
    });
    await mount();
    expect(preferences.selectedQuality).toBe('480p');
    expect(preferences.playbackSpeed).toBe(1.5);
    await act(async () => renderer?.unmount());
    await mount();
    expect(preferences.selectedQuality).toBe('480p');
    expect(preferences.playbackSpeed).toBe(1.5);
  });

  it('rejects a stale profile begun while intent was pending even when its PUT has since acknowledged', async () => {
    const stored = journalStorage();
    mockPlaybackUpdate.mockRejectedValue(new Error('offline'));
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '480p', playbackSpeed: 1.25},
      mockBoundary,
    );
    await flushPlaybackPreferenceWrites(mockBoundary);
    let finishPut!: () => void;
    let finishProfile!: (value: unknown) => void;
    mockPlaybackUpdate.mockReturnValue(
      new Promise<void>(resolve => {
        finishPut = resolve;
      }),
    );
    mockProfile.mockReturnValue(
      new Promise(resolve => {
        finishProfile = resolve;
      }),
    );
    await mount();
    const flight = flushPlaybackPreferenceWrites(mockBoundary);
    await act(async () => {
      finishPut();
      await flight;
    });
    expect(stored.has(`${PENDING_PLAYBACK_PREFERENCES_KEY}:learner-7`)).toBe(
      false,
    );
    await act(async () =>
      finishProfile({videoQualityPreference: '1080p', playbackSpeed: 2}),
    );
    expect(preferences.selectedQuality).toBe('480p');
    expect(preferences.playbackSpeed).toBe(1.25);
    expect(stored.get('VIDEO_QUALITY:learner-7')).toBe('480p');
    expect(stored.get('VIDEO_PLAYBACK_SPEED:learner-7')).toBe(1.25);
  });
});
