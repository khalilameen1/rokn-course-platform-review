import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  removeItem,
  saveItem,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {secureRandomUuid} from '../../utils/secureRandom';
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

let storageTail: Promise<void> = Promise.resolve();

const serializeStorageMutation = <T>(
  operation: () => Promise<T>,
): Promise<T> => {
  const result = storageTail.then(operation, operation);
  storageTail = result.then(
    () => undefined,
    () => undefined,
  );
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

const getOrCreateAttemptKey = <TIntent extends AttemptIntent>(
  spec: AttemptSpec<TIntent>,
  boundary: AccountSessionBoundary,
): Promise<string> =>
  serializeStorageMutation(async () => {
    assertAccountSessionBoundary(boundary);
    const key = await storageKey(spec, boundary);
    const storedKey = await readAttempt(key, spec.intent);
    if (storedKey) return storedKey;

    const idempotencyKey = secureRandomUuid();
    const persisted = await saveItem(key, {...spec.intent, idempotencyKey});
    if (!persisted) throw new Error(spec.unavailableCode);
    return idempotencyKey;
  });

const clearAttemptKey = <TIntent extends AttemptIntent>(
  spec: AttemptSpec<TIntent>,
  expectedIdempotencyKey: string,
  boundary: AccountSessionBoundary,
) =>
  serializeStorageMutation(async () => {
    assertAccountSessionBoundary(boundary);
    const key = await storageKey(spec, boundary);
    const storedKey = await readAttempt(key, spec.intent);
    if (storedKey === expectedIdempotencyKey) await removeItem(key);
  });

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
