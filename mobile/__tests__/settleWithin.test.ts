import {settleWithin} from '../src/utils/settleWithin';

describe('bounded best-effort work', () => {
  afterEach(() => jest.useRealTimers());

  it('releases the caller when local work does not settle', async () => {
    jest.useFakeTimers();
    const result = settleWithin(
      new Promise<string>(() => undefined),
      'cache-miss',
      25,
    );

    jest.advanceTimersByTime(25);

    await expect(result).resolves.toBe('cache-miss');
  });

  it('keeps an available value and turns a failed best-effort read into its fallback', async () => {
    await expect(
      settleWithin(Promise.resolve('cached'), 'cache-miss'),
    ).resolves.toBe('cached');
    await expect(
      settleWithin(
        Promise.reject(new Error('storage unavailable')),
        'cache-miss',
      ),
    ).resolves.toBe('cache-miss');
  });
});
