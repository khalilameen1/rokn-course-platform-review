import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractApiToken,
  getItem,
  AsyncKeys,
} from '../constants/helpers';
import {migrateGuestLearningState} from '../components/VideoPlayer/courseLearningApi';
import {migrateGuestProductFeedback} from './productFeedback';

const PENDING_GUEST_MIGRATION_KEY = '@rokn/pending-guest-migration/v1';
const SEARCH_HISTORY_KEY = '@rokn/search-history/v1';
const HOME_SCROLL_KEY = '@rokn/home-scroll/v1';
const PRODUCT_EVENTS_KEY = '@rokn/product-events/v1';
const ATTACHMENT_PROMPT_KEY = 'course-attachment-prompt-seen:v1';
const ACCOUNT_PREFERENCES = [
  'PREF_WATCH_HISTORY',
  'PREF_NOTIFICATIONS',
  'PREF_MARKETING_NOTIFICATIONS',
];

type PendingGuestMigration = {
  guestScope: string;
  accountScope?: string;
};

const scopedKey = (base: string, scope: string) => `${base}:${scope}`;

const validScope = (value: string) => /^[a-z0-9_-]+$/i.test(value);

const retireCorruptGuestValue = async (key: string, raw: string) => {
  // Preserve bounded evidence before removing a value that cannot be merged.
  // If storage is full, setItem rejects and the source plus pending migration
  // remain untouched for a later launch.
  await AsyncStorage.setItem(`${key}:corrupt`, raw.slice(0, 8192));
  await AsyncStorage.removeItem(key);
};

const parsePendingMigration = (raw: string): PendingGuestMigration | null => {
  if (validScope(raw)) return {guestScope: raw};
  try {
    const parsed = JSON.parse(raw) as Partial<PendingGuestMigration>;
    if (!validScope(String(parsed.guestScope || ''))) return null;
    const accountScope = String(parsed.accountScope || '');
    return {
      guestScope: String(parsed.guestScope),
      ...(validScope(accountScope) ? {accountScope} : {}),
    };
  } catch {
    return null;
  }
};

const accountPendingKey = (accountScope: string) =>
  scopedKey(PENDING_GUEST_MIGRATION_KEY, accountScope);

const readPendingMigration = async (
  accountScope: string,
): Promise<{key: string; value: PendingGuestMigration} | null> => {
  const scopedMigrationKey = accountPendingKey(accountScope);
  const [[, scopedRaw], [, unboundRaw]] = await AsyncStorage.multiGet([
    scopedMigrationKey,
    PENDING_GUEST_MIGRATION_KEY,
  ]);
  const scoped = parsePendingMigration(scopedRaw || '');
  if (
    scoped &&
    (!scoped.accountScope || scoped.accountScope === accountScope)
  ) {
    return {key: scopedMigrationKey, value: scoped};
  }
  const unbound = parsePendingMigration(unboundRaw || '');
  if (
    unbound &&
    (!unbound.accountScope || unbound.accountScope === accountScope)
  ) {
    return {key: PENDING_GUEST_MIGRATION_KEY, value: unbound};
  }
  return null;
};

export const stageGuestAccountMigration = async (guestScope: string) => {
  if (!validScope(guestScope)) return;
  const existingRaw =
    (await AsyncStorage.getItem(PENDING_GUEST_MIGRATION_KEY)) || '';
  const existing = parsePendingMigration(existingRaw);
  // Older builds stored an already-bound retry in the single global slot.
  // Preserve it under its owner, then free the slot for this guest journey.
  if (existing?.accountScope && validScope(existing.accountScope)) {
    await AsyncStorage.setItem(
      accountPendingKey(existing.accountScope),
      JSON.stringify(existing),
    );
  }
  await AsyncStorage.setItem(
    PENDING_GUEST_MIGRATION_KEY,
    JSON.stringify({guestScope} satisfies PendingGuestMigration),
  );
};

const mergeStringList = async (
  baseKey: string,
  guestScope: string,
  accountScope: string,
  limit: number,
) => {
  const sourceKey = scopedKey(baseKey, guestScope);
  const targetKey = scopedKey(baseKey, accountScope);
  const [[, sourceRaw], [, targetRaw]] = await AsyncStorage.multiGet([
    sourceKey,
    targetKey,
  ]);
  if (!sourceRaw) return;
  let source: unknown;
  let target: unknown;
  try {
    source = JSON.parse(sourceRaw);
    target = targetRaw ? JSON.parse(targetRaw) : [];
  } catch {
    await retireCorruptGuestValue(sourceKey, sourceRaw);
    return;
  }
  const values = [
    ...(Array.isArray(target) ? target : []),
    ...(Array.isArray(source) ? source : []),
  ]
    .filter(
      (item): item is string =>
        typeof item === 'string' && Boolean(item.trim()),
    )
    .map(item => item.trim());
  await AsyncStorage.setItem(
    targetKey,
    JSON.stringify(Array.from(new Set(values)).slice(0, limit)),
  );
  await AsyncStorage.removeItem(sourceKey);
};

const mergeOutbox = async (guestScope: string, accountScope: string) => {
  const sourceKey = scopedKey(PRODUCT_EVENTS_KEY, guestScope);
  const targetKey = scopedKey(PRODUCT_EVENTS_KEY, accountScope);
  const [[, sourceRaw], [, targetRaw]] = await AsyncStorage.multiGet([
    sourceKey,
    targetKey,
  ]);
  if (!sourceRaw) return;
  let source: unknown;
  let target: unknown;
  try {
    source = JSON.parse(sourceRaw);
    target = targetRaw ? JSON.parse(targetRaw) : [];
  } catch {
    await retireCorruptGuestValue(sourceKey, sourceRaw);
    return;
  }
  const byId = new Map<string, unknown>();
  [
    ...(Array.isArray(source) ? source : []),
    ...(Array.isArray(target) ? target : []),
  ].forEach(item => {
    const id =
      item && typeof item === 'object' && typeof item.id === 'string'
        ? item.id
        : '';
    if (id) byId.set(id, item);
  });
  await AsyncStorage.setItem(
    targetKey,
    JSON.stringify(Array.from(byId.values()).slice(-50)),
  );
  await AsyncStorage.removeItem(sourceKey);
};

const moveHomeScroll = async (guestScope: string, accountScope: string) => {
  const sourceKey = scopedKey(HOME_SCROLL_KEY, guestScope);
  const targetKey = scopedKey(HOME_SCROLL_KEY, accountScope);
  const [[, sourceRaw], [, targetRaw]] = await AsyncStorage.multiGet([
    sourceKey,
    targetKey,
  ]);
  if (sourceRaw === null) return;
  let sourceOffset: unknown;
  try {
    sourceOffset = JSON.parse(sourceRaw);
  } catch {
    await retireCorruptGuestValue(sourceKey, sourceRaw);
    return;
  }
  const normalizedOffset = Number(sourceOffset);
  if (!Number.isFinite(normalizedOffset) || normalizedOffset < 0) {
    await retireCorruptGuestValue(sourceKey, sourceRaw);
    return;
  }
  let targetIsValid = false;
  if (targetRaw !== null) {
    try {
      const targetOffset = Number(JSON.parse(targetRaw));
      targetIsValid = Number.isFinite(targetOffset) && targetOffset >= 0;
      if (!targetIsValid) {
        await retireCorruptGuestValue(targetKey, targetRaw);
      }
    } catch {
      await retireCorruptGuestValue(targetKey, targetRaw);
    }
  }
  // A returning account keeps its own last position. A first login adopts the
  // anonymous exploration point without carrying any search text with it.
  if (!targetIsValid) {
    await AsyncStorage.setItem(targetKey, JSON.stringify(normalizedOffset));
  }
  await AsyncStorage.removeItem(sourceKey);
};

const copyPreferences = async (guestScope: string, accountScope: string) => {
  for (const baseKey of ACCOUNT_PREFERENCES) {
    const sourceKey = scopedKey(baseKey, guestScope);
    const targetKey = scopedKey(baseKey, accountScope);
    const [[, source], [, target]] = await AsyncStorage.multiGet([
      sourceKey,
      targetKey,
    ]);
    if (source !== null && target === null) {
      try {
        JSON.parse(source);
        await AsyncStorage.setItem(targetKey, source);
      } catch {
        await retireCorruptGuestValue(sourceKey, source);
        continue;
      }
    }
    if (source !== null) await AsyncStorage.removeItem(sourceKey);
  }
};

const moveAttachmentPromptReceipts = async (
  guestScope: string,
  accountScope: string,
) => {
  const sourcePrefix = `${ATTACHMENT_PROMPT_KEY}:${guestScope}:`;
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(sourcePrefix),
  );
  for (const sourceKey of keys) {
    const suffix = sourceKey.slice(sourcePrefix.length);
    const targetKey = `${ATTACHMENT_PROMPT_KEY}:${accountScope}:${suffix}`;
    const sourceValue = await AsyncStorage.getItem(sourceKey);
    if (sourceValue !== null)
      await AsyncStorage.setItem(targetKey, sourceValue);
    await AsyncStorage.removeItem(sourceKey);
  }
};

/**
 * Moves every portable public-preview action to the newly authenticated
 * account. Local state is completed before navigation; remote feedback writes
 * can continue in the background and remain retryable across process death.
 */
const resumeGuestAccountMigration = async (
  syncRemoteFeedback = true,
): Promise<boolean> => {
  if (!extractApiToken(await getItem(AsyncKeys.USER_DATA))) return false;
  const accountBoundary = await captureAccountSessionBoundary();
  const accountScope = accountBoundary.scope;
  const record = await readPendingMigration(accountScope);
  assertAccountSessionBoundary(accountBoundary);
  if (!record) return false;
  const pending = record.value;
  const {guestScope} = pending;
  if (!validScope(accountScope) || accountScope === guestScope) return false;
  // Once a guest journey is attached to an account it can never leak into a
  // different account if the first learner logs out during a network retry.
  if (pending.accountScope && pending.accountScope !== accountScope)
    return false;
  if (!pending.accountScope) {
    const boundKey = accountPendingKey(accountScope);
    await AsyncStorage.setItem(
      boundKey,
      JSON.stringify({
        guestScope,
        accountScope,
      } satisfies PendingGuestMigration),
    );
    if (record.key !== boundKey) await AsyncStorage.removeItem(record.key);
    record.key = boundKey;
    pending.accountScope = accountScope;
  }

  if (!(await migrateGuestLearningState(guestScope, accountBoundary)))
    return false;
  await Promise.all([
    mergeStringList(SEARCH_HISTORY_KEY, guestScope, accountScope, 7),
    moveHomeScroll(guestScope, accountScope),
    mergeOutbox(guestScope, accountScope),
    copyPreferences(guestScope, accountScope),
    moveAttachmentPromptReceipts(guestScope, accountScope),
    migrateGuestProductFeedback(
      guestScope,
      accountScope,
      false,
      accountBoundary,
    ),
  ]);
  assertAccountSessionBoundary(accountBoundary);

  if (!syncRemoteFeedback) return true;
  const feedbackMigrated = await migrateGuestProductFeedback(
    guestScope,
    accountScope,
    true,
    accountBoundary,
  );
  assertAccountSessionBoundary(accountBoundary);
  if (!feedbackMigrated) return false;
  await AsyncStorage.multiRemove([
    record.key,
    `@rokn/course-player/v3:${guestScope}`,
  ]);
  return true;
};

let migrationQueue: Promise<unknown> = Promise.resolve();

export const resumePendingGuestAccountMigration = (
  syncRemoteFeedback = true,
): Promise<boolean> => {
  const operation = migrationQueue.then(
    () => resumeGuestAccountMigration(syncRemoteFeedback),
    () => resumeGuestAccountMigration(syncRemoteFeedback),
  );
  migrationQueue = operation.catch(() => undefined);
  return operation;
};

/**
 * Finish the one guest-to-account handoff in order. Local learning data moves
 * first so login never waits on feedback sync; the remote pass then drains the
 * remaining outbox. Every login surface calls this owner instead of carrying
 * its own two-step migration recipe.
 */
export const resumeCompleteGuestAccountMigration =
  async (): Promise<boolean> => {
    await resumePendingGuestAccountMigration(false);
    return resumePendingGuestAccountMigration(true);
  };
