import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getCurrentAccountStorageScope,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import type {CourseLearningData, CourseLearningModule} from '../types';
import {asArray} from './shared';

const PLAYER_STATE_KEY = '@rokn/course-player/v3';
export const WATCH_HISTORY_ENABLED_KEY = 'PREF_WATCH_HISTORY';
const MAX_LOCAL_RESUME_ENTRIES = 300;
const playerStateQueues = new Map<string, Promise<unknown>>();

type PersistedPlayerState = {
  positions: Record<string, number>;
  lastWatchedAt: Record<string, string>;
  completedSections: string[];
  savedLessons: string[];
  savedFolderLessons: Record<string, string[]>;
  activityDays: string[];
};

const EMPTY_STATE: PersistedPlayerState = {
  positions: {},
  lastWatchedAt: {},
  completedSections: [],
  savedLessons: [],
  savedFolderLessons: {},
  activityDays: [],
};

const isAccountBoundaryError = (error: unknown) =>
  error instanceof Error && error.message === 'ACCOUNT_CHANGED_DURING_REQUEST';

const remoteIds = (value: unknown): string[] =>
  Array.from(
    new Set(
      asArray<unknown>(value)
        .filter((item): item is string => typeof item === 'string')
        .map(item => item.trim())
        .filter(item => /^\d{1,18}$/.test(item) && Number(item) > 0),
    ),
  );

const savedFolderMemberships = (value: unknown): Record<string, string[]> => {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
  return Object.fromEntries(
    Object.entries(value)
      .filter(
        ([folderId]) => /^\d{1,18}$/.test(folderId) && Number(folderId) > 0,
      )
      .map(([folderId, lessons]) => [folderId, remoteIds(lessons)])
      .filter(([, lessons]) => lessons.length > 0),
  );
};

const compactResumeState = (
  rawPositions: unknown,
  rawLastWatchedAt: unknown,
) => {
  const positionsSource =
    rawPositions &&
    typeof rawPositions === 'object' &&
    !Array.isArray(rawPositions)
      ? (rawPositions as Record<string, unknown>)
      : {};
  const watchedSource =
    rawLastWatchedAt &&
    typeof rawLastWatchedAt === 'object' &&
    !Array.isArray(rawLastWatchedAt)
      ? (rawLastWatchedAt as Record<string, unknown>)
      : {};
  const keys = Array.from(
    new Set([...Object.keys(positionsSource), ...Object.keys(watchedSource)]),
  )
    .map((key, index) => ({
      index,
      key,
      watchedAt: Date.parse(String(watchedSource[key] || '')) || 0,
    }))
    .sort(
      (left, right) =>
        right.watchedAt - left.watchedAt || right.index - left.index,
    )
    .slice(0, MAX_LOCAL_RESUME_ENTRIES);

  const positions: Record<string, number> = {};
  const lastWatchedAt: Record<string, string> = {};
  keys.forEach(({key, watchedAt}) => {
    const seconds = Number(positionsSource[key]);
    if (Number.isFinite(seconds) && seconds >= 0) {
      positions[key] = seconds;
    }
    if (watchedAt > 0) {
      lastWatchedAt[key] = new Date(watchedAt).toISOString();
    }
  });
  return {positions, lastWatchedAt};
};

const compactPlayerState = (
  state: PersistedPlayerState,
): PersistedPlayerState => ({
  ...state,
  ...compactResumeState(state.positions, state.lastWatchedAt),
  activityDays: Array.from(new Set(state.activityDays)).slice(-60),
});

export const readPlayerState = async (
  scopedStorageKey?: string,
  accountBoundary?: AccountSessionBoundary,
): Promise<PersistedPlayerState> => {
  const boundary =
    accountBoundary ||
    (scopedStorageKey ? undefined : await captureAccountSessionBoundary());
  const storageKey =
    scopedStorageKey ||
    (await accountScopedStorageKey(PLAYER_STATE_KEY, boundary));
  let value: string | null;
  try {
    value = await AsyncStorage.getItem(storageKey);
  } catch {
    return {...EMPTY_STATE};
  }
  if (boundary) assertAccountSessionBoundary(boundary);
  try {
    if (!value) {
      return {...EMPTY_STATE};
    }
    const parsed = JSON.parse(value);
    const compactResume = compactResumeState(
      parsed?.positions,
      parsed?.lastWatchedAt,
    );
    const folderMemberships = savedFolderMemberships(
      parsed?.savedFolderLessons,
    );
    const state = compactPlayerState({
      ...compactResume,
      completedSections: asArray(parsed?.completedSections),
      savedLessons: Array.from(
        new Set([
          ...remoteIds(parsed?.savedLessons),
          ...Object.values(folderMemberships).flat(),
        ]),
      ),
      savedFolderLessons: folderMemberships,
      activityDays: asArray(parsed?.activityDays),
    });
    if (
      Object.keys(parsed?.positions || {}).length > MAX_LOCAL_RESUME_ENTRIES ||
      Object.keys(parsed?.lastWatchedAt || {}).length >
        MAX_LOCAL_RESUME_ENTRIES ||
      asArray(parsed?.activityDays).length > state.activityDays.length
    ) {
      if (boundary) assertAccountSessionBoundary(boundary);
      // Compaction is maintenance, not the source of truth for this read. A
      // transient storage write failure must not turn the valid snapshot we
      // just parsed into an empty learning history for the current session.
      try {
        await AsyncStorage.setItem(storageKey, JSON.stringify(state));
      } catch {
        // Keep the valid in-memory state. A later write can compact it again.
      }
      if (boundary) assertAccountSessionBoundary(boundary);
    }
    return state;
  } catch (error: unknown) {
    if (isAccountBoundaryError(error)) throw error;
    return {...EMPTY_STATE};
  }
};

const mergeStringArrays = (left: string[], right: string[]) =>
  Array.from(new Set([...left, ...right]));

/**
 * Keeps free-preview progress when a guest creates an account, while never
 * importing demo project passes into a real learner record.
 */
export const migrateGuestLearningState = async (
  guestScope: string,
  accountBoundary?: AccountSessionBoundary,
): Promise<boolean> => {
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  const accountScope =
    accountBoundary?.scope ?? (await getCurrentAccountStorageScope());
  if (!guestScope || guestScope === accountScope) {
    return false;
  }
  const sourceKey = `${PLAYER_STATE_KEY}:${guestScope}`;
  const targetKey = `${PLAYER_STATE_KEY}:${accountScope}`;
  const [[, sourceValue], [, targetValue]] = await AsyncStorage.multiGet([
    sourceKey,
    targetKey,
  ]);
  if (!sourceValue) {
    return true;
  }
  let source: Partial<PersistedPlayerState>;
  let target: Partial<PersistedPlayerState>;
  try {
    source = JSON.parse(sourceValue) as Partial<PersistedPlayerState>;
    target = targetValue
      ? (JSON.parse(targetValue) as Partial<PersistedPlayerState>)
      : {};
  } catch {
    // Keep a damaged guest cache for a later app migration.
    return false;
  }
  // Bookmarks are account-only server records. Public-preview state may carry
  // playback progress into the new account, but it must never manufacture a
  // saved membership or icon that the server did not create.
  const savedFolderLessons = savedFolderMemberships(target.savedFolderLessons);
  const next = compactPlayerState({
    positions: {...(source.positions || {}), ...(target.positions || {})},
    lastWatchedAt: {
      ...(source.lastWatchedAt || {}),
      ...(target.lastWatchedAt || {}),
    },
    completedSections: mergeStringArrays(
      asArray(source.completedSections),
      asArray(target.completedSections),
    ),
    savedLessons: Array.from(
      new Set([
        ...remoteIds(target.savedLessons),
        ...Object.values(savedFolderLessons).flat(),
      ]),
    ),
    savedFolderLessons,
    activityDays: mergeStringArrays(
      asArray(source.activityDays),
      asArray(target.activityDays),
    ).slice(-60),
  });
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  await AsyncStorage.setItem(targetKey, JSON.stringify(next));
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  return true;
};

export const updatePlayerState = async (
  update: (state: PersistedPlayerState) => PersistedPlayerState,
  scopedStorageKey?: string,
  accountBoundary?: AccountSessionBoundary,
) => {
  // Resolve the account once per operation. A global queue that recalculates
  // the key at write time can leak progress from account A into account B if
  // logout/login happens while an update is waiting.
  const boundary =
    accountBoundary ||
    (scopedStorageKey ? undefined : await captureAccountSessionBoundary());
  const storageKey =
    scopedStorageKey ||
    (await accountScopedStorageKey(PLAYER_STATE_KEY, boundary));
  const previous = playerStateQueues.get(storageKey) ?? Promise.resolve();
  const operation = previous.then(async () => {
    if (boundary) assertAccountSessionBoundary(boundary);
    const current = await readPlayerState(storageKey, boundary);
    const next = compactPlayerState(update(current));
    if (boundary) assertAccountSessionBoundary(boundary);
    await AsyncStorage.setItem(storageKey, JSON.stringify(next));
    if (boundary) assertAccountSessionBoundary(boundary);
    return next;
  });
  const settled = operation.catch(() => undefined);
  playerStateQueues.set(storageKey, settled);
  void settled.finally(() => {
    if (playerStateQueues.get(storageKey) === settled) {
      playerStateQueues.delete(storageKey);
    }
  });
  return operation;
};

export const updatePlayerStateForScope = async (
  accountScope: string,
  update: (state: PersistedPlayerState) => PersistedPlayerState,
  boundary?: AccountSessionBoundary,
) => {
  if (!/^[a-z0-9_-]+$/i.test(accountScope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }
  if (boundary) assertAccountSessionBoundary(boundary);
  const storageKey = `${PLAYER_STATE_KEY}:${accountScope}`;
  const result = await updatePlayerState(update, storageKey, boundary);
  if (!boundary) return result;
  try {
    assertAccountSessionBoundary(boundary);
    return result;
  } catch (error) {
    // A different owner may have cleared this scope while the native storage
    // write was already in progress. Remove only the obsolete owner's key;
    // a same-account token refresh keeps its valid learning record.
    if ((await getCurrentAccountStorageScope()) !== accountScope) {
      await AsyncStorage.removeItem(storageKey);
    }
    throw error;
  }
};

export const readPlayerStateForScope = async (
  accountScope: string,
  boundary?: AccountSessionBoundary,
) => {
  if (!/^[a-z0-9_-]+$/i.test(accountScope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }
  if (boundary) assertAccountSessionBoundary(boundary);
  const state = await readPlayerState(
    `${PLAYER_STATE_KEY}:${accountScope}`,
    boundary,
  );
  if (boundary) assertAccountSessionBoundary(boundary);
  return state;
};

export const getLocalLearningState = readPlayerState;

export const isWatchHistoryEnabled = async (): Promise<boolean> => {
  const boundary = await captureAccountSessionBoundary();
  let stored: string | null;
  try {
    stored = await AsyncStorage.getItem(
      await accountScopedStorageKey(WATCH_HISTORY_ENABLED_KEY, boundary),
    );
  } catch {
    return true;
  }
  assertAccountSessionBoundary(boundary);
  try {
    return stored === null ? true : JSON.parse(stored) !== false;
  } catch {
    return true;
  }
};

/**
 * Removes only the optional viewing trail and resume positions. Course
 * completion, unlocked modules, projects, certificates and saved lists stay
 * untouched because they are part of the learning record, not watch history.
 */
export const clearLocalWatchHistory = async (
  accountBoundary?: AccountSessionBoundary,
) =>
  updatePlayerState(
    state => ({
      ...state,
      positions: {},
      lastWatchedAt: {},
    }),
    undefined,
    accountBoundary,
  );

export const resetPlayerStateRuntime = () => {
  playerStateQueues.clear();
};

export const applyLocalLearningState = async (
  course: CourseLearningData,
): Promise<CourseLearningData> => {
  const state = await readPlayerState();
  return {
    ...course,
    modules: course.modules.map(module => {
      // Local state remembers presentation and retryable writes. It is never
      // an entitlement for production content: only the API may expose a
      // module and its signed media source.
      const moduleUnlocked = !module.isLocked;
      const reels = module.reels.map(reel => {
        const isCompleted =
          reel.isCompleted || state.completedSections.includes(reel.sectionId);
        return {
          ...reel,
          isLocked: !moduleUnlocked || reel.isLocked,
          isCompleted,
        };
      });
      const nextModule: CourseLearningModule = {
        ...module,
        isLocked: !moduleUnlocked,
        reels,
      };
      return nextModule;
    }),
  };
};
