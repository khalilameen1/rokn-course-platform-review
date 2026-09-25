import RNFS from 'react-native-fs';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  isLearnerDraftStorageKey,
  learnerDraftStorage,
} from '../learnerDraftStorage';
import {
  CACHE_ROOT,
  filePath,
  isManagedPath,
  accountScopeFromPath,
} from './paths';

// Mutations are called only under the file owner's account queue. Do not add
// a second lock here: copy, reference updates and cleanup form one operation.
export const REFERENCE_WRITE_GRACE_MS = 5 * 60 * 1000;

type DraftReferenceRegistry = Record<
  string,
  {paths: string[]; updatedAt: number}
>;
const registryPath = (accountScope: string) =>
  `${CACHE_ROOT}/${accountScope}/.references.json`;

export const readReferenceRegistry = async (
  accountScope: string,
): Promise<DraftReferenceRegistry> => {
  const target = registryPath(accountScope);
  for (const candidate of [target, `${target}.backup`]) {
    // Only absence permits trying the rename backup. A stale backup cannot
    // replace an unreadable current registry and erase its newer owners.
    if (!(await RNFS.exists(candidate))) continue;
    const raw = await RNFS.readFile(candidate, 'utf8');
    const parsed = JSON.parse(raw) as DraftReferenceRegistry;
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed))
      throw new Error('LEARNER_FILE_REFERENCES_UNAVAILABLE');
    return Object.fromEntries(
      Object.entries(parsed).flatMap(([owner, value]) => {
        if (
          !value ||
          !Array.isArray(value.paths) ||
          value.paths.some(path => typeof path !== 'string') ||
          !Number.isFinite(value.updatedAt)
        )
          throw new Error('LEARNER_FILE_REFERENCES_UNAVAILABLE');
        const paths = value.paths
          .map(filePath)
          .filter(
            path =>
              isManagedPath(path) &&
              accountScopeFromPath(path) === accountScope,
          );
        return paths.length
          ? [[owner.slice(0, 180), {paths, updatedAt: Number(value.updatedAt)}]]
          : [];
      }),
    );
  }
  return {};
};

export const writeReferenceRegistry = async (
  accountScope: string,
  registry: DraftReferenceRegistry,
) => {
  const directory = `${CACHE_ROOT}/${accountScope}`;
  await RNFS.mkdir(directory);
  const target = registryPath(accountScope);
  const temporary = `${target}.tmp`;
  const backup = `${target}.backup`;
  await RNFS.writeFile(temporary, JSON.stringify(registry), 'utf8');
  if (await RNFS.exists(target)) {
    await RNFS.unlink(backup).catch(() => undefined);
    await RNFS.moveFile(target, backup);
  }
  try {
    await RNFS.moveFile(temporary, target);
    await RNFS.unlink(backup).catch(() => undefined);
  } catch (error) {
    if (!(await RNFS.exists(target)) && (await RNFS.exists(backup))) {
      await RNFS.moveFile(backup, target).catch(() => undefined);
    }
    throw error;
  }
};

const managedPathsInValue = (value: unknown, found: Set<string>): void => {
  if (typeof value === 'string') {
    const path = filePath(value);
    if (isManagedPath(path)) found.add(path);
    return;
  }
  if (Array.isArray(value)) {
    value.forEach(item => managedPathsInValue(item, found));
    return;
  }
  if (value && typeof value === 'object') {
    Object.values(value as Record<string, unknown>).forEach(item =>
      managedPathsInValue(item, found),
    );
  }
};

export const durableDraftPaths = async (
  accountScope: string,
): Promise<Set<string>> => {
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    isLearnerDraftStorageKey(key, accountScope),
  );
  const values = new Map(keys.length ? await AsyncStorage.multiGet(keys) : []);
  const paths = new Set<string>();
  for (const key of keys) {
    // A removed key is returned as null. A missing row is an incomplete read,
    // not evidence that every file belonging to that outbox was abandoned.
    if (!values.has(key))
      throw new Error('LEARNER_FILE_REFERENCES_UNAVAILABLE');
    const raw = values.get(key);
    if (raw === null) continue;
    const parsed: unknown = JSON.parse(raw!);
    managedPathsInValue(parsed, paths);
    if (
      key.startsWith(`${learnerDraftStorage.feedbackConflicts.namespace}:`) &&
      Array.isArray(parsed)
    ) {
      // These are selectable drafts, unlike the retired :corrupt snapshots.
      for (const entry of parsed) {
        if (typeof entry?.raw === 'string')
          managedPathsInValue(JSON.parse(entry.raw), paths);
      }
    }
  }
  return new Set(
    [...paths].filter(path => accountScopeFromPath(path) === accountScope),
  );
};

/** Recent registry entries guard commits; durable drafts keep their own files
 * even if they never used the registry. Only confirmed orphans lose ownership. */
export const reconcileReferenceRegistry = (
  registry: DraftReferenceRegistry,
  referencedByDurableState: Set<string>,
): DraftReferenceRegistry => {
  const now = Date.now();
  const reconciled: DraftReferenceRegistry = {};
  Object.entries(registry).forEach(([owner, value]) => {
    const withinCommitGrace = now - value.updatedAt <= REFERENCE_WRITE_GRACE_MS;
    const paths = value.paths.filter(
      path => withinCommitGrace || referencedByDurableState.has(path),
    );
    if (paths.length) reconciled[owner] = {...value, paths};
  });
  return reconciled;
};
