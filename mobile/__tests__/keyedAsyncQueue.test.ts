import {createKeyedAsyncQueue} from '../src/utils/keyedAsyncQueue';

const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(done => (resolve = done));
  return {promise, resolve};
};

describe('keyed async queue', () => {
  it('waits for the captured tail without extending the wait to later operations', async () => {
    const run = createKeyedAsyncQueue();
    const firstGate = deferred();
    const laterGate = deferred();
    const first = run('same', () => firstGate.promise);
    const barrier = run.waitForPending('same');
    const later = run('same', () => laterGate.promise);
    await expect(run.waitForPending('other')).resolves.toBeUndefined();
    firstGate.resolve();
    try {
      await expect(barrier).resolves.toBeUndefined();
      const thirdOperation = jest.fn(async () => undefined);
      const third = run('same', thirdOperation);
      await Promise.resolve();
      expect(thirdOperation).not.toHaveBeenCalled();
      laterGate.resolve();
      await third;
    } finally {
      laterGate.resolve();
      await Promise.all([first, later]);
    }
  });

  it('a failed operation settles its read barrier without hiding the caller error', async () => {
    const run = createKeyedAsyncQueue();
    const failure = new Error('write failed');
    const write = run('same', async () => {
      throw failure;
    });
    const barrier = run.waitForPending('same');
    await expect(write).rejects.toBe(failure);
    await expect(barrier).resolves.toBeUndefined();
    await expect(run.waitForPending('same')).resolves.toBeUndefined();
  });

  it('orders same-key operations and returns their individual results', async () => {
    const run = createKeyedAsyncQueue();
    const gate = deferred();
    const calls: number[] = [];
    const first = run('same', async () => {
      calls.push(1);
      await gate.promise;
      calls.push(2);
      return 'first';
    });
    const second = run('same', async () => {
      calls.push(3);
      return 'second';
    });
    await Promise.resolve();
    expect(calls).toEqual([1]);
    gate.resolve();
    await expect(Promise.all([first, second])).resolves.toEqual([
      'first',
      'second',
    ]);
    expect(calls).toEqual([1, 2, 3]);
  });

  it('does not serialize unrelated keys or separate owners', async () => {
    const run = createKeyedAsyncQueue();
    const otherOwner = createKeyedAsyncQueue();
    const gate = deferred();
    const waiting = run('same', () => gate.promise);
    await expect(run('other', async () => 1)).resolves.toBe(1);
    await expect(otherOwner('same', async () => 2)).resolves.toBe(2);
    gate.resolve();
    await waiting;
  });

  it.each(['rejection', 'throw'])(
    'releases the next operation after a %s',
    async mode => {
      const run = createKeyedAsyncQueue();
      const failure = new Error('storage unavailable');
      const failed = run('same', () => {
        if (mode === 'throw') throw failure;
        return Promise.reject(failure);
      });
      const next = run('same', async () => 'recovered');
      await expect(failed).rejects.toBe(failure);
      await expect(next).resolves.toBe('recovered');
    },
  );

  it('does not let an older completion remove a newer pending tail', async () => {
    const run = createKeyedAsyncQueue();
    const gate = deferred();
    const started = deferred();
    const first = run('same', async () => undefined);
    const second = run('same', async () => {
      started.resolve();
      await gate.promise;
    });
    await first;
    await started.promise;
    const thirdOperation = jest.fn(async () => 'third');
    const third = run('same', thirdOperation);
    await Promise.resolve();
    expect(thirdOperation).not.toHaveBeenCalled();
    gate.resolve();
    await second;
    await expect(third).resolves.toBe('third');
    await expect(run('same', async () => 'fourth')).resolves.toBe('fourth');
  });
});
