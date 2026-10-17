import {
  isUploadStalledError,
  uploadProgressPercent,
  withUploadStallTimeout,
  type UploadTransport,
} from '../src/services/uploadStallTimeout';

const abortable = (transport: UploadTransport) =>
  new Promise<string>((_resolve, reject) => {
    transport.signal.addEventListener('abort', () =>
      reject(Object.assign(new Error('canceled'), {code: 'ERR_CANCELED'})),
    );
  });

describe('upload stall timeout (Uppy XHRUpload progress-timeout model)', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('never applies a total deadline to an upload that keeps moving', async () => {
    let transport!: UploadTransport;
    let finish!: (value: string) => void;
    const result = withUploadStallTimeout(next => {
      transport = next;
      return new Promise<string>((resolve, reject) => {
        finish = resolve;
        next.signal.addEventListener('abort', () => reject(new Error('x')));
      });
    });
    expect(transport.timeout).toBe(0);
    // Ten minutes of a slow uplink: progress every 20 seconds.
    for (let loaded = 1; loaded <= 30; loaded += 1) {
      await jest.advanceTimersByTimeAsync(20_000);
      transport.onUploadProgress({loaded, total: 100, bytes: 1} as never);
    }
    expect(transport.signal.aborted).toBe(false);
    finish('ok');
    await expect(result).resolves.toBe('ok');
  });

  it('aborts after 30 seconds without progress as a timeout-shaped error', async () => {
    let transport!: UploadTransport;
    const result = withUploadStallTimeout(next => {
      transport = next;
      return abortable(next);
    });
    const failure = result.catch((error: unknown) => error);
    transport.onUploadProgress({loaded: 10, total: 100, bytes: 10} as never);
    await jest.advanceTimersByTimeAsync(29_000);
    expect(transport.signal.aborted).toBe(false);
    await jest.advanceTimersByTimeAsync(1_001);
    expect(transport.signal.aborted).toBe(true);
    const error = await failure;
    expect(error).toMatchObject({code: 'ECONNABORTED', uploadStalled: true});
    expect(isUploadStalledError(error)).toBe(true);
  });

  it('waits for the server response after the last byte within its own bound', async () => {
    let transport!: UploadTransport;
    const result = withUploadStallTimeout(
      next => {
        transport = next;
        return abortable(next);
      },
      {stallMs: 1_000, responseMs: 5_000},
    );
    const failure = result.catch((error: unknown) => error);
    transport.onUploadProgress({loaded: 100, total: 100, bytes: 1} as never);
    await jest.advanceTimersByTimeAsync(4_000);
    expect(transport.signal.aborted).toBe(false);
    await jest.advanceTimersByTimeAsync(1_001);
    expect(await failure).toMatchObject({uploadStalled: true});
  });

  it('keeps a caller pause as the original cancel, never a stall', async () => {
    const pause = new AbortController();
    let transport!: UploadTransport;
    const result = withUploadStallTimeout(
      next => {
        transport = next;
        return abortable(next);
      },
      {signal: pause.signal},
    );
    pause.abort();
    expect(transport.signal.aborted).toBe(true);
    await expect(result).rejects.toMatchObject({code: 'ERR_CANCELED'});
    await result.catch(error => expect(isUploadStalledError(error)).toBe(false));
  });

  it('isolates a throwing progress observer from the transfer', async () => {
    const onUploadProgress = jest.fn(() => {
      throw new Error('presentation');
    });
    await expect(
      withUploadStallTimeout(
        async next => {
          next.onUploadProgress({loaded: 5, total: 10, bytes: 5} as never);
          return 'done';
        },
        {onUploadProgress},
      ),
    ).resolves.toBe('done');
    expect(onUploadProgress).toHaveBeenCalledTimes(1);
  });

  it('reports whole percentages only when the total is known', () => {
    expect(uploadProgressPercent({loaded: 1, total: 3})).toBe(33);
    expect(uploadProgressPercent({loaded: 5, total: undefined})).toBeNull();
    expect(uploadProgressPercent({loaded: 9, total: 4})).toBe(100);
  });
});
