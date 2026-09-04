import RNFS from 'react-native-fs';
import {Platform} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';

// Chat attachments are working files, not learner documents. Keeping them in
// the OS cache lets low-storage phones reclaim the space automatically.
const getCacheDir = (): string => `${RNFS.CachesDirectoryPath}/rokn_chat`;

const CACHE_METADATA_KEY = '@chat_file_cache_metadata';
const MAX_CACHE_AGE_MS = 24 * 60 * 60 * 1000;
const MAX_CACHE_BYTES = 16 * 1024 * 1024;
const MAX_SINGLE_FILE_BYTES = 8 * 1024 * 1024;
const ORPHAN_GRACE_MS = 10 * 60 * 1000;
let cacheGeneration = 0;
let cacheWritesSuspended = false;
const activeCacheWrites = new Set<Promise<CachedFile>>();
let metadataTail: Promise<void> = Promise.resolve();

export interface CachedFile {
  id: string;
  uri: string;
  type: 'image' | 'file';
  mimeType: string;
  name: string;
  size: number;
  cachedAt: number;
}

interface CacheMetadata {
  [fileId: string]: CachedFile;
}

const withMetadataLock = <T>(operation: () => Promise<T>): Promise<T> => {
  const result = metadataTail.then(operation, operation);
  metadataTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const filePathFromUri = (uri: string): string =>
  uri.replace(/^file:\/\//, '').replace(/\\/g, '/');

const isManagedCacheUri = (uri: string): boolean => {
  const root = getCacheDir().replace(/\\/g, '/').replace(/\/$/, '');
  const path = filePathFromUri(uri);
  const relative = path.startsWith(`${root}/`) ? path.slice(root.length + 1) : '';

  return relative !== '' && !relative.split('/').includes('..');
};

const isCachedFile = (fileId: string, value: unknown): value is CachedFile => {
  if (!value || typeof value !== 'object') return false;
  const file = value as Partial<CachedFile>;
  return (
    file.id === fileId &&
    /^file_\d+_[a-z0-9]+$/i.test(fileId) &&
    typeof file.uri === 'string' &&
    isManagedCacheUri(file.uri) &&
    (file.type === 'image' || file.type === 'file') &&
    typeof file.mimeType === 'string' &&
    file.mimeType.length <= 255 &&
    typeof file.name === 'string' &&
    file.name.length > 0 &&
    file.name.length <= 255 &&
    typeof file.size === 'number' &&
    Number.isFinite(file.size) &&
    file.size >= 0 &&
    file.size <= MAX_SINGLE_FILE_BYTES &&
    typeof file.cachedAt === 'number' &&
    Number.isFinite(file.cachedAt) &&
    file.cachedAt > 0
  );
};

const readMetadata = async (): Promise<CacheMetadata> => {
  const raw = await AsyncStorage.getItem(CACHE_METADATA_KEY);
  if (!raw) return {};

  try {
    const parsed: unknown = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      throw new Error('INVALID_CACHE_METADATA');
    }

    const metadata: CacheMetadata = {};
    Object.entries(parsed).forEach(([fileId, file]) => {
      if (isCachedFile(fileId, file)) metadata[fileId] = file;
    });
    return metadata;
  } catch {
    // A damaged index must not poison every future attachment. Preserve one
    // bounded diagnostic copy, then rebuild from an empty trusted index.
    await AsyncStorage.setItem(`${CACHE_METADATA_KEY}:corrupt`, raw.slice(0, 8192))
      .catch(() => undefined);
    await AsyncStorage.removeItem(CACHE_METADATA_KEY).catch(() => undefined);
    return {};
  }
};

const cacheErrorMessage = (error: unknown, fallback: string) =>
  error instanceof Error && error.message
    ? error.message
    : error
    ? String(error)
    : fallback;

// Initialize cache directory
export const initCacheDir = async (): Promise<void> => {
  try {
    const cacheDir = getCacheDir();

    // Check if directory exists
    const exists = await RNFS.exists(cacheDir);
    if (exists) {
      return; // Directory already exists
    }

    // Try to create directory
    try {
      await RNFS.mkdir(cacheDir);
    } catch (mkdirError: unknown) {
      // If mkdir fails, check if directory was created by another process
      const existsAfterError = await RNFS.exists(cacheDir);
      if (!existsAfterError) {
        // Directory still doesn't exist, log the error
        const errorMessage = cacheErrorMessage(
          mkdirError,
          'Failed to create cache directory',
        );
        if (__DEV__) {
          console.warn('Could not create cache directory:', errorMessage);
        }
        // Initialization retries on the first cache write.
      }
      // If directory exists now, it's fine - another process created it
    }
  } catch (error: unknown) {
    // Normalize initialization errors for logging.
    const errorMessage = cacheErrorMessage(
      error,
      'Unknown error initializing cache directory',
    );
    if (__DEV__) {
      console.warn('Error initializing cache directory:', errorMessage);
    }
    // The first cache write retries initialization.
  }
};

// Generate unique file ID
const generateFileId = (): string => {
  return `file_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
};

// Get file extension from URI or name
const getFileExtension = (uri: string, name?: string): string => {
  const fileName = name || uri.split('/').pop() || '';
  const parts = fileName.split('.');
  return parts.length > 1 ? parts.pop()!.toLowerCase() : '';
};

// Determine file type from extension or mime type
const getFileType = (mimeType: string, extension: string): 'image' | 'file' => {
  if (
    mimeType.startsWith('image/') ||
    ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)
  ) {
    return 'image';
  }
  return 'file';
};

// Cache a file (copy from source to cache directory)
const cacheFileInternal = async (
  sourceUri: string,
  mimeType: string,
  name?: string,
  generation = cacheGeneration,
): Promise<CachedFile> => {
  let copiedPath = '';
  try {
    await cleanupOldFiles();
    // Ensure cache directory exists
    await initCacheDir();
    const cacheDir = getCacheDir();

    const fileId = generateFileId();
    const extension = getFileExtension(sourceUri, name);
    const fileName = `${fileId}.${extension || 'bin'}`;
    const cachedPath = `${cacheDir}/${fileName}`;
    copiedPath = cachedPath;

    // Copy file to cache directory
    await RNFS.copyFile(sourceUri, cachedPath);
    if (cacheWritesSuspended || generation !== cacheGeneration) {
      await RNFS.unlink(cachedPath).catch(() => undefined);
      throw new Error('Attachment cache was closed with the account session.');
    }

    // Get file size
    const stat = await RNFS.stat(cachedPath);
    const size =
      typeof stat.size === 'string' ? parseInt(stat.size, 10) : stat.size;

    if (!Number.isFinite(size) || size > MAX_SINGLE_FILE_BYTES) {
      await RNFS.unlink(cachedPath).catch(() => undefined);
      throw new Error(
        'The selected file is too large for a temporary chat attachment.',
      );
    }

    const cachedFile: CachedFile = {
      id: fileId,
      uri: Platform.OS === 'ios' ? cachedPath : `file://${cachedPath}`,
      type: getFileType(mimeType, extension),
      mimeType,
      name: name || `file.${extension}`,
      size,
      cachedAt: Date.now(),
    };

    // Save metadata
    await withMetadataLock(async () => {
      const metadata = await readMetadata();
      metadata[fileId] = cachedFile;
      if (cacheWritesSuspended || generation !== cacheGeneration) {
        throw new Error('Attachment cache was closed with the account session.');
      }
      await AsyncStorage.setItem(CACHE_METADATA_KEY, JSON.stringify(metadata));
    });
    await cleanupOldFiles();

    return cachedFile;
  } catch (error: unknown) {
    if (copiedPath) {
      await RNFS.unlink(copiedPath).catch(() => undefined);
    }
    // Expose a concrete cache error.
    const errorMessage = cacheErrorMessage(error, 'Unknown error caching file');
    if (__DEV__) console.error('Error caching file:', errorMessage);
    throw new Error(errorMessage);
  }
};

export const cacheFile = (
  sourceUri: string,
  mimeType: string,
  name?: string,
): Promise<CachedFile> => {
  if (cacheWritesSuspended) {
    return Promise.reject(
      new Error('Attachment cache was closed with the account session.'),
    );
  }
  const generation = cacheGeneration;
  const flight = cacheFileInternal(sourceUri, mimeType, name, generation);
  activeCacheWrites.add(flight);
  const clear = () => activeCacheWrites.delete(flight);
  void flight.then(clear, clear);
  return flight;
};

// Get cached file by ID
export const getCachedFile = async (
  fileId: string,
): Promise<CachedFile | null> => {
  try {
    const metadata = await withMetadataLock(readMetadata);
    const file = metadata[fileId] || null;

    if (!file) return null;

    // Check if file still exists
    const exists = await RNFS.exists(
      Platform.OS === 'ios' ? file.uri : file.uri.replace('file://', ''),
    );
    if (!exists) {
      // Remove from metadata if file doesn't exist
      await withMetadataLock(async () => {
        const current = await readMetadata();
        if (current[fileId]?.uri !== file.uri) return;
        delete current[fileId];
        if (Object.keys(current).length) {
          await AsyncStorage.setItem(
            CACHE_METADATA_KEY,
            JSON.stringify(current),
          );
        } else {
          await AsyncStorage.removeItem(CACHE_METADATA_KEY);
        }
      });
      return null;
    }

    return file;
  } catch (error) {
    if (__DEV__) console.error('Error getting cached file:', error);
    return null;
  }
};

// Convert file to base64 for API
export const fileToBase64 = async (fileUri: string): Promise<string> => {
  try {
    const uri =
      Platform.OS === 'android' ? fileUri.replace('file://', '') : fileUri;
    const base64 = await RNFS.readFile(uri, 'base64');
    return base64;
  } catch (error: unknown) {
    // Expose a concrete read error.
    const errorMessage = cacheErrorMessage(
      error,
      'Unknown error converting file to base64',
    );
    if (__DEV__)
      console.error('Error converting file to base64:', errorMessage);
    throw new Error(errorMessage);
  }
};

const deleteCachedPath = async (uri: string) => {
  if (!isManagedCacheUri(uri)) return;
  const filePath = filePathFromUri(uri);
  if (await RNFS.exists(filePath)) {
    await RNFS.unlink(filePath);
  }
};

// Bound temporary attachment storage by age and total size.
export const cleanupOldFiles = async (): Promise<void> => {
  try {
    await withMetadataLock(async () => {
      const metadata = await readMetadata();
      const expiresBefore = Date.now() - MAX_CACHE_AGE_MS;
      const newestFirst = Object.entries(metadata).sort(
        ([, left], [, right]) => right.cachedAt - left.cachedAt,
      );
      const retained: CacheMetadata = {};
      let retainedBytes = 0;

      for (const [fileId, file] of newestFirst) {
        const size = Number(file.size) || 0;
        const expired = !file.cachedAt || file.cachedAt < expiresBefore;
        const exceedsBudget = retainedBytes + size > MAX_CACHE_BYTES;
        const exists = await RNFS.exists(filePathFromUri(file.uri)).catch(
          () => false,
        );

        if (expired || exceedsBudget || !exists) {
          await deleteCachedPath(file.uri).catch(() => undefined);
          continue;
        }
        retained[fileId] = file;
        retainedBytes += size;
      }

      if (Object.keys(retained).length) {
        await AsyncStorage.setItem(
          CACHE_METADATA_KEY,
          JSON.stringify(retained),
        );
      } else {
        await AsyncStorage.removeItem(CACHE_METADATA_KEY);
      }

      // A process kill between copyFile and metadata commit can leave bytes
      // that no index can ever evict. Do not touch a fresh unknown file because
      // another concurrent copy may still be committing it; older files are
      // safe orphans inside Rokn's own cache directory.
      const retainedPaths = new Set(
        Object.values(retained).map(file => filePathFromUri(file.uri)),
      );
      const directoryEntries = await RNFS.readDir(getCacheDir()).catch(() => []);
      await Promise.all(
        directoryEntries
          .filter(entry => entry.isFile() && !retainedPaths.has(filePathFromUri(entry.path)))
          .filter(entry => {
            const modifiedAt =
              entry.mtime instanceof Date ? entry.mtime.getTime() : 0;
            return modifiedAt > 0 && modifiedAt < Date.now() - ORPHAN_GRACE_MS;
          })
          .map(entry => RNFS.unlink(entry.path).catch(() => undefined)),
      );
    });
  } catch (error) {
    if (__DEV__) console.error('Error cleaning up old files:', error);
  }
};

/** Rokn AI is session-only. Remove every transient attachment at logout. */
export const clearTransientChatCache = async (
  options: {accountBoundary?: boolean} = {},
): Promise<void> => {
  if (options.accountBoundary) {
    cacheWritesSuspended = true;
    cacheGeneration += 1;
  }
  const quiescers = [
    import('../components/VideoPlayer/courseChat/persistence')
      .then(module => module.quiesceCourseChatPersistence())
      .catch(() => undefined),
  ];
  if (options.accountBoundary) {
    quiescers.push(import('../components/VideoPlayer/attachmentActions')
      .then(module => module.quiescePrivateAttachmentDownloads())
      .catch(() => undefined));
  }
  await Promise.all(quiescers);
  if (options.accountBoundary && activeCacheWrites.size) {
    await Promise.allSettled([...activeCacheWrites]);
  }
  try {
    const directory = getCacheDir();
    if (await RNFS.exists(directory)) await RNFS.unlink(directory);
  } catch {
    // Cleanup failure does not block logout or account replacement.
  }
  await withMetadataLock(() =>
    AsyncStorage.removeItem(CACHE_METADATA_KEY),
  ).catch(() => undefined);
  if (options.accountBoundary) {
    cacheGeneration += 1;
    cacheWritesSuspended = false;
  }
};

// Get cache size
export const getCacheSize = async (): Promise<number> => {
  try {
    const metadata = await withMetadataLock(readMetadata);
    return Object.values(metadata).reduce(
      (total, file) => total + file.size,
      0,
    );
  } catch (error) {
    if (__DEV__) console.error('Error getting cache size:', error);
    return 0;
  }
};
