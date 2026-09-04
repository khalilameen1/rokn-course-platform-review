import {Platform} from 'react-native';
import RNFS from 'react-native-fs';
import AsyncStorage from '@react-native-async-storage/async-storage';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getCurrentAccountStorageScope,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {secureRandomUuid} from '../utils/secureRandom';

export type LearnerDraftFile = {
  uri: string;
  type?: string;
  fileName?: string;
  size?: number;
};

const CACHE_ROOT = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts`;
const MAX_ACCOUNT_CACHE_BYTES = 192 * 1024 * 1024;
const MAX_CACHE_AGE_MS = 31 * 24 * 60 * 60 * 1000;
const REFERENCE_WRITE_GRACE_MS = 5 * 60 * 1000;
const accountFileOperations = new Map<string, Promise<void>>();
const provisionalDraftFiles = new Map<string, Map<string, number>>();
type DraftReferenceRegistry = Record<
  string,
  {paths: string[]; updatedAt: number}
>;
const filePath = (uri?: string) =>
  String(uri || '')
    .replace(/^file:\/\//, '')
    .replace(/\\/g, '/');

const safeExtension = (file: LearnerDraftFile) => {
  const named = String(file.fileName || '').match(/\.([a-z0-9]{1,8})$/i)?.[1];
  if (named) return named.toLowerCase();
  return (
    {
      'image/jpeg': 'jpg',
      'image/png': 'png',
      'image/webp': 'webp',
      'video/mp4': 'mp4',
      'video/quicktime': 'mov',
      'video/webm': 'webm',
      'application/pdf': 'pdf',
      'text/plain': 'txt',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
        'docx',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation':
        'pptx',
    }[String(file.type || '').toLowerCase()] || 'bin'
  );
};

const isManagedPath = (path: string) => {
  const normalizedRoot = CACHE_ROOT.replace(/\\/g, '/').replace(/\/$/, '');
  return (
    path.startsWith(`${normalizedRoot}/`) &&
    !path
      .slice(normalizedRoot.length + 1)
      .split('/')
      .includes('..')
  );
};

export const learnerDraftFileIsManaged = (
  file?: Pick<LearnerDraftFile, 'uri'> | null,
) => isManagedPath(filePath(file?.uri));

const accountScopeFromPath = (path: string): string | undefined => {
  if (!isManagedPath(path)) return undefined;
  const normalizedRoot = CACHE_ROOT.replace(/\\/g, '/').replace(/\/$/, '');
  const scope = path.slice(normalizedRoot.length + 1).split('/')[0];
  return /^[a-z0-9_-]+$/i.test(scope) ? scope : undefined;
};

const registryPath = (accountScope: string) =>
  `${CACHE_ROOT}/${accountScope}/.references.json`;

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

const readReferenceRegistry = async (
  accountScope: string,
): Promise<DraftReferenceRegistry> => {
  const target = registryPath(accountScope);
  for (const candidate of [target, `${target}.backup`]) {
    try {
      const raw = await RNFS.readFile(candidate, 'utf8');
      const parsed = JSON.parse(raw) as DraftReferenceRegistry;
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed))
        continue;
      return Object.fromEntries(
        Object.entries(parsed).flatMap(([owner, value]) => {
          if (
            !value ||
            !Array.isArray(value.paths) ||
            !Number.isFinite(value.updatedAt)
          )
            return [];
          const paths = value.paths
            .map(filePath)
            .filter(
              path =>
                isManagedPath(path) &&
                accountScopeFromPath(path) === accountScope,
            );
          return paths.length
            ? [
                [
                  owner.slice(0, 180),
                  {paths, updatedAt: Number(value.updatedAt)},
                ],
              ]
            : [];
        }),
      );
    } catch {}
  }
  return {};
};

const writeReferenceRegistry = async (
  accountScope: string,
  registry: DraftReferenceRegistry,
) => {
  const directory = `${CACHE_ROOT}/${accountScope}`;
  await RNFS.mkdir(directory);
  const target = registryPath(accountScope);
  const temporary = `${target}.tmp`;
  const backup = `${target}.backup`;
  await RNFS.writeFile(temporary, JSON.stringify(registry), 'utf8');
  if (await RNFS.exists(target).catch(() => false)) {
    await RNFS.unlink(backup).catch(() => undefined);
    await RNFS.moveFile(target, backup);
  }
  try {
    await RNFS.moveFile(temporary, target);
    await RNFS.unlink(backup).catch(() => undefined);
  } catch (error) {
    if (
      !(await RNFS.exists(target).catch(() => false)) &&
      (await RNFS.exists(backup).catch(() => false))
    ) {
      await RNFS.moveFile(backup, target).catch(() => undefined);
    }
    throw error;
  }
};

const withAccountFileLock = async <T>(
  accountScope: string,
  operation: () => Promise<T>,
): Promise<T> => {
  const previous = accountFileOperations.get(accountScope) ?? Promise.resolve();
  let release: () => void = () => undefined;
  const current = new Promise<void>(resolve => {
    release = resolve;
  });
  accountFileOperations.set(accountScope, current);
  await previous.catch(() => undefined);
  try {
    return await operation();
  } finally {
    release();
    if (accountFileOperations.get(accountScope) === current) {
      accountFileOperations.delete(accountScope);
    }
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

/**
 * Registry entries are write-ahead guards, while account-scoped AsyncStorage
 * is the durable outbox source of truth. Recent entries get a short commit
 * grace; older entries survive only while a real draft/outbox still names the
 * file. This prevents both silent active-file eviction and immortal orphan
 * references after a project or chat is abandoned.
 */
const reconcileReferenceRegistry = async (
  accountScope: string,
  registry: DraftReferenceRegistry,
): Promise<DraftReferenceRegistry> => {
  const now = Date.now();
  const keys = (await AsyncStorage.getAllKeys().catch(() => [])).filter(key =>
    key.includes(`:${accountScope}`),
  );
  const referencedByDurableState = new Set<string>();
  const values = keys.length
    ? await AsyncStorage.multiGet(keys).catch(() => [])
    : [];
  values.forEach(([, raw]) => {
    if (!raw) return;
    try {
      managedPathsInValue(JSON.parse(raw), referencedByDurableState);
    } catch {}
  });

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

const trimAccountDraftFiles = async (
  accountScope: string,
  protectedPath?: string,
): Promise<void> => {
  const accountDirectory = `${CACHE_ROOT}/${accountScope}`;
  if (!(await RNFS.exists(accountDirectory).catch(() => false))) return;

  const kindDirectories = await RNFS.readDir(accountDirectory).catch(() => []);
  const files = (
    await Promise.all(
      kindDirectories
        .filter(item => item.isDirectory())
        .map(item => RNFS.readDir(item.path).catch(() => [])),
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
  const activeRegistry = await reconcileReferenceRegistry(
    accountScope,
    registry,
  );
  if (JSON.stringify(activeRegistry) !== JSON.stringify(registry)) {
    await writeReferenceRegistry(accountScope, activeRegistry);
  }
  const referencedPaths = new Set(
    Object.values(activeRegistry).flatMap(value => value.paths),
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
      await RNFS.unlink(file.path).catch(() => undefined);
      continue;
    }
    retainedBytes += file.size;
  }
  if (retainedBytes > MAX_ACCOUNT_CACHE_BYTES) {
    // Every remaining byte belongs to a durable active owner or the file
    // currently being copied. Refuse the new pick; never corrupt an outbox to
    // make room invisibly.
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
  } catch {
    return false;
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
