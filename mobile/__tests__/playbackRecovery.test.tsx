import React from 'react';
import ReactTestRenderer from 'react-test-renderer';
import {usePlaybackRecovery} from '../src/components/VideoPlayer/video/usePlaybackRecovery';
import {usePlaybackStatus} from '../src/components/VideoPlayer/video/usePlaybackStatus';
import {probeVideoSource} from '../src/components/VideoPlayer/videoSourcePolicy';

jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/videoSourcePolicy', () => ({
  probeVideoSource: jest.fn(),
}));

const ref = <T,>(current: T) => ({current});
const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('playback recovery completion', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.mocked(probeVideoSource).mockResolvedValue('reachable');
  });
  afterEach(() => {
    jest.clearAllTimers();
    jest.useRealTimers();
    jest.clearAllMocks();
  });

  async function mount(onRefreshSource?: () => Promise<void>) {
    const remountSource = jest.fn();
    const lastPosition = ref(7);
    const retryPosition = ref<number | null>(null);
    const recoveryTimer = ref<ReturnType<typeof setTimeout> | null>(null);
    const options = {
      activateFallback: jest.fn(),
      activateQuality: jest.fn(),
      activePlayerOwner: ref('owner'),
      adaptiveSource: false,
      bufferCount: ref(0),
      bufferDurationMs: ref(0),
      bufferingStartedAt: ref<number | null>(null),
      data: {id: 'reel-1', availableQualities: ['auto' as const]},
      deferredPreloadFailure: ref(false),
      diagnosticRequest: ref(0),
      effectiveQuality: 'auto' as const,
      emitPlaybackEvent: jest.fn(),
      hasRestored: ref(true),
      hasStarted: ref(true),
      hasSupportedFallback: false,
      isFallbackSource: false,
      lastPosition,
      lifecycleGeneration: ref(0),
      longBufferTimer: ref<ReturnType<typeof setTimeout> | null>(null),
      manualRetryFlight: ref<symbol | null>(null),
      onRefreshSource,
      playbackEligible: true,
      playerOwner: 'owner',
      publishRuntimeMetrics: jest.fn(),
      recoveryAttempts: ref(0),
      recoveryTimer,
      reelIdentity: ref('reel-1'),
      remountSource,
      resetSource: jest.fn(),
      retryPosition,
      sameSourceRetryUsed: ref(false),
      selectedQuality: 'auto' as const,
      selectedVariantUri: '',
      sourceRefreshRequired: false,
      sourceUri: 'https://cdn.example.com/video.mp4',
      unsupportedSource: false,
    };
    let current!: ReturnType<typeof usePlaybackRecovery> &
      ReturnType<typeof usePlaybackStatus>;
    function Harness() {
      const status = usePlaybackStatus();
      current = {...status, ...usePlaybackRecovery({...options, ...status})};
      return null;
    }
    let renderer!: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(<Harness />);
    });
    await ReactTestRenderer.act(() => {
      current.setIsLoaded(true);
      current.setIsBuffering(false);
    });
    return {
      get current() {
        return current;
      },
      options,
      renderer,
      remountSource,
    };
  }

  it('clears a terminal failure when the existing decoder resumes without losing position', async () => {
    const h = await mount();
    h.options.sameSourceRetryUsed.current = true;
    await ReactTestRenderer.act(() => {
      expect(h.current.recoverOrFail('timeout')).toBe(false);
    });
    expect(h.current.error).toBe(true);
    await ReactTestRenderer.act(() => h.current.markPlaybackHealthy());
    expect(h.current.error).toBe(false);
    expect(h.current.isLoaded).toBe(true);
    expect(h.current.isBuffering).toBe(false);
    expect(h.options.lastPosition.current).toBe(7);
    expect(h.remountSource).not.toHaveBeenCalled();
    await ReactTestRenderer.act(() => h.renderer.unmount());
  });

  it('cancels a queued remount once playback resumes', async () => {
    const h = await mount();
    await ReactTestRenderer.act(() => {
      h.current.recoverOrFail('timeout');
    });
    expect(h.options.recoveryTimer.current).not.toBeNull();
    await ReactTestRenderer.act(() => h.current.markPlaybackHealthy());
    await ReactTestRenderer.act(() => jest.advanceTimersByTime(15_000));
    expect(h.remountSource).not.toHaveBeenCalled();
    expect(h.current.recoveryMessage).toBe('');
    expect(h.current.isLoaded).toBe(true);
    expect(h.current.isBuffering).toBe(false);
    expect(h.options.retryPosition.current).toBeNull();
    await ReactTestRenderer.act(() => h.renderer.unmount());
  });

  it.each(['automatic', 'manual'])(
    'ignores an obsolete %s refresh after playback resumes',
    async mode => {
      const refresh = deferred();
      const h = await mount(() => refresh.promise);
      await ReactTestRenderer.act(() => {
        if (mode === 'manual') h.current.retryPlayback();
        else h.current.recoverOrFail('timeout');
      });
      await ReactTestRenderer.act(() => h.current.markPlaybackHealthy());
      await ReactTestRenderer.act(() => refresh.resolve());
      await ReactTestRenderer.act(() => jest.advanceTimersByTime(1000));
      expect(h.remountSource).not.toHaveBeenCalled();
      expect(h.current.recoveryMessage).toBe('');
      await ReactTestRenderer.act(() => h.renderer.unmount());
    },
  );

  it('ignores a late diagnostic for a failure that has already recovered', async () => {
    let finish!: (value: 'offline') => void;
    jest.mocked(probeVideoSource).mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finish = resolve;
        }),
    );
    const h = await mount();
    h.options.sameSourceRetryUsed.current = true;
    await ReactTestRenderer.act(() => {
      h.current.recoverOrFail('timeout');
    });
    await ReactTestRenderer.act(() => h.current.markPlaybackHealthy());
    await ReactTestRenderer.act(() => finish('offline'));
    expect(h.current.failureKind).toBe('timeout');
    expect(h.current.error).toBe(false);
    await ReactTestRenderer.act(() => h.renderer.unmount());
  });

  it('still retries an unresolved failure and restores its position', async () => {
    const h = await mount();
    await ReactTestRenderer.act(() => {
      h.current.recoverOrFail('timeout');
    });
    await ReactTestRenderer.act(() => jest.advanceTimersByTime(120));
    expect(h.remountSource).toHaveBeenCalledTimes(1);
    expect(h.options.retryPosition.current).toBe(7);
    await ReactTestRenderer.act(() => h.renderer.unmount());
  });

  it('allows a later manual retry even if an obsolete refresh is still pending', async () => {
    const oldRefresh = deferred();
    const newRefresh = deferred();
    const refresh = jest
      .fn()
      .mockReturnValueOnce(oldRefresh.promise)
      .mockReturnValueOnce(newRefresh.promise);
    const h = await mount(refresh);
    await ReactTestRenderer.act(() => h.current.retryPlayback());
    await ReactTestRenderer.act(() => h.current.markPlaybackHealthy());
    await ReactTestRenderer.act(() => h.current.retryPlayback());
    expect(refresh).toHaveBeenCalledTimes(2);
    const activeFlight = h.options.manualRetryFlight.current;
    await ReactTestRenderer.act(() => oldRefresh.resolve());
    expect(h.options.manualRetryFlight.current).toBe(activeFlight);
    expect(h.remountSource).not.toHaveBeenCalled();
    await ReactTestRenderer.act(() => newRefresh.resolve());
    expect(h.remountSource).toHaveBeenCalledTimes(1);
    expect(h.options.manualRetryFlight.current).toBeNull();
    await ReactTestRenderer.act(() => h.renderer.unmount());
  });

  it.each(['automatic', 'manual'])(
    'does not revive an old playback owner from a %s refresh',
    async mode => {
      const refresh = deferred();
      const h = await mount(() => refresh.promise);
      await ReactTestRenderer.act(() => {
        if (mode === 'manual') h.current.retryPlayback();
        else h.current.recoverOrFail('timeout');
      });
      h.options.lifecycleGeneration.current += 1;
      h.options.reelIdentity.current = 'reel-2';
      await ReactTestRenderer.act(() => refresh.resolve());
      await ReactTestRenderer.act(() => jest.advanceTimersByTime(1000));
      expect(h.remountSource).not.toHaveBeenCalled();
      await ReactTestRenderer.act(() => h.renderer.unmount());
    },
  );
});
