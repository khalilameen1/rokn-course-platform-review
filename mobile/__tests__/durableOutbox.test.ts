const mockMovedScopes = new Set<string>();

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn((boundary: {scope: string}) => {
    if (mockMovedScopes.has(boundary.scope)) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  }),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  createDurableOutbox,
  durableOutboxBackoffMs,
  resetDurableOutboxForTests,
  type DurableOutboxPolicy,
} from '../src/services/durableOutbox';

const owner = {epoch: 1, scope: 'user-a'};
const other = {epoch: 1, scope: 'user-b'};

const policy = <T,>(
  overrides: Partial<DurableOutboxPolicy<T, any>> = {},
): DurableOutboxPolicy<T, any> => ({
  namespace: '@test/outbox',
  accountScoped: true,
  maxItems: 50,
  ttlMs: 7 * 24 * 60 * 60 * 1000,
  backoff: {baseMs: 1_000, maxMs: 60_000},
  ...overrides,
});

describe('durable outbox engine', () => {
  beforeEach(async () => {
    await AsyncStorage.clear();
    resetDurableOutboxForTests();
    mockMovedScopes.clear();
    jest.restoreAllMocks();
  });

  it('deduplicates by id and acknowledges only delivered records', async () => {
    const queue = createDurableOutbox<{value: number}>(policy());
    await Promise.all([
      queue.enqueue('one', {value: 1}, {boundary: owner}),
      queue.enqueue('one', {value: 2}, {boundary: owner}),
      queue.enqueue('two', {value: 3}, {boundary: owner}),
    ]);

    const deliver = jest.fn(async () => 'ack' as const);
    await queue.flush({boundary: owner, deliver});

    expect(deliver).toHaveBeenCalledTimes(2);
    expect(deliver).toHaveBeenCalledWith(
      expect.objectContaining({id: 'one', generation: 2, payload: {value: 2}}),
    );
    expect(await queue.list({boundary: owner})).toEqual([]);
  });

  it('keeps account scopes apart under one namespace', async () => {
    const queue = createDurableOutbox<number>(policy());
    await queue.enqueue('one', 1, {boundary: owner});
    await queue.enqueue('one', 2, {boundary: other});

    expect(queue.storageKey(owner)).toBe('@test/outbox:user-a');
    expect((await queue.list({boundary: owner}))[0].payload).toBe(1);
    expect((await queue.list({boundary: other}))[0].payload).toBe(2);
  });

  it('backs off with full jitter and keeps newer enqueues', async () => {
    let now = Date.parse('2026-08-12T10:00:00.000Z');
    jest.spyOn(Date, 'now').mockImplementation(() => now);
    const queue = createDurableOutbox<number>(policy());
    await queue.enqueue('one', 1, {boundary: owner});

    await queue.flush({
      boundary: owner,
      deliver: async () => 'retry',
      now: () => now,
      random: () => 0.5,
    });
    await queue.enqueue('two', 2, {boundary: owner});

    const queued = await queue.list({boundary: owner});
    expect(queued.map(item => item.id)).toEqual(['one', 'two']);
    expect(queued[0].attempts).toBe(1);
    // attempt 1: uniform in [0, min(cap, base * 2^0)) -> 0.5 * 1000
    expect(Date.parse(queued[0].nextAttemptAt)).toBe(now + 500);

    // A retry that is not yet due is skipped by an automatic flush...
    const skipped = jest.fn(async () => 'ack' as const);
    await queue.flush({boundary: owner, deliver: skipped, now: () => now});
    expect(skipped).toHaveBeenCalledWith(expect.objectContaining({id: 'two'}));
    expect(skipped).not.toHaveBeenCalledWith(
      expect.objectContaining({id: 'one'}),
    );
    // ...but a lifecycle trigger may ignore the client clock.
    await queue.flush({
      boundary: owner,
      deliver: skipped,
      now: () => now,
      ignoreBackoff: true,
    });
    expect(await queue.list({boundary: owner})).toEqual([]);
  });

  it('caps the exponential ceiling and never exceeds it', () => {
    const backoff = {baseMs: 2_000, maxMs: 60_000};
    expect(durableOutboxBackoffMs(1, backoff, () => 0.999)).toBe(1_998);
    expect(durableOutboxBackoffMs(3, backoff, () => 0.999)).toBe(7_992);
    expect(durableOutboxBackoffMs(40, backoff, () => 0.999)).toBe(59_940);
    expect(durableOutboxBackoffMs(4, backoff, () => 0)).toBe(0);
  });

  it('never bypasses a server-directed cooldown', async () => {
    const now = Date.parse('2026-08-12T10:00:00.000Z');
    jest.spyOn(Date, 'now').mockImplementation(() => now);
    const queue = createDurableOutbox<number>(policy());
    await queue.enqueue('one', 1, {boundary: owner});
    await queue.flush({
      boundary: owner,
      deliver: async () => ({retryAt: now + 60_000}),
      now: () => now,
    });
    const deliver = jest.fn(async () => 'ack' as const);
    await queue.flush({
      boundary: owner,
      deliver,
      now: () => now,
      ignoreBackoff: true,
    });
    expect(deliver).not.toHaveBeenCalled();
    expect((await queue.get('one', {boundary: owner}))?.notBefore).toBe(
      new Date(now + 60_000).toISOString(),
    );
  });

  it('drops expired and damaged records without blocking the queue', async () => {
    const queue = createDurableOutbox<number>(policy());
    await AsyncStorage.setItem(
      '@test/outbox:user-a',
      JSON.stringify([
        {id: 'damaged'},
        {
          id: 'expired',
          payload: 1,
          createdAt: '2020-01-01T00:00:00.000Z',
          expiresAt: '2020-01-02T00:00:00.000Z',
          attempts: 0,
          nextAttemptAt: '2020-01-01T00:00:00.000Z',
        },
      ]),
    );
    expect(await queue.list({boundary: owner})).toEqual([]);

    await AsyncStorage.setItem('@test/outbox:user-a', '{not json');
    expect(await queue.list({boundary: owner})).toEqual([]);
    expect(await AsyncStorage.getItem('@test/outbox:user-a:corrupt')).toBe(
      '{not json',
    );
  });

  it('isolates a failed group and keeps a saved pause for new siblings', async () => {
    type Media = {project: string; file: string};
    const queue = createDurableOutbox<Media>(
      policy({groupOf: payload => payload.project}),
    );
    await queue.enqueue('a1', {project: 'a', file: '1'}, {boundary: owner});
    await queue.enqueue('a2', {project: 'a', file: '2'}, {boundary: owner});
    await queue.enqueue('b1', {project: 'b', file: '1'}, {boundary: owner});
    await queue.enqueue('c1', {project: 'c', file: '1'}, {boundary: owner});
    await queue.setPaused('c', true, {boundary: owner});
    await queue.enqueue('c2', {project: 'c', file: '2'}, {boundary: owner});

    const deliver = jest.fn(async ({id}: {id: string}) =>
      id === 'a1' ? ('retry' as const) : ('ack' as const),
    );
    const report = await queue.flush({boundary: owner, deliver});

    expect(deliver.mock.calls.map(([item]) => item.id)).toEqual(['a1', 'b1']);
    expect(report.attempted).toBe(2);
    const left = await queue.list({boundary: owner});
    expect(left.map(item => [item.id, Boolean(item.paused)])).toEqual([
      ['a1', false],
      ['a2', false],
      ['c1', true],
      ['c2', true],
    ]);
  });

  it('drops a whole group when its target is gone', async () => {
    const queue = createDurableOutbox<{project: string}>(
      policy({groupOf: payload => payload.project}),
    );
    await queue.enqueue('a1', {project: 'a'}, {boundary: owner});
    await queue.enqueue('a2', {project: 'a'}, {boundary: owner});
    await queue.enqueue('b1', {project: 'b'}, {boundary: owner});
    await queue.flush({
      boundary: owner,
      deliver: async item => (item.id === 'a1' ? 'drop_group' : 'retry'),
    });
    expect((await queue.list({boundary: owner})).map(item => item.id)).toEqual(
      ['b1'],
    );
  });

  it('coalesces to the newest value and delivers a generation replaced in flight', async () => {
    const queue = createDurableOutbox<{a?: number; b?: number}>(
      policy({coalesce: (previous, next) => ({...previous, ...next})}),
    );
    await queue.enqueue('prefs', {a: 1}, {boundary: owner});
    const sent: unknown[] = [];
    await queue.flush({
      boundary: owner,
      deliver: async item => {
        sent.push(item.payload);
        if (sent.length === 1) {
          await queue.enqueue('prefs', {b: 2}, {boundary: owner});
        }
        return 'ack';
      },
    });
    expect(sent).toEqual([{a: 1}, {a: 1, b: 2}]);
    expect(await queue.list({boundary: owner})).toEqual([]);
  });

  it('keeps an acknowledged item whose local commit fails', async () => {
    const queue = createDurableOutbox<number>(policy());
    await queue.enqueue('one', 1, {boundary: owner});
    await queue.flush({
      boundary: owner,
      deliver: async () => ({outcome: 'ack', commit: async () => false}),
    });
    expect(await queue.list({boundary: owner})).toHaveLength(1);
  });

  it('classifies thrown errors and propagates account changes untouched', async () => {
    const queue = createDurableOutbox<number>(
      policy({
        classify: error =>
          (error as {status?: number}).status === 422 ? 'drop' : 'retry',
      }),
    );
    await queue.enqueue('invalid', 1, {boundary: owner});
    await queue.enqueue('moved', 2, {boundary: owner});

    await expect(
      queue.flush({
        boundary: owner,
        deliver: async item => {
          if (item.id === 'invalid') {
            throw Object.assign(new Error('INVALID'), {status: 422});
          }
          throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
        },
      }),
    ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');

    const left = await queue.list({boundary: owner});
    expect(left.map(item => [item.id, item.attempts])).toEqual([['moved', 0]]);
  });

  it('rethrows a foreground failure after recording its classification', async () => {
    const queue = createDurableOutbox<number>(policy({classify: () => 'keep'}));
    const item = await queue.enqueue('one', 1, {boundary: owner});
    await expect(
      queue.deliver(item, async () => {
        throw new Error('OFFLINE');
      }, {boundary: owner}),
    ).rejects.toThrow('OFFLINE');
    expect(await queue.get('one', {boundary: owner})).toMatchObject({
      attempts: 0,
    });
  });

  it('does not run a paused item unless the learner resumes it', async () => {
    const queue = createDurableOutbox<number>(policy());
    const item = await queue.enqueue('one', 1, {boundary: owner, paused: true});
    const deliver = jest.fn(async () => 'ack' as const);
    await expect(
      queue.deliver(item, deliver, {boundary: owner}),
    ).resolves.toEqual({state: 'paused'});
    expect(deliver).not.toHaveBeenCalled();
    await expect(
      queue.deliver(item, deliver, {boundary: owner, resume: true}),
    ).resolves.toMatchObject({state: 'delivered', outcome: 'ack'});
    expect(await queue.list({boundary: owner})).toEqual([]);
  });

  it('protects new file references before the write and releases them after removal', async () => {
    const retain = jest.fn(async () => undefined);
    const release = jest.fn(async () => undefined);
    const queue = createDurableOutbox<{file: string}, string>(
      policy({
        references: {
          of: payload => [payload.file],
          key: reference => reference,
          retain,
          release,
        },
      }),
    );
    await queue.enqueue('one', {file: 'a'}, {boundary: owner});
    expect(retain).toHaveBeenLastCalledWith(['a'], 'user-a');

    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockRejectedValueOnce(new Error('STORAGE_FULL'));
    await expect(
      queue.enqueue('one', {file: 'b'}, {boundary: owner}),
    ).rejects.toThrow('STORAGE_FULL');
    // Write-ahead union, then rollback to the committed set.
    expect(retain.mock.calls.slice(-2)).toEqual([
      [['a', 'b'], 'user-a'],
      [['a'], 'user-a'],
    ]);

    await queue.enqueue('one', {file: 'b'}, {boundary: owner});
    expect(release).toHaveBeenLastCalledWith('a');
    await queue.remove('one', {boundary: owner});
    expect(retain).toHaveBeenLastCalledWith([], 'user-a');
    expect(release).toHaveBeenLastCalledWith('b');
  });

  it('retracts an enqueue that landed after its account moved', async () => {
    const queue = createDurableOutbox<number>(
      policy({retractOnBoundaryChange: true}),
    );
    const write = AsyncStorage.setItem;
    jest.spyOn(AsyncStorage, 'setItem').mockImplementationOnce(async (...args) => {
      await write(...args);
      mockMovedScopes.add('user-a');
    });
    await expect(queue.enqueue('one', 1, {boundary: owner})).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    mockMovedScopes.clear();
    expect(await queue.list({boundary: owner})).toEqual([]);
  });

  it('migrates a previous storage format before retiring it', async () => {
    const order: string[] = [];
    const queue = createDurableOutbox<{position: number}>(
      policy({
        legacy: {
          read: async scope => {
            order.push(`read:${scope}`);
            return [
              {id: 'lesson-2', payload: {position: 20}, createdAt: Date.now() - 2},
              {id: 'lesson-1', payload: {position: 10}, createdAt: Date.now() - 5},
            ];
          },
          clear: async scope => {
            order.push(`clear:${scope}`);
            expect(await AsyncStorage.getItem('@test/outbox:user-a')).toContain(
              'lesson-2',
            );
          },
        },
      }),
    );
    expect(
      (await queue.list({boundary: owner})).map(item => [item.id, item.payload]),
    ).toEqual([
      ['lesson-1', {position: 10}],
      ['lesson-2', {position: 20}],
    ]);
    await queue.list({boundary: owner});
    expect(order).toEqual(['read:user-a', 'clear:user-a']);
  });

  it('keeps the old format when it cannot be read yet', async () => {
    let attempts = 0;
    const queue = createDurableOutbox<number>(
      policy({
        legacy: {
          read: async () => {
            attempts += 1;
            if (attempts === 1) throw new Error('NATIVE_STORAGE_BUSY');
            return [{id: 'one', payload: 1}];
          },
          clear: async () => undefined,
        },
      }),
    );
    expect(await queue.list({boundary: owner})).toEqual([]);
    expect((await queue.list({boundary: owner})).map(item => item.id)).toEqual([
      'one',
    ]);
  });

  it('shares one pass between concurrent full flushes', async () => {
    const queue = createDurableOutbox<number>(policy());
    await queue.enqueue('one', 1, {boundary: owner});
    let finish: (() => void) | undefined;
    const deliver = jest.fn(
      () =>
        new Promise<'ack'>(resolve => {
          finish = () => resolve('ack');
        }),
    );
    const first = queue.flush({boundary: owner, deliver});
    const second = queue.flush({boundary: owner, deliver});
    for (let turn = 0; turn < 20 && !finish; turn += 1) {
      await new Promise(resolve => setImmediate(resolve));
    }
    finish?.();
    const [left, right] = await Promise.all([first, second]);
    expect(left).toBe(right);
    expect(deliver).toHaveBeenCalledTimes(1);
  });
});
