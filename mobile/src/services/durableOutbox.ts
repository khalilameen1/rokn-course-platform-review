import AsyncStorage from '@react-native-async-storage/async-storage';
import {serverNowMs} from '../utils/serverClock';
import {createKeyedAsyncQueue} from '../utils/keyedAsyncQueue';

export type DurableOutboxItem<T> = {
  id: string;
  /**
   * Monotonic per logical id. A delivery may finish after the same id was
   * replaced; its acknowledgement must only consume the version it sent.
   */
  generation: number;
  payload: T;
  createdAt: string;
  expiresAt: string;
  attempts: number;
  nextAttemptAt: string;
};

type DeliveryResult = 'ack' | 'retry' | 'drop';

type EnqueueOptions<T> = {
  storageKey: string;
  id: string;
  payload: T;
  maxItems?: number;
  ttlMs?: number;
};

type FlushOptions<T> = {
  storageKey: string;
  deliver: (payload: T) => Promise<DeliveryResult>;
  deliverBatch?: (payloads: T[]) => Promise<DeliveryResult>;
  maxBatch?: number;
  maxItems?: number;
  baseRetryMs?: number;
  maxRetryMs?: number;
  now?: () => number;
};

const DEFAULT_MAX_ITEMS = 50;
const DEFAULT_TTL_MS = 7 * 24 * 60 * 60 * 1000;
let locks = createKeyedAsyncQueue();
const flushes = new Map<string, Promise<void>>();

const withLock = <T>(storageKey: string, operation: () => Promise<T>) =>
  locks(storageKey, operation);

const validItem = <T>(value: unknown): value is DurableOutboxItem<T> => {
  if (!value || typeof value !== 'object') return false;
  const item = value as DurableOutboxItem<T>;
  return (
    typeof item.id === 'string' &&
    item.id.length > 0 &&
    (item.generation === undefined ||
      (Number.isInteger(item.generation) && item.generation >= 0)) &&
    typeof item.createdAt === 'string' &&
    Number.isFinite(Date.parse(item.createdAt)) &&
    typeof item.expiresAt === 'string' &&
    Number.isFinite(Date.parse(item.expiresAt)) &&
    typeof item.nextAttemptAt === 'string' &&
    Number.isFinite(Date.parse(item.nextAttemptAt)) &&
    Number.isInteger(item.attempts) &&
    item.attempts >= 0 &&
    Object.prototype.hasOwnProperty.call(item, 'payload')
  );
};

const read = async <T>(storageKey: string, now = serverNowMs()) => {
  const raw = await AsyncStorage.getItem(storageKey);
  if (!raw) return [];
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) throw new Error('INVALID_OUTBOX_SHAPE');
  } catch {
    // Do not let one damaged record poison all future enqueue attempts. Keep
    // the exact bytes first; if the device is full and the backup cannot be
    // written, throw and leave the original untouched rather than erasing it.
    await AsyncStorage.setItem(`${storageKey}:corrupt`, raw);
    await AsyncStorage.removeItem(storageKey);
    return [];
  }
  return parsed
    .filter(validItem<T>)
    .filter(item => Date.parse(item.expiresAt) > now);
};

const write = async <T>(
  storageKey: string,
  items: DurableOutboxItem<T>[],
  maxItems: number,
) => {
  await AsyncStorage.setItem(
    storageKey,
    JSON.stringify(items.slice(-Math.max(1, maxItems))),
  );
};

export const enqueueDurableOutbox = async <T>({
  storageKey,
  id,
  payload,
  maxItems = DEFAULT_MAX_ITEMS,
  ttlMs = DEFAULT_TTL_MS,
}: EnqueueOptions<T>): Promise<void> => {
  const now = serverNowMs();
  const timestamp = new Date(now).toISOString();
  await withLock(storageKey, async () => {
    const current = await read<T>(storageKey, now);
    const replaced = current.find(item => item.id === id);
    const next = current.filter(item => item.id !== id);
    next.push({
      id,
      generation: Math.max(0, replaced?.generation || 0) + 1,
      payload,
      createdAt: timestamp,
      expiresAt: new Date(now + Math.max(60_000, ttlMs)).toISOString(),
      attempts: 0,
      nextAttemptAt: timestamp,
    });
    await write(storageKey, next, maxItems);
  });
};

export const flushDurableOutbox = async <T>({
  storageKey,
  deliver,
  deliverBatch,
  maxBatch = 12,
  maxItems = DEFAULT_MAX_ITEMS,
  baseRetryMs = 2_000,
  maxRetryMs = 60 * 60 * 1000,
  now = serverNowMs,
}: FlushOptions<T>): Promise<void> => {
  const existing = flushes.get(storageKey);
  if (existing) return existing;

  const flight = (async () => {
    const snapshot = await withLock(storageKey, () =>
      read<T>(storageKey, now()),
    );
    const eligible = snapshot
      .filter(item => Date.parse(item.nextAttemptAt) <= now())
      .slice(0, Math.max(1, maxBatch));

    if (deliverBatch && eligible.length > 0) {
      let result: DeliveryResult = 'retry';
      try {
        result = await deliverBatch(eligible.map(item => item.payload));
      } catch {
        result = 'retry';
      }
      await withLock(storageKey, async () => {
        const current = await read<T>(storageKey, now());
        const eligibleVersions = new Map(
          eligible.map(item => [item.id, item.generation || 0]),
        );
        const next = current.flatMap(item => {
          const sentGeneration = eligibleVersions.get(item.id);
          if (
            sentGeneration === undefined ||
            (item.generation || 0) !== sentGeneration
          )
            return [item];
          if (result === 'ack' || result === 'drop') return [];
          const attempts = item.attempts + 1;
          const retryMs = Math.min(
            Math.max(baseRetryMs, 1) * 2 ** Math.min(attempts - 1, 12),
            Math.max(baseRetryMs, maxRetryMs),
          );
          return [
            {
              ...item,
              attempts,
              nextAttemptAt: new Date(now() + retryMs).toISOString(),
            },
          ];
        });
        await write(storageKey, next, maxItems);
      });
      return;
    }

    for (const candidate of eligible) {
      let result: DeliveryResult = 'retry';
      try {
        result = await deliver(candidate.payload);
      } catch {
        result = 'retry';
      }

      await withLock(storageKey, async () => {
        const current = await read<T>(storageKey, now());
        const index = current.findIndex(
          item =>
            item.id === candidate.id &&
            (item.generation || 0) === (candidate.generation || 0),
        );
        if (index < 0) return;
        if (result === 'ack' || result === 'drop') {
          current.splice(index, 1);
        } else {
          const attempts = current[index].attempts + 1;
          const retryMs = Math.min(
            Math.max(baseRetryMs, 1) * 2 ** Math.min(attempts - 1, 12),
            Math.max(baseRetryMs, maxRetryMs),
          );
          current[index] = {
            ...current[index],
            attempts,
            nextAttemptAt: new Date(now() + retryMs).toISOString(),
          };
        }
        await write(storageKey, current, maxItems);
      });

      if (result === 'retry') break;
    }
  })();
  flushes.set(storageKey, flight);
  try {
    await flight;
  } finally {
    if (flushes.get(storageKey) === flight) flushes.delete(storageKey);
  }
};

export const readDurableOutbox = async <T>(storageKey: string) => {
  try {
    return await withLock(storageKey, () => read<T>(storageKey));
  } catch {
    // Diagnostics may render an empty snapshot while storage is unavailable,
    // but enqueue/flush stay strict so they never overwrite unread events.
    return [];
  }
};

export const resetDurableOutboxForTests = () => {
  locks = createKeyedAsyncQueue();
  flushes.clear();
};
