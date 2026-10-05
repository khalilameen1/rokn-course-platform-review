import React from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockPause = jest.fn();
jest.mock('../src/services/portfolioMediaDelivery', () => ({
  pausePortfolioMediaDelivery: (...args: unknown[]) => mockPause(...args),
}));
import {usePortfolioUploadSession} from '../src/screens/Profile/gallery/usePortfolioUploadSession';

const boundary = {epoch: 1, scope: 'owner-a'};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
describe('portfolio upload presentation owns durable pause acknowledgement', () => {
  let session!: ReturnType<typeof usePortfolioUploadSession>;
  let renderer: TestRenderer.ReactTestRenderer;
  const mountedRef = {current: true};
  const Harness = () => {
    session = usePortfolioUploadSession(mountedRef);
    return null;
  };
  beforeEach(() => {
    mockPause.mockReset();
    mountedRef.current = true;
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    act(() => {
      renderer = TestRenderer.create(<Harness />);
    });
  });
  afterEach(() => {
    mountedRef.current = false;
    act(() => renderer.unmount());
    jest.restoreAllMocks();
  });

  it('coalesces repeated stop presses and holds completion until durable pause answers', async () => {
    const pause = deferred<boolean>();
    mockPause.mockReturnValue(pause.promise);
    act(() => session.begin('9', boundary));
    let first!: Promise<boolean>;
    let second!: Promise<boolean>;
    let ended!: Promise<boolean>;
    let completed = false;
    act(() => {
      first = session.pause();
      second = session.pause();
      ended = session.end().then(value => {
        completed = true;
        return value;
      });
    });
    expect(first).toBe(second);
    expect(session.pausing).toBe(true);
    expect(completed).toBe(false);
    expect(mockPause).toHaveBeenCalledWith('9', boundary);
    expect(mockPause).toHaveBeenCalledTimes(1);
    await act(async () => {
      pause.resolve(true);
      expect(await ended).toBe(true);
    });
    expect(session.canPause).toBe(false);
    expect(session.pausing).toBe(false);
  });

  it('does not falsely acknowledge storage failure and allows the same owner to retry', async () => {
    mockPause
      .mockRejectedValueOnce(new Error('STORAGE_FULL'))
      .mockResolvedValueOnce(true);
    act(() => session.begin('9', boundary));
    await act(async () => {
      expect(await session.pause()).toBe(false);
    });
    expect(session.canPause).toBe(true);
    expect(session.pausing).toBe(false);
    expect(Alert.alert).toHaveBeenCalledWith(
      'تعذّر إيقاف الرفع',
      'الرفع مستمر\nحاول مرة أخرى',
    );
    await act(async () => {
      expect(await session.pause()).toBe(true);
    });
    expect(mockPause).toHaveBeenCalledTimes(2);
    expect(session.canPause).toBe(false);
    await act(async () => {
      expect(await session.end()).toBe(true);
    });
  });

  it('ignores late old-owner completion without clearing the new upload owner', async () => {
    const old = deferred<boolean>();
    mockPause.mockReturnValueOnce(old.promise).mockResolvedValueOnce(true);
    act(() => session.begin('9', boundary));
    let first!: Promise<boolean>;
    let ended!: Promise<boolean>;
    act(() => {
      first = session.pause();
      ended = session.end();
      session.begin('10', boundary);
    });
    await act(async () => {
      old.resolve(true);
      await first;
      await ended;
    });
    expect(session.canPause).toBe(true);
    await act(async () => {
      expect(await session.pause()).toBe(true);
    });
    expect(mockPause.mock.calls.map(([id]) => id)).toEqual(['9', '10']);
  });
});
