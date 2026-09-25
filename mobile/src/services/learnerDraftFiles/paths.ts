import RNFS from 'react-native-fs';

export type LearnerDraftFile = {
  uri: string;
  type?: string;
  fileName?: string;
  size?: number;
};

export const CACHE_ROOT = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts`;

export const filePath = (uri?: string) =>
  String(uri || '')
    .replace(/^file:\/\//, '')
    .replace(/\\/g, '/');

export const safeExtension = (file: LearnerDraftFile) => {
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

export const isManagedPath = (path: string) => {
  const normalizedRoot = CACHE_ROOT.replace(/\\/g, '/').replace(/\/$/, '');
  return (
    path.startsWith(`${normalizedRoot}/`) &&
    !path
      .slice(normalizedRoot.length + 1)
      .split('/')
      .includes('..')
  );
};

export const accountScopeFromPath = (path: string): string | undefined => {
  if (!isManagedPath(path)) return undefined;
  const normalizedRoot = CACHE_ROOT.replace(/\\/g, '/').replace(/\/$/, '');
  const scope = path.slice(normalizedRoot.length + 1).split('/')[0];
  return /^[a-z0-9_-]+$/i.test(scope) ? scope : undefined;
};
