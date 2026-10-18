import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {serverNowMs} from '../utils/serverClock';
import {createKeyedAsyncQueue} from '../utils/keyedAsyncQueue';
import {settleWithin} from '../utils/settleWithin';

/**
 * The one durable outbox engine (ARCHITECTURE.md M2).
 *
 * References this adapts, not reinvents:
 * - Transactional outbox (Chris Richardson, microservices.io "Pattern:
 *   Transactional outbox"), applied on the client: an intent is committed to
 *   device storage before any network call, delivery is at-least-once, and a
 *   delivery only retires the exact version (`generation`) it sent.
 * - Exponential backoff with full jitter (Marc Brooker, AWS Architecture Blog,
 *   "Exponential Backoff And Jitter", 2015; also the AWS SDK retry strategy):
 *   `delay = random(0, min(cap, base * 2 ** attempt))`. Server-directed
 *   cooldowns (Retry-After) are a hard floor (`notBefore`) on top of it.
 *
 * A feature never owns a queue, retry clock or storage format. It declares a
 * typed adapter: payload type + deliver function + {@link DurableOutboxPolicy}.
 * Engine features (each used by at least one adapter):
 * - account-scoped storage keys `${namespace}:${scope}` (M6: the logout and
 *   account-switch cleanup removes them like every other scoped key);
 * - replace-by-id with a monotonic `generation`, optional `coalesce`;
 * - groups (`groupOf`): a paused group stays paused for new siblings, and a
 *   transient failure skips the rest of its group but not other groups;
 * - TTL, max items, damaged-record quarantine, `usable` pruning;
 * - write-ahead file `references` (no durable item ever points at a released
 *   file; a process death can leak a reclaimable file, never lose one);
 * - migration of a feature's previous storage format on first read
 *   (`legacy`): the new record is written before the old one is retired;
 * - retry classification of thrown errors (`classify`) and an in-lock
 *   `commit` hook so a delivery retires its item only after the local mirror
 *   of the accepted value is written.
 *
 * Lifecycle triggers (startup, foreground, connectivity regained, a learner
 * tap) are new evidence that conditions changed, so they may pass
 * `ignoreBackoff`; the backoff clock gates automatic retries. A server
 * cooldown is never ignored.
 */

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
  /** Waits for an explicit resume (a learner pause). */
  paused?: boolean;
  /** Server-directed cooldown (Retry-After); never bypassed. */
  notBefore?: string;
};

export type DurableOutboxOutcome =
  /** Delivered: retire this generation. */
  | 'ack'
  /** Permanently refused: retire this generation. */
  | 'drop'
  /** The group's target is gone: retire every item of the group. */
  | 'drop_group'
  /** Transient: back off with full jitter. */
  | 'retry'
  /** Keep it until an explicit resume. */
  | 'pause'
  /** No decision yet (cancelled, awaiting review): leave it unchanged. */
  | 'keep'
  /** Server-directed cooldown. */
  | {retryAt: number};

export type DurableOutboxDelivery<T, R = unknown> = {
  outcome: DurableOutboxOutcome;
  value?: R;
  /**
   * Runs under the queue lock only while the delivered generation is still
   * current, before the item is retired. `false` keeps the item.
   */
  commit?: (item: DurableOutboxItem<T>) => Promise<boolean>;
};

export type DurableOutboxDeliver<T, R = unknown> = (
  item: DurableOutboxItem<T>,
) => Promise<DurableOutboxOutcome | DurableOutboxDelivery<T, R>>;

export type LegacyOutboxRecord<T> = {
  id: string;
  payload: T;
  /** Milliseconds; keeps the original TTL clock and order. */
  createdAt?: number;
  paused?: boolean;
  notBefore?: number;
};

export type DurableOutboxReferences<T, Ref> = {
  of: (payload: T) => Ref[];
  key: (reference: Ref) => string;
  /** Replace this outbox's protected set with exactly these references. */
  retain: (references: Ref[], scope: string) => Promise<void>;
  /** Called after the last durable item using the reference is gone. */
  release: (reference: Ref) => Promise<void>;
};

export type DurableOutboxPolicy<T, Ref = unknown> = {
  /** Persisted contract; change only with a `legacy` migration. */
  namespace: string;
  accountScoped: boolean;
  maxItems: number;
  ttlMs: number;
  backoff: {baseMs: number; maxMs: number};
  decode?: (payload: unknown) => T | null;
  coalesce?: (previous: T, next: T) => T;
  groupOf?: (payload: T) => string;
  /** Outcome for an error thrown by a deliver function. Default `retry`. */
  classify?: (error: unknown) => DurableOutboxOutcome;
  /** Items whose payload is no longer usable (e.g. its file vanished). */
  usable?: (payload: T) => Promise<boolean>;
  references?: DurableOutboxReferences<T, Ref>;
  legacy?: {
    read: (scope: string) => Promise<LegacyOutboxRecord<T>[]>;
    /** Called only after the migrated items are durable. */
    clear: (scope: string) => Promise<void>;
  };
  /** Remove a write that landed after its account boundary moved. */
  retractOnBoundaryChange?: boolean;
  /** Successful delivery hook, e.g. invalidating related queries. */
  onDelivered?: (payload: T, scope: string) => void;
};

export type DurableOutboxFlushResult<T, R> = {
  item: DurableOutboxItem<T>;
  outcome: DurableOutboxOutcome;
  value?: R;
  /** False when a newer generation replaced the item during delivery. */
  applied: boolean;
};

export type DurableOutboxFlushReport<T, R> = {
  attempted: number;
  results: DurableOutboxFlushResult<T, R>[];
};

type FlushOptions<T, R> = {
  boundary?: AccountSessionBoundary;
  deliver: DurableOutboxDeliver<T, R>;
  /** One request for several payloads; one outcome for all of them. */
  deliverBatch?: (payloads: T[]) => Promise<DurableOutboxOutcome>;
  maxBatch?: number;
  ids?: string[];
  ignoreBackoff?: boolean;
  /** Start a new pass after a running one instead of joining it. */
  afterCurrent?: boolean;
  /**
   * Deliver a generation that replaced an item while it was in flight in the
   * same pass (default). A throttled stream passes false and waits instead.
   */
  redeliverSuperseded?: boolean;
  /**
   * Wait for a final outcome's local retirement before the pass resolves
   * (default). False lets the caller continue while the retirement runs in
   * the queue's order; later operations on the queue still wait for it.
   */
  awaitRetirement?: boolean;
  /** Classification for this pass, e.g. a silent background replay. */
  classify?: (error: unknown) => DurableOutboxOutcome;
  now?: () => number;
  random?: () => number;
};

type OwnerOptions = {boundary?: AccountSessionBoundary};

const DEVICE_SCOPE = 'device';
const MAX_REDELIVERIES = 3;
const ABORT_CODES = new Set([
  'ACCOUNT_CHANGED_DURING_REQUEST',
  'ACCOUNT_SESSION_CHANGED',
]);

let locks = createKeyedAsyncQueue();
let flights = new Map<string, {epoch?: number; promise: Promise<unknown>}>();

export const isOutboxAbort = (error: unknown) =>
  error instanceof Error && ABORT_CODES.has(error.message);

/** Full jitter: uniform in [0, min(cap, base * 2^(attempt-1))). */
export const durableOutboxBackoffMs = (
  attempts: number,
  backoff: {baseMs: number; maxMs: number},
  random: () => number = Math.random,
) => {
  const base = Math.max(1, backoff.baseMs);
  const ceiling = Math.min(
    Math.max(base, backoff.maxMs),
    base * 2 ** Math.min(Math.max(0, attempts - 1), 30),
  );
  return Math.floor(Math.min(1, Math.max(0, random())) * ceiling);
};

const validTimestamp = (value: unknown) =>
  typeof value === 'string' && Number.isFinite(Date.parse(value));

const validItem = (value: unknown): value is DurableOutboxItem<unknown> => {
  if (!value || typeof value !== 'object') return false;
  const item = value as DurableOutboxItem<unknown>;
  return (
    typeof item.id === 'string' &&
    item.id.length > 0 &&
    (item.generation === undefined ||
      (Number.isInteger(item.generation) && item.generation >= 0)) &&
    validTimestamp(item.createdAt) &&
    validTimestamp(item.expiresAt) &&
    validTimestamp(item.nextAttemptAt) &&
    (item.notBefore === undefined || validTimestamp(item.notBefore)) &&
    (item.paused === undefined || typeof item.paused === 'boolean') &&
    Number.isInteger(item.attempts) &&
    item.attempts >= 0 &&
    Object.prototype.hasOwnProperty.call(item, 'payload')
  );
};

const normalizeDelivery = <T, R>(
  result: DurableOutboxOutcome | DurableOutboxDelivery<T, R>,
): DurableOutboxDelivery<T, R> =>
  result && typeof result === 'object' && 'outcome' in result
    ? result
    : {outcome: result as DurableOutboxOutcome};

const terminal = (outcome: DurableOutboxOutcome) =>
  outcome === 'ack' || outcome === 'drop' || outcome === 'drop_group';

export const createDurableOutbox = <T, Ref = unknown>(
  policy: DurableOutboxPolicy<T, Ref>,
) => {
  const scopeOf = (boundary?: AccountSessionBoundary) => {
    if (!policy.accountScoped) return DEVICE_SCOPE;
    if (!boundary) throw new Error('DURABLE_OUTBOX_OWNER_REQUIRED');
    return boundary.scope;
  };
  const keyOf = (boundary?: AccountSessionBoundary) =>
    policy.accountScoped
      ? `${policy.namespace}:${scopeOf(boundary)}`
      : policy.namespace;
  const assertOwner = (boundary?: AccountSessionBoundary) => {
    if (policy.accountScoped && boundary) assertAccountSessionBoundary(boundary);
  };
  const withLock = <R>(
    boundary: AccountSessionBoundary | undefined,
    operation: () => Promise<R>,
  ): Promise<R> =>
    locks(keyOf(boundary), async () => {
      assertOwner(boundary);
      return operation();
    });
  /** The same lock without an owner check: only for retracting a write. */
  const withRawLock = <R>(
    boundary: AccountSessionBoundary | undefined,
    operation: () => Promise<R>,
  ): Promise<R> => locks(keyOf(boundary), operation);
  const groupOf = (item: DurableOutboxItem<T>) =>
    policy.groupOf ? policy.groupOf(item.payload) : '*';
  const referencesOf = (items: DurableOutboxItem<T>[]) =>
    policy.references
      ? items.flatMap(item => policy.references!.of(item.payload))
      : [];
  const referenceKeys = (items: DurableOutboxItem<T>[]) =>
    new Set(referencesOf(items).map(policy.references?.key ?? String));

  const decodeItem = (value: unknown): DurableOutboxItem<T> | null => {
    if (!validItem(value)) return null;
    if (!policy.decode) return value as DurableOutboxItem<T>;
    const payload = policy.decode(value.payload);
    return payload === null ? null : {...value, payload};
  };

  /** `null` when this outbox has never been written (or is empty). */
  const readStored = async (
    key: string,
  ): Promise<DurableOutboxItem<T>[] | null> => {
    const raw = await AsyncStorage.getItem(key);
    if (!raw) return null;
    let parsed: unknown;
    try {
      parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) throw new Error('INVALID_OUTBOX_SHAPE');
    } catch {
      // Do not let one damaged record poison all future enqueue attempts. Keep
      // the exact bytes first; if the device is full and the backup cannot be
      // written, throw and leave the original untouched rather than erasing it.
      await AsyncStorage.setItem(`${key}:corrupt`, raw);
      await AsyncStorage.removeItem(key);
      return [];
    }
    return parsed
      .map(decodeItem)
      .filter((item): item is DurableOutboxItem<T> => item !== null);
  };
  const readRaw = async (key: string) => (await readStored(key)) ?? [];

  /**
   * Commit a new list. References added by it are protected before the write;
   * references it no longer uses are released only after the write.
   */
  const commit = async (
    key: string,
    scope: string,
    previous: DurableOutboxItem<T>[],
    next: DurableOutboxItem<T>[],
  ) => {
    const refs = policy.references;
    const before = referenceKeys(previous);
    const nextRefs = referencesOf(next);
    const grows = Boolean(
      refs && nextRefs.some(ref => !before.has(refs.key(ref))),
    );
    if (refs && grows) {
      const union = new Map<string, Ref>();
      [...referencesOf(previous), ...nextRefs].forEach(ref =>
        union.set(refs.key(ref), ref),
      );
      await refs.retain([...union.values()], scope);
    }
    try {
      if (next.length) await AsyncStorage.setItem(key, JSON.stringify(next));
      else await AsyncStorage.removeItem(key);
    } catch (error) {
      if (refs && grows) {
        await refs.retain(referencesOf(previous), scope).catch(() => undefined);
      }
      throw error;
    }
    if (!refs) return;
    const after = new Set(nextRefs.map(refs.key));
    const released = new Map<string, Ref>();
    referencesOf(previous).forEach(ref => {
      if (!after.has(refs.key(ref))) released.set(refs.key(ref), ref);
    });
    if (!grows && !released.size) return;
    // Release a file only after the protected set no longer lists it; if the
    // registry cannot be narrowed now, the file stays until a later commit.
    const narrowed = await refs.retain(nextRefs, scope).then(
      () => true,
      () => false,
    );
    if (!narrowed) return;
    await Promise.all(
      [...released.values()].map(ref =>
        refs.release(ref).catch(() => undefined),
      ),
    );
  };

  const migrate = async (
    key: string,
    scope: string,
    current: DurableOutboxItem<T>[],
  ) => {
    if (!policy.legacy) return current;
    let records: LegacyOutboxRecord<T>[];
    try {
      records = await policy.legacy.read(scope);
    } catch {
      // The old record stays where it is; the next access tries again.
      return current;
    }
    if (!records.length) return current;
    const known = new Set(current.map(item => item.id));
    const imported = records
      .filter(record => !known.has(record.id))
      .sort((left, right) => (left.createdAt ?? 0) - (right.createdAt ?? 0))
      .map((record): DurableOutboxItem<T> => {
        const created = Number.isFinite(record.createdAt)
          ? Number(record.createdAt)
          : serverNowMs();
        const notBefore =
          record.notBefore && record.notBefore > serverNowMs()
            ? new Date(record.notBefore).toISOString()
            : undefined;
        return {
          id: record.id,
          generation: 1,
          payload: record.payload,
          createdAt: new Date(created).toISOString(),
          expiresAt: new Date(created + policy.ttlMs).toISOString(),
          attempts: 0,
          nextAttemptAt: notBefore ?? new Date(created).toISOString(),
          ...(record.paused ? {paused: true} : {}),
          ...(notBefore ? {notBefore} : {}),
        };
      });
    const next = [...imported, ...current].slice(-Math.max(1, policy.maxItems));
    // The new record is durable before any old record is removed: a crash
    // between the two re-imports by id instead of losing a learner's intent.
    await commit(key, scope, current, next);
    await policy.legacy.clear(scope);
    return next;
  };

  /**
   * Read, migrate and prune expired items; callers hold the lock. A deep load
   * (listing, enqueue) also retires items whose payload is no longer usable.
   */
  const load = async (boundary?: AccountSessionBoundary, deep = false) => {
    const key = keyOf(boundary);
    const scope = scopeOf(boundary);
    // A previous format can only exist while this outbox has no record: the
    // migration writes the new record before it retires the old one.
    const raw = await readStored(key);
    const stored = raw ?? (await migrate(key, scope, []));
    assertOwner(boundary);
    const now = serverNowMs();
    const inspected = await Promise.all(
      stored.map(async item => ({
        item,
        keep:
          Date.parse(item.expiresAt) > now &&
          (!deep || !policy.usable || (await policy.usable(item.payload))),
      })),
    );
    const kept = inspected
      .filter(entry => entry.keep)
      .map(entry => entry.item)
      .slice(-Math.max(1, policy.maxItems));
    if (kept.length !== stored.length) {
      await commit(key, scope, stored, kept);
    }
    assertOwner(boundary);
    return kept;
  };

  const write = async (
    boundary: AccountSessionBoundary | undefined,
    previous: DurableOutboxItem<T>[],
    next: DurableOutboxItem<T>[],
  ) => {
    await commit(keyOf(boundary), scopeOf(boundary), previous, next);
    assertOwner(boundary);
  };

  const list = ({boundary}: OwnerOptions = {}) =>
    withLock(boundary, () => load(boundary, true));

  /** Current items without the usability probe (cheap re-checks). */
  const peek = ({boundary}: OwnerOptions = {}) =>
    withLock(boundary, () => load(boundary));

  const get = async (id: string, options: OwnerOptions = {}) =>
    (await list(options)).find(item => item.id === id) ?? null;

  const enqueue = async (
    id: string,
    payload: T,
    {
      boundary,
      paused,
      afterWrite,
    }: OwnerOptions & {
      paused?: boolean;
      /** Runs under the lock right after the item is durable. */
      afterWrite?: (item: DurableOutboxItem<T>) => Promise<void>;
    } = {},
  ): Promise<DurableOutboxItem<T>> => {
    const item = await withLock(boundary, async () => {
      const current = await load(boundary, true);
      const previous = current.find(candidate => candidate.id === id);
      const nextPayload =
        previous && policy.coalesce
          ? policy.coalesce(previous.payload, payload)
          : payload;
      const now = serverNowMs();
      const timestamp = new Date(now).toISOString();
      const group = policy.groupOf?.(nextPayload);
      // Re-staging or adding a sibling never silently revokes a saved pause.
      const groupPaused =
        group !== undefined &&
        current.some(
          candidate =>
            candidate.paused === true && groupOf(candidate) === group,
        );
      const notBefore =
        previous?.notBefore && Date.parse(previous.notBefore) > now
          ? previous.notBefore
          : undefined;
      // A coalesced value continues the same logical write: it keeps the
      // retry clock, so a stream of new samples cannot bypass the backoff.
      const continued = previous && policy.coalesce ? previous : undefined;
      const next: DurableOutboxItem<T> = {
        id,
        generation: Math.max(0, previous?.generation || 0) + 1,
        payload: nextPayload,
        createdAt: timestamp,
        expiresAt: new Date(now + Math.max(60_000, policy.ttlMs)).toISOString(),
        attempts: continued?.attempts ?? 0,
        nextAttemptAt: notBefore ?? continued?.nextAttemptAt ?? timestamp,
        ...(paused || groupPaused ? {paused: true} : {}),
        ...(notBefore ? {notBefore} : {}),
      };
      const items = [
        ...current.filter(candidate => candidate.id !== id),
        next,
      ].slice(-Math.max(1, policy.maxItems));
      await commit(keyOf(boundary), scopeOf(boundary), current, items);
      await afterWrite?.(next);
      return next;
    });
    if (policy.retractOnBoundaryChange && boundary) {
      try {
        assertAccountSessionBoundary(boundary);
      } catch (error) {
        // Logout/account replacement can clear this scope while the native
        // write was in flight. Retract only this exact generation; a newer
        // write at the same key must never be erased by this one.
        await withRawLock(boundary, async () => {
          const key = keyOf(boundary);
          const current = await readRaw(key);
          const next = current.filter(
            candidate =>
              candidate.id !== id || candidate.generation !== item.generation,
          );
          if (next.length !== current.length) {
            await commit(key, scopeOf(boundary), current, next);
          }
        }).catch(() => undefined);
        throw error;
      }
    }
    return item;
  };

  const mutate = (
    boundary: AccountSessionBoundary | undefined,
    change: (items: DurableOutboxItem<T>[]) => DurableOutboxItem<T>[] | null,
    repairReferences = false,
  ) =>
    withLock(boundary, async () => {
      const current = await load(boundary);
      const next = change(current);
      if (!next) {
        // An explicit removal that found nothing still re-asserts the exact
        // protected set, repairing a registry that drifted from storage.
        if (repairReferences && policy.references) {
          await policy.references
            .retain(referencesOf(current), scopeOf(boundary))
            .catch(() => undefined);
        }
        return false;
      }
      await write(boundary, current, next);
      return true;
    });

  /** Delivery progress of the same intent; keeps its generation. */
  const update = async (
    id: string,
    change: (payload: T) => T,
    {boundary, generation}: OwnerOptions & {generation?: number} = {},
  ) => {
    let updated: DurableOutboxItem<T> | null = null;
    await mutate(boundary, items => {
      const index = items.findIndex(
        item =>
          item.id === id &&
          (generation === undefined || item.generation === generation),
      );
      if (index < 0) return null;
      updated = {...items[index], payload: change(items[index].payload)};
      const next = [...items];
      next[index] = updated;
      return next;
    });
    return updated as DurableOutboxItem<T> | null;
  };

  const remove = (
    id: string,
    {boundary, generation}: OwnerOptions & {generation?: number} = {},
  ) =>
    mutate(boundary, items => {
      const next = items.filter(
        item =>
          item.id !== id ||
          (generation !== undefined && item.generation !== generation),
      );
      return next.length === items.length ? null : next;
    }, true);

  const removeGroup = (group: string, {boundary}: OwnerOptions = {}) =>
    mutate(
      boundary,
      items => {
        const next = items.filter(item => groupOf(item) !== group);
        return next.length === items.length ? null : next;
      },
      true,
    );

  /** Durable local intent; returns how many items changed. */
  const setPaused = async (
    group: string | undefined,
    paused: boolean,
    {boundary}: OwnerOptions = {},
  ) => {
    let changed = 0;
    await mutate(boundary, items => {
      changed = 0;
      const next = items.map(item => {
        if (group !== undefined && groupOf(item) !== group) return item;
        if (group === undefined && Boolean(item.paused) === paused) return item;
        changed += 1;
        return {...item, paused};
      });
      return changed ? next : null;
    });
    return changed;
  };

  const clear = ({boundary}: OwnerOptions = {}) =>
    mutate(boundary, items => (items.length ? [] : null), true);

  /** Apply one delivery outcome to the exact generation that was sent. */
  const apply = (
    boundary: AccountSessionBoundary | undefined,
    sent: DurableOutboxItem<T>,
    delivery: DurableOutboxDelivery<T, unknown>,
    now: () => number,
    random: () => number,
  ) =>
    withLock(boundary, async () => {
      const current = await load(boundary);
      const {outcome} = delivery;
      if (outcome === 'drop_group') {
        const group = groupOf(sent);
        const next = current.filter(item => groupOf(item) !== group);
        if (next.length !== current.length) await write(boundary, current, next);
        return true;
      }
      const index = current.findIndex(
        item =>
          item.id === sent.id &&
          (item.generation || 0) === (sent.generation || 0),
      );
      if (index < 0) return false;
      const item = current[index];
      const next = [...current];
      if (outcome === 'ack' || outcome === 'drop') {
        if (delivery.commit && !(await delivery.commit(item))) return true;
        next.splice(index, 1);
      } else if (outcome === 'retry' || typeof outcome === 'object') {
        const attempts = item.attempts + 1;
        const at =
          typeof outcome === 'object'
            ? outcome.retryAt
            : now() + durableOutboxBackoffMs(attempts, policy.backoff, random);
        next[index] = {
          ...item,
          attempts,
          nextAttemptAt: new Date(at).toISOString(),
          ...(typeof outcome === 'object'
            ? {notBefore: new Date(at).toISOString()}
            : {}),
        };
      } else if (outcome === 'pause') {
        if (item.paused) return true;
        next[index] = {...item, paused: true};
      } else {
        return true;
      }
      await write(boundary, current, next);
      return true;
    });

  const run = async <R>(
    item: DurableOutboxItem<T>,
    deliver: DurableOutboxDeliver<T, R>,
    classify = policy.classify,
  ): Promise<{delivery: DurableOutboxDelivery<T, R>; error?: unknown}> => {
    try {
      return {delivery: normalizeDelivery(await deliver(item))};
    } catch (error) {
      if (isOutboxAbort(error)) throw error;
      return {
        delivery: {outcome: classify ? classify(error) : 'retry'},
        error,
      };
    }
  };

  /**
   * Foreground delivery of one item (a learner tap). The outcome is applied
   * like a flush; a thrown error is classified, applied and rethrown.
   */
  const deliver = async <R>(
    item: DurableOutboxItem<T>,
    deliverItem: DurableOutboxDeliver<T, R>,
    {
      boundary,
      resume = false,
      now = serverNowMs,
    }: OwnerOptions & {resume?: boolean; now?: () => number} = {},
  ): Promise<
    | {state: 'paused'}
    | {
        state: 'delivered';
        outcome: DurableOutboxOutcome;
        value?: R;
        /** Local retirement of a final outcome: done, failed or still running. */
        retirement?: 'retired' | 'failed' | 'pending';
      }
  > => {
    const stored = await withLock(boundary, async () => {
      const current = await load(boundary);
      const index = current.findIndex(candidate => candidate.id === item.id);
      if (index < 0 || !current[index].paused) return current[index] ?? null;
      if (!resume) return 'paused' as const;
      const next = [...current];
      next[index] = {...current[index], paused: false};
      await write(boundary, current, next);
      return next[index];
    });
    if (stored === 'paused') return {state: 'paused'};
    const sent = stored ?? item;
    const {delivery, error} = await run(sent, deliverItem);
    const applied = apply(boundary, sent, delivery, now, Math.random);
    let retirement: 'retired' | 'failed' | 'pending' | undefined;
    if (terminal(delivery.outcome)) {
      // The remote result is already final. A failed local retirement keeps
      // the item (same id) for an idempotent replay; it never fails delivery,
      // and the result is not held behind slow native storage (the lock still
      // orders every later operation behind this retirement).
      retirement = await settleWithin(
        applied.then(
          () => 'retired' as const,
          () => 'failed' as const,
        ),
        'pending' as const,
      );
    } else {
      await applied;
    }
    if (error !== undefined) throw error;
    if (delivery.outcome === 'ack') {
      policy.onDelivered?.(sent.payload, scopeOf(boundary));
    }
    return {
      state: 'delivered',
      outcome: delivery.outcome,
      value: delivery.value,
      ...(retirement ? {retirement} : {}),
    };
  };

  const flushOnce = async <R>({
    boundary,
    deliver: deliverItem,
    deliverBatch,
    maxBatch = policy.maxItems,
    ids,
    ignoreBackoff = false,
    redeliverSuperseded = true,
    awaitRetirement = true,
    classify = policy.classify,
    now = serverNowMs,
    random = Math.random,
  }: FlushOptions<T, R>): Promise<DurableOutboxFlushReport<T, R>> => {
    const report: DurableOutboxFlushReport<T, R> = {attempted: 0, results: []};
    const snapshot = await list({boundary});
    const wanted = ids ? new Set(ids) : null;
    const due = (item: DurableOutboxItem<T>) =>
      !item.paused &&
      (!wanted || wanted.has(item.id)) &&
      (!item.notBefore || Date.parse(item.notBefore) <= now()) &&
      (ignoreBackoff || Date.parse(item.nextAttemptAt) <= now());
    const eligible = snapshot.filter(due).slice(0, Math.max(1, maxBatch));
    if (!eligible.length) return report;

    if (deliverBatch) {
      let outcome: DurableOutboxOutcome;
      try {
        outcome = await deliverBatch(eligible.map(item => item.payload));
      } catch (error) {
        if (isOutboxAbort(error)) throw error;
        outcome = classify ? classify(error) : 'retry';
      }
      report.attempted = eligible.length;
      for (const item of eligible) {
        const applied = await apply(boundary, item, {outcome}, now, random);
        report.results.push({item, outcome, applied});
      }
      return report;
    }

    const blocked = new Set<string>();
    const redelivered = new Map<string, number>();
    const pending = [...eligible];
    while (pending.length) {
      const candidate = pending.shift()!;
      const group = groupOf(candidate);
      if (blocked.has(group)) continue;
      assertOwner(boundary);
      // Re-check under the lock: never deliver from a stale snapshot (a pause
      // or completion may have landed since the list was read).
      const current = (await peek({boundary})).find(
        item =>
          item.id === candidate.id && item.generation === candidate.generation,
      );
      if (!current || !due(current)) continue;
      report.attempted += 1;
      const {delivery} = await run(current, deliverItem, classify);
      let applied = true;
      try {
        const applying = apply(boundary, current, delivery, now, random);
        if (!awaitRetirement && terminal(delivery.outcome)) {
          applying.catch(() => undefined);
        } else {
          applied = await applying;
        }
      } catch (error) {
        // A final remote decision whose local retirement failed keeps the
        // same id for an idempotent replay; it does not fail the pass.
        if (!terminal(delivery.outcome) || isOutboxAbort(error)) throw error;
      }
      report.results.push({
        item: current,
        outcome: delivery.outcome,
        value: delivery.value,
        applied,
      });
      const {outcome} = delivery;
      if (outcome === 'ack' && applied) {
        policy.onDelivered?.(current.payload, scopeOf(boundary));
      }
      if (outcome === 'drop_group' || !terminal(outcome)) {
        blocked.add(group);
        continue;
      }
      if (!applied && redeliverSuperseded) {
        // A newer generation replaced the item while it was in flight; it
        // carries the newest coalesced value and belongs to this pass.
        const count = (redelivered.get(current.id) || 0) + 1;
        redelivered.set(current.id, count);
        const newer = (await peek({boundary})).find(
          item => item.id === current.id,
        );
        if (newer && count <= MAX_REDELIVERIES && due(newer)) {
          pending.unshift(newer);
        }
      }
    }
    return report;
  };

  /** Drain due items. Concurrent full flushes of one key share one pass. */
  const flush = <R>(
    options: FlushOptions<T, R>,
  ): Promise<DurableOutboxFlushReport<T, R>> => {
    const key = keyOf(options.boundary);
    if (options.ids) return flushOnce(options);
    const epoch = options.boundary?.epoch;
    const existing = flights.get(key);
    if (existing && existing.epoch === epoch && !options.afterCurrent) {
      return existing.promise as Promise<DurableOutboxFlushReport<T, R>>;
    }
    // A newer session epoch or a new edit needs its own probe after the
    // running pass, not the result of a request that started before it.
    const start = () => flushOnce(options);
    const flight: Promise<DurableOutboxFlushReport<T, R>> = (
      existing
        ? existing.promise.then(start, start)
        : Promise.resolve().then(start)
    ).finally(() => {
      if (flights.get(key)?.promise === flight) flights.delete(key);
    });
    flights.set(key, {epoch, promise: flight});
    return flight;
  };

  return {
    policy,
    storageKey: keyOf,
    enqueue,
    update,
    get,
    list,
    peek,
    remove,
    removeGroup,
    setPaused,
    clear,
    deliver,
    flush,
  };
};

export type DurableOutbox<T, Ref = unknown> = ReturnType<
  typeof createDurableOutbox<T, Ref>
>;

export const resetDurableOutboxForTests = () => {
  locks = createKeyedAsyncQueue();
  flights = new Map();
};
