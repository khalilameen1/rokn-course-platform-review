import RNFS from 'react-native-fs';

// Must match backend/config/projects.php (25,600 KiB).
// The smaller 8 MiB image-inspection ceiling is a server implementation
// detail; it is not the learner submission limit.
export const PROJECT_SUBMISSION_MAX_BYTES = 25 * 1024 * 1024;
export const PROJECT_SUBMISSION_MAX_LABEL = '٢٥ ميجابايت';
export const PROJECT_SUBMISSION_FORMATS_LABEL = 'صورة أو PDF أو TXT أو DOCX أو PPTX';
export const PENDING_PROJECT_FILES_MAX_BYTES = 75 * 1024 * 1024;

export const assertPendingProjectCacheCapacity = (
  retainedBytes: number,
  incomingBytes: number,
) => {
  const retained = Math.max(0, Number(retainedBytes) || 0);
  const incoming = Math.max(0, Number(incomingBytes) || 0);
  if (retained + incoming > PENDING_PROJECT_FILES_MAX_BYTES) {
    throw new Error('PROJECT_PENDING_CACHE_FULL');
  }
};

const allowedMimeTypes = new Set([
  'image/jpeg',
  'image/jpg',
  'image/png',
  'image/webp',
  'application/pdf',
  'text/plain',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
]);

const canonicalMimeByExtension: Record<string, string> = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  pdf: 'application/pdf',
  txt: 'text/plain',
  docx:
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  pptx:
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
};

const canonicalMime = (value?: string) => {
  const mime = String(value || '').trim().toLowerCase();
  return mime === 'image/jpg' ? 'image/jpeg' : mime;
};

const canonicalMimeFromName = (value?: string) => {
  const extension = String(value || '')
    .trim()
    .toLowerCase()
    .match(/\.([a-z0-9]+)$/)?.[1];
  return canonicalMimeByExtension[extension || ''] || '';
};

/**
 * Android document providers may omit a MIME type for valid DOCX/PDF/image
 * files. The server still validates the actual bytes; this only keeps the
 * client-side project contract from rejecting a supported file before upload.
 */
export const projectFileMatchesAllowedTypes = (
  file: {name?: string; type?: string},
  allowed: string[],
): boolean => {
  if (allowed.length === 0) return true;
  const accepted = new Set(allowed.map(canonicalMime));
  return (
    accepted.has(canonicalMime(file.type)) ||
    accepted.has(canonicalMimeFromName(file.name))
  );
};

export const validateProjectFileType = (file: {
  name?: string;
  type?: string;
}): void => {
  const mime = canonicalMime(file.type);
  const extensionMime = canonicalMimeFromName(file.name);
  if (!allowedMimeTypes.has(mime) && !allowedMimeTypes.has(extensionMime)) {
    throw new Error('PROJECT_FILE_TYPE_UNSUPPORTED');
  }
};

export const validatedProjectFileSize = async (file: {
  uri: string;
  size?: number;
}): Promise<number> => {
  let size = Number(file.size);
  if (!Number.isFinite(size) || size <= 0) {
    try {
      const stat = await RNFS.stat(file.uri.replace(/^file:\/\//, ''));
      size = Number(stat.size);
    } catch {
      throw new Error('PROJECT_FILE_SIZE_UNAVAILABLE');
    }
  }
  if (!Number.isFinite(size) || size <= 0) {
    throw new Error('PROJECT_FILE_SIZE_UNAVAILABLE');
  }
  if (size > PROJECT_SUBMISSION_MAX_BYTES) {
    throw new Error('PROJECT_FILE_TOO_LARGE');
  }
  return size;
};

export const validateProjectFile = async (file: {
  uri: string;
  name?: string;
  type?: string;
  size?: number;
}): Promise<number> => {
  validateProjectFileType(file);
  return validatedProjectFileSize(file);
};
