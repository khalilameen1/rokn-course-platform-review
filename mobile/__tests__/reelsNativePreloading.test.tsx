import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockWindow = jest.fn();
const mockClear = jest.fn();
let mockBoundary = {epoch: 1, scope: 'user-1'};
jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
  NativeModules: {
    RoknReelPreload: {
      setWindow: (...args: unknown[]) => mockWindow(...args),
      clear: (...args: unknown[]) => mockClear(...args),
    },
  },
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
import type {
  CourseFeedItem,
  CourseReel,
} from '../src/components/VideoPlayer/types';
import {
  buildNativeReelWindow,
  useReelsNativePreloading,
} from '../src/screens/reels/useReelsNativePreloading';

const reel = (index: number): CourseReel => ({
  id: String(index),
  lessonId: String(index),
  sectionId: String(index),
  moduleId: '1',
  title: 'مقطع',
  caption: '',
  videoUrl: `https://media.example/${index}.m3u8?token=issued`,
  playbackSessionId: `session-${index}`,
  playbackExpiresAt: new Date(Date.now() + 300_000).toISOString(),
  availableQualities: ['auto', '480p'],
  qualitySources: {
    '480p': `https://media.example/${index}/480.m3u8?token=issued`,
  },
  isLocked: false,
  isPreview: false,
  isCompleted: false,
  reelNumber: index,
});
const feed = (): CourseFeedItem[] =>
  [0, 1, 2, 3].map(index => ({
    key: String(index),
    type: 'reel',
    moduleId: '1',
    reel: reel(index),
  }));

describe('native signed adjacent samples owner', () => {
  let params: Parameters<typeof useReelsNativePreloading>[0];
  let state: ReturnType<typeof useReelsNativePreloading>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Harness = () => {
    state = useReelsNativePreloading(params);
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  beforeEach(() => {
    mockBoundary = {epoch: 1, scope: 'user-1'};
    mockWindow.mockReset().mockResolvedValue(true);
    mockClear.mockReset();
    params = {
      scopeKey: 'user-1:course-3',
      active: true,
      preloadEnabled: true,
      feedItems: feed(),
      currentIndex: 1,
      quality: 'auto',
    };
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer?.unmount());
    renderer = undefined;
  });

  it('keeps only current and actual adjacent signed reels, using the selected quality', () => {
    const entries = buildNativeReelWindow(feed(), 1, '480p');
    expect(entries.map(item => item.index)).toEqual([1, 2, 0]);
    expect(entries.every(item => item.uri.includes('/480.m3u8'))).toBe(true);
  });

  it('does not prepare locked, unsigned or expired media', () => {
    const items = feed();
    if (items[0].type === 'reel') items[0].reel.isLocked = true;
    if (items[1].type === 'reel') items[1].reel.playbackSessionId = undefined;
    if (items[2].type === 'reel')
      items[2].reel.playbackExpiresAt = new Date(Date.now() - 1).toISOString();
    expect(buildNativeReelWindow(items, 1, 'auto')).toEqual([]);
  });

  it('acknowledges native ownership before mounting the protected source', async () => {
    let finish!: (accepted: boolean) => void;
    mockWindow.mockReturnValue(
      new Promise(resolve => {
        finish = resolve;
      }),
    );
    await mount();
    expect(state.nativePreloadPending).toBe(true);
    expect(state.nativePreloadOwner).toBeUndefined();
    await act(async () => {
      finish(true);
    });
    expect(state.nativePreloadPending).toBe(false);
    expect(state.nativePreloadOwner).toMatch(/^rokn-reels:/);
    expect(mockWindow.mock.calls[0][3]).toHaveLength(3);
  });

  it('does not unmount current playback when only the next signed source changes', async () => {
    await mount();
    const owner = state.nativePreloadOwner;
    let finish!: (accepted: boolean) => void;
    mockWindow.mockReturnValueOnce(
      new Promise(resolve => {
        finish = resolve;
      }),
    );
    const next = feed();
    if (next[2].type === 'reel') next[2].reel.videoUrl += '&renewal=2';
    params = {...params, feedItems: next};
    await act(async () => {
      renderer?.update(<Harness />);
    });
    expect(state.nativePreloadPending).toBe(false);
    expect(state.nativePreloadOwner).toBe(owner);
    await act(async () => {
      finish(true);
    });
  });

  it('retires its old account owner and ignores a late bridge acknowledgement', async () => {
    let finishOld!: (accepted: boolean) => void;
    mockWindow.mockReturnValueOnce(
      new Promise(resolve => {
        finishOld = resolve;
      }),
    );
    await mount();
    const oldOwner = mockWindow.mock.calls[0][0];
    mockBoundary = {epoch: 2, scope: 'user-2'};
    params = {...params, scopeKey: 'user-2:course-3'};
    await act(async () => {
      renderer?.update(<Harness />);
    });
    const newOwner = state.nativePreloadOwner;
    await act(async () => {
      finishOld(true);
    });
    expect(state.nativePreloadOwner).toBe(newOwner);
    expect(newOwner).not.toBe(oldOwner);
    expect(mockClear.mock.calls.some(([owner]) => owner === oldOwner)).toBe(
      true,
    );
  });

  it('clears native samples on background and resumes through a fresh window', async () => {
    await mount();
    const owner = state.nativePreloadOwner;
    params = {...params, active: false};
    await act(async () => {
      renderer?.update(<Harness />);
    });
    expect(state.nativePreloadOwner).toBeUndefined();
    expect(mockClear.mock.calls.some(([key]) => key === owner)).toBe(true);
    params = {...params, active: true, preloadEnabled: false};
    await act(async () => {
      renderer?.update(<Harness />);
    });
    expect(state.nativePreloadPending).toBe(false);
    expect(mockWindow.mock.calls.at(-1)?.[4]).toBe(false);
  });

  it('keeps current playback available after a bridge failure without restoring a second decoder', async () => {
    mockWindow.mockRejectedValue(new Error('NATIVE_WINDOW_FAILED'));
    await mount();
    expect(state.nativePreloadMode).toBe(true);
    expect(state.nativePreloadPending).toBe(false);
    expect(state.nativePreloadOwner).toBeUndefined();
  });
});
