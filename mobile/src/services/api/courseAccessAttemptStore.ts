import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  removeItem,
  saveItem,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {secureRandomUuid} from '../../utils/secureRandom';
import {settleWithin} from '../../utils/settleWithin';
import {isApiRecord} from './common';

type AttemptValue = string | number;
type AttemptIntent = Record<string, AttemptValue>;

type AttemptSpec<TIntent extends AttemptIntent> = {
  namespace: string;
  path: AttemptValue[];
  intent: TIntent;
  unavailableCode: string;
};

const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

const storageTails = new Map<string, Promise<void>>();

const serializeStorageMutation = <T>(
  key: string,
  operation: () => Promise<T>,
): Promise<T> => {
  const previous = storageTails.get(key) ?? Promise.resolve();
  const result = previous.then(operation, operation);
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  storageTails.set(key, tail);
  void tail.then(() => {
    if (storageTails.get(key) === tail) storageTails.delete(key);
  });
  return result;
};

const storageKey = <TIntent extends AttemptIntent>(
  spec: AttemptSpec<TIntent>,
  boundary: AccountSessionBoundary,
) =>
  accountScopedStorageKey(
    `${spec.namespace}:${spec.path
      .map(value => encodeURIComponent(String(value || 'none')))
      .join(':')}`,
    boundary,
  );

const matchesIntent = <TIntent extends AttemptIntent>(
  stored: Record<string, unknown>,
  intent: TIntent,
) =>
  Object.entries(intent).every(([field, expected]) =>
    typeof expected === 'number'
      ? Number(stored[field]) === expected
      : String(stored[field] ?? '') === expected,
  );

const readAttempt = async <TIntent extends AttemptIntent>(
  key: string,
  intent: TIntent,
): Promise<string | null> => {
  const raw = await AsyncStorage.getItem(key);
  if (raw === null) return null;

  try {
    const stored: unknown = JSON.parse(raw);
    if (!isApiRecord(stored) || !matchesIntent(stored, intent)) {
      throw new Error('INVALID_ATTEMPT');
    }
    const idempotencyKey = String(stored.idempotencyKey ?? '').toLowerCase();
    if (!UUID_PATTERN.test(idempotencyKey)) {
      throw new Error('INVALID_ATTEMPT');
    }
    return idempotencyKey;
  } catch {
    await removeItem(key);
    return null;
  }
};

const getOrCreateAttemptKey = async <TIntent extends AttemptIntent>(
  spec: AttemptSpec<TIntent>,
  boundary: AccountSessionBoundary,
): Promise<string> => {
  const key = await storageKey(spec, boundary);
  return serializeStorageMutation(key, async () => {
    assertAccountSessionBoundary(boundary);
    const storedKey = await readAttempt(key, spec.intent);
    if (storedKey) return storedKey;

    const idempotencyKey = secureRandomUuid();
    const persisted = await saveItem(key, {...spec.intent, idempotencyKey});
    if (!persisted) throw new Error(spec.unavailableCode);
    return idempotencyKey;
  });
};

const clearAttemptKey = async <TIntent extends AttemptIntent>(
  spec: AttemptSpec<TIntent>,
  expectedIdempotencyKey: string,
  boundary: AccountSessionBoundary,
) => {
  const key = await storageKey(spec, boundary);
  const cleanup = serializeStorageMutation(key, async () => {
    assertAccountSessionBoundary(boundary);
    try {
      const storedKey = await readAttempt(key, spec.intent);
      assertAccountSessionBoundary(boundary);
      if (storedKey === expectedIdempotencyKey) await removeItem(key);
    } catch {
      // The server has confirmed access. Retaining its original request key
      // is recoverable; replacing that result with a local error is not.
      assertAccountSessionBoundary(boundary);
      void import('../operationalTelemetry')
        .then(({reportClientError}) =>
          reportClientError(new Error('COURSE_ACCESS_TERMINAL_CLEANUP'), {
            source: 'course_access_terminal_cleanup',
          }),
        )
        .catch(() => undefined);
    }
    assertAccountSessionBoundary(boundary);
  });
  // Access is already confirmed. Its optional cleanup cannot hold that result
  // or unrelated purchases open. Keep the real per-intent queue intact so a
  // late removal still precedes any new request stored under this same key.
  await settleWithin(cleanup, undefined);
  assertAccountSessionBoundary(boundary);
};

type CoursePurchaseIntent = {
  courseId: number;
  accessPlanCode: string;
  couponCode: string;
};

const purchaseSpec = (intent: CoursePurchaseIntent) => ({
  namespace: '@rokn/course-purchase-attempt/v2',
  path: [intent.courseId, intent.accessPlanCode, intent.couponCode],
  intent,
  unavailableCode: 'COURSE_PURCHASE_IDEMPOTENCY_UNAVAILABLE',
});

export const getOrCreateCoursePurchaseAttemptKey = (
  intent: CoursePurchaseIntent,
  boundary: AccountSessionBoundary,
) => getOrCreateAttemptKey(purchaseSpec(intent), boundary);

export const clearCoursePurchaseAttemptKey = (
  intent: CoursePurchaseIntent,
  expectedIdempotencyKey: string,
  boundary: AccountSessionBoundary,
) => clearAttemptKey(purchaseSpec(intent), expectedIdempotencyKey, boundary);

type CourseUpgradeIntent = {
  courseId: number;
  targetPlanCode: string;
  expectedPrice: number;
};

const upgradeSpec = (intent: CourseUpgradeIntent) => ({
  namespace: '@rokn/course-upgrade-attempt/v1',
  path: [intent.courseId, intent.targetPlanCode, intent.expectedPrice],
  intent,
  unavailableCode: 'COURSE_UPGRADE_IDEMPOTENCY_UNAVAILABLE',
});

export const getOrCreateCourseUpgradeAttemptKey = (
  intent: CourseUpgradeIntent,
  boundary: AccountSessionBoundary,
) => getOrCreateAttemptKey(upgradeSpec(intent), boundary);

export const clearCourseUpgradeAttemptKey = (
  intent: CourseUpgradeIntent,
  expectedIdempotencyKey: string,
  boundary: AccountSessionBoundary,
) => clearAttemptKey(upgradeSpec(intent), expectedIdempotencyKey, boundary);
