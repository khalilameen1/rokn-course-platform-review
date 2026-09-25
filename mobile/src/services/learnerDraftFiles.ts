import {Platform} from 'react-native';
import RNFS from 'react-native-fs';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getCurrentAccountStorageScope,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {secureRandomUuid} from '../utils/secureRandom';
import {createKeyedAsyncQueue} from '../utils/keyedAsyncQueue';
import {
  CACHE_ROOT,
  filePath,
  safeExtension,
  isManagedPath,
  accountScopeFromPath,
  type LearnerDraftFile,
} from './learnerDraftFiles/paths';
import {
  REFERENCE_WRITE_GRACE_MS,
  readReferenceRegistry,
  writeReferenceRegistry,
  durableDraftPaths,
  reconcileReferenceRegistry,
} from './learnerDraftFiles/referenceStore';

export type {LearnerDraftFile} from './learnerDraftFiles/paths';

const MAX_ACCOUNT_CACHE_BYTES = 192 * 1024 * 1024;
const MAX_CACHE_AGE_MS = 31 * 24 * 60 * 60 * 1000;
const withAccountFileLock = createKeyedAsyncQueue();
const provisionalDraftFiles = new Map<string, Map<string, number>>();
export const learnerDraftFileIsManaged = (
  file?: Pick<LearnerDraftFile, 'uri'> | null,
) => isManagedPath(filePath(file?.uri));

const provisionalPathsFor = (accountScope: string): Set<string> => {
  const entries = provisionalDraftFiles.get(accountScope);
  if (!entries) return new Set();
  const now = Date.now();
  for (const [path, expiresAt] of entries) {
    if (expiresAt <= now) entries.delete(path);
  }
  if (!entries.size) provisionalDraftFiles.delete(accountScope);
  return new Set(entries.keys());
};

const protectProvisionalPath = (accountScope: string, path: string) => {
  const entries = provisionalDraftFiles.get(accountScope) ?? new Map();
  entries.set(path, Date.now() + REFERENCE_WRITE_GRACE_MS);
  provisionalDraftFiles.set(accountScope, entries);
};

const releaseProvisionalPath = (accountScope: string, path: string) => {
  const entries = provisionalDraftFiles.get(accountScope);
  if (!entries) return;
  entries.delete(path);
  if (!entries.size) provisionalDraftFiles.delete(accountScope);
};

const trimAccountDraftFiles = async (
  accountScope: string,
  protectedPath?: string,
): Promise<void> => {
  const accountDirectory = `${CACHE_ROOT}/${accountScope}`;
  if (!(await RNFS.exists(accountDirectory))) return;

  const kindDirectories = await RNFS.readDir(accountDirectory);
  const files = (
    await Promise.all(
      kindDirectories
        .filter(item => item.isDirectory())
        .map(item => RNFS.readDir(item.path)),
    )
  )
    .flat()
    .filter(
      item =>
        item.isFile() &&
        !item.name.startsWith('.references') &&
        isManagedPath(filePath(item.path)),
    )
    .map(item => ({
      modifiedAt: item.mtime?.getTime() || 0,
      path: filePath(item.path),
      size: Math.max(0, Number(item.size) || 0),
    }))
    .sort((left, right) => right.modifiedAt - left.modifiedAt);

  const now = Date.now();
  const registry = await readReferenceRegistry(accountScope);
  const referencedPaths = await durableDraftPaths(accountScope);
  const activeRegistry = reconcileReferenceRegistry(registry, referencedPaths);
  if (JSON.stringify(activeRegistry) !== JSON.stringify(registry)) {
    await writeReferenceRegistry(accountScope, activeRegistry);
  }
  Object.values(activeRegistry).forEach(value =>
    value.paths.forEach(path => referencedPaths.add(path)),
  );
  provisionalPathsFor(accountScope).forEach(path => referencedPaths.add(path));
  let retainedBytes = 0;
  for (const file of files) {
    const isProtected =
      file.path === protectedPath || referencedPaths.has(file.path);
    const expired =
      !isProtected &&
      (!file.modifiedAt || now - file.modifiedAt > MAX_CACHE_AGE_MS);
    const exceedsBudget =
      !isProtected && retainedBytes + file.size > MAX_ACCOUNT_CACHE_BYTES;
    if (expired || exceedsBudget) {
      try {
        await RNFS.unlink(file.path);
        continue;
      } catch {
        // Failed eviction still occupies the account's storage budget.
      }
    }
    retainedBytes += file.size;
  }
  if (retainedBytes > MAX_ACCOUNT_CACHE_BYTES) {
    // Refuse the new pick when protected or unevictable bytes fill the budget;
    // never corrupt an outbox to make room invisibly.
    throw new Error('LEARNER_DRAFT_STORAGE_FULL');
  }
};

export const removeLearnerDraftFile = async (
  file?: Pick<LearnerDraftFile, 'uri'> | null,
): Promise<void> => {
  const path = filePath(file?.uri);
  if (!path || !isManagedPath(path)) return;
  const scope = accountScopeFromPath(path);
  if (!scope) return;
  await withAccountFileLock(scope, async () => {
    releaseProvisionalPath(scope, path);
    const registry = await readReferenceRegistry(scope);
    if (Object.values(registry).some(value => value.paths.includes(path)))
      return;
    if ((await durableDraftPaths(scope)).has(path)) return;
    await RNFS.unlink(path).catch(() => undefined);
  });
};

/** Replace one durable outbox owner's file references atomically. */
export const retainLearnerDraftFiles = async (
  owner: string,
  files: Array<Pick<LearnerDraftFile, 'uri'>>,
  accountScope?: string,
): Promise<void> => {
  const safeOwner = owner.trim().slice(0, 180);
  if (!safeOwner) throw new Error('INVALID_DRAFT_REFERENCE_OWNER');
  const paths = Array.from(
    new Set(files.map(file => filePath(file.uri)).filter(isManagedPath)),
  );
  const scope =
    accountScope ||
    paths.map(accountScopeFromPath).find(Boolean) ||
    (await getCurrentAccountStorageScope());
  if (!/^[a-z0-9_-]+$/i.test(scope))
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  if (paths.some(path => accountScopeFromPath(path) !== scope)) {
    throw new Error('DRAFT_REFERENCE_ACCOUNT_MISMATCH');
  }
  await withAccountFileLock(scope, async () => {
    const registry = await readReferenceRegistry(scope);
    if (paths.length) registry[safeOwner] = {paths, updatedAt: Date.now()};
    else delete registry[safeOwner];
    await writeReferenceRegistry(scope, registry);
  });
};

export const cacheLearnerDraftFile = async (
  kind: 'avatar' | 'feedback' | 'portfolio' | 'project' | 'course_chat',
  source: LearnerDraftFile,
  maximumBytes: number,
  ownerBoundary?: AccountSessionBoundary,
): Promise<LearnerDraftFile> => {
  if (!source.uri || maximumBytes <= 0) {
    throw new Error('LEARNER_FILE_UNAVAILABLE');
  }
  const declaredSize = Number(source.size);
  if (Number.isFinite(declaredSize) && declaredSize > maximumBytes) {
    throw new Error('LEARNER_FILE_TOO_LARGE');
  }

  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const scope = boundary.scope;
  const managedSourceScope = accountScopeFromPath(filePath(source.uri));
  if (managedSourceScope && managedSourceScope !== scope) {
    throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }
  if (!/^[a-z0-9_-]+$/i.test(scope))
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  const directory = `${CACHE_ROOT}/${scope}/${kind}`;
  const destination = `${directory}/${secureRandomUuid()}.${safeExtension(
    source,
  )}`;
  return withAccountFileLock(scope, async () => {
    try {
      assertAccountSessionBoundary(boundary);
      await RNFS.mkdir(directory);
      await trimAccountDraftFiles(
        scope,
        managedSourceScope === scope ? filePath(source.uri) : undefined,
      );
      assertAccountSessionBoundary(boundary);
      await RNFS.copyFile(source.uri, destination);
      const stat = await RNFS.stat(destination);
      const copiedSize = Number(stat.size);
      if (
        !Number.isFinite(copiedSize) ||
        copiedSize <= 0 ||
        copiedSize > maximumBytes ||
        (Number.isFinite(declaredSize) &&
          declaredSize > 0 &&
          copiedSize !== declaredSize)
      ) {
        throw new Error(
          copiedSize > maximumBytes
            ? 'LEARNER_FILE_TOO_LARGE'
            : 'LEARNER_FILE_INCOMPLETE',
        );
      }

      // A multi-select caller cannot commit its durable draft/outbox until the
      // whole batch has been copied. Keep every successful copy owned during
      // that short gap so a later file cannot evict an earlier one silently.
      // Durable registries take over after the caller commits; abandoned
      // picks lose this in-memory grace automatically and remain reclaimable.
      protectProvisionalPath(scope, destination);
      await trimAccountDraftFiles(scope, destination);
      assertAccountSessionBoundary(boundary);
      return {
        ...source,
        size: copiedSize,
        uri: Platform.OS === 'ios' ? destination : `file://${destination}`,
      };
    } catch (error) {
      releaseProvisionalPath(scope, destination);
      await RNFS.unlink(destination).catch(() => undefined);
      if (
        error instanceof Error &&
        (error.message.startsWith('LEARNER_FILE_') ||
          error.message === 'LEARNER_DRAFT_STORAGE_FULL' ||
          error.message === 'ACCOUNT_CHANGED_DURING_REQUEST')
      ) {
        throw error;
      }
      throw new Error('LEARNER_FILE_COPY_FAILED');
    }
  });
};

export const learnerDraftFileIsReadable = async (
  file?: LearnerDraftFile | null,
): Promise<boolean> => {
  if (!file?.uri) return false;
  try {
    const stat = await RNFS.stat(filePath(file.uri));
    return Number(stat.size) > 0;
  } catch (error) {
    const nativeError = error as {code?: unknown; message?: unknown} | null;
    // RNFS stat uses platform-specific missing-file errors. An unavailable
    // filesystem is not proof that a learner's saved attachment disappeared.
    if (
      [
        'ENOENT',
        'ENOTDIR',
        'ENSCOCOAERRORDOMAIN260',
        'ENSPOSIXERRORDOMAIN2',
      ].includes(String(nativeError?.code || '')) ||
      nativeError?.message === 'File does not exist'
    ) {
      return false;
    }
    throw error;
  }
};

export const clearAccountLearnerDraftFiles = async (
  accountScope: string,
): Promise<void> => {
  if (!/^[a-z0-9_-]+$/i.test(accountScope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }
  const directory = `${CACHE_ROOT}/${accountScope}`;
  await withAccountFileLock(accountScope, async () => {
    provisionalDraftFiles.delete(accountScope);
    if (await RNFS.exists(directory).catch(() => false)) {
      await RNFS.unlink(directory).catch(() => undefined);
    }
  });
};
