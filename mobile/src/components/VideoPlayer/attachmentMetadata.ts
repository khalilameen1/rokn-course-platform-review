import type {CourseAttachment} from './types';
import {safeFilenameStem} from '../../utils/unicodeText';

export type AttachmentMetadata = {
  url: string;
  statusCode: number;
  contentType?: string;
  contentDisposition?: string;
  contentLength?: number;
};

export const attachmentResponseIsHtml = (mime?: string): boolean =>
  ['text/html', 'application/xhtml+xml'].includes(
    String(mime || '')
      .split(';')[0]
      .trim()
      .toLowerCase(),
  );

export const attachmentPrefixIsHtml = (prefix: string): boolean => {
  // RNFS ascii reads preserve raw bytes without trying to decode binary files.
  // Keep BOM-prefixed host pages detectable in that bounded byte string too.
  const sample = /^(?:\xFF\xFE|\xFE\xFF)/.test(prefix)
    ? prefix.slice(2).split('\x00').join('')
    : prefix;
  return /^\s*(?:\uFEFF|\xEF\xBB\xBF)?\s*(?:<!doctype\s+html|<html\b|<head\b|<body\b)/i.test(
    sample,
  );
};

export const attachmentHeaderFilename = (
  disposition?: string,
): string | undefined => {
  const value = String(disposition || '');
  const encoded = /filename\*\s*=\s*UTF-8'[^']*'([^;\r\n]+)/i.exec(value)?.[1];
  if (encoded) {
    try {
      return decodeURIComponent(encoded.trim().replace(/^"|"$/g, ''));
    } catch {
      // A malformed extended value may still include a usable plain filename.
    }
  }
  return /filename\s*=\s*(?:"([^"\r\n]+)"|([^;\r\n]+))/i
    .exec(value)
    ?.slice(1)
    .find(Boolean)
    ?.trim();
};

export const safeAttachmentName = (value: string): string => {
  const basename = value.split(/[\\/]/).pop() || '';
  const extension = /\.([a-z0-9]{1,10})$/i.exec(basename)?.[1];
  const stem = safeFilenameStem(
    extension ? basename.slice(0, -(extension.length + 1)) : basename,
  );
  return `${stem || 'rokn-attachment'}${
    extension ? `.${extension.toLowerCase()}` : ''
  }`;
};

export const MIME_EXTENSIONS: Record<string, string> = {
  'application/pdf': 'pdf',
  'application/zip': 'zip',
  'application/x-zip-compressed': 'zip',
  'application/msword': 'doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
    'docx',
  'application/vnd.ms-excel': 'xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
  'application/vnd.ms-powerpoint': 'ppt',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation':
    'pptx',
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'text/plain': 'txt',
};
const EXTENSION_MIME: Record<string, string> = Object.fromEntries(
  Object.entries(MIME_EXTENSIONS).map(([mime, extension]) => [extension, mime]),
);

const normalizeExtension = (value?: string) => {
  const normalized = String(value || '')
    .trim()
    .toLowerCase()
    .split(';')[0];
  if (MIME_EXTENSIONS[normalized]) return MIME_EXTENSIONS[normalized];
  const tail = normalized.includes('/')
    ? normalized.split('/').pop() || ''
    : normalized;
  const clean = tail.replace(/^\./, '').replace(/[^a-z0-9]/g, '');
  if (!clean || clean.length > 8) {
    return '';
  }
  return clean === 'jpeg' ? 'jpg' : clean === 'plain' ? 'txt' : clean;
};

export const mimeTypeFor = (attachment: CourseAttachment, fileName: string) => {
  const supplied = String(attachment.mimeType || attachment.fileType || '')
    .trim()
    .toLowerCase()
    .split(';')[0];
  if (supplied.includes('/')) return supplied;
  const extension = normalizeExtension(fileName.split('.').pop());
  return EXTENSION_MIME[extension] || 'application/octet-stream';
};

export const safeFileName = (attachment: CourseAttachment) => {
  if (attachment.fileName) return safeAttachmentName(attachment.fileName);
  const fromUrl = attachment.url.split('?')[0].split('/').pop();
  const extensionFromUrl = fromUrl?.includes('.')
    ? normalizeExtension(fromUrl.split('.').pop())
    : '';
  if (fromUrl && extensionFromUrl) {
    try {
      return safeAttachmentName(decodeURIComponent(fromUrl));
    } catch {
      return safeAttachmentName(fromUrl);
    }
  }
  const extension =
    extensionFromUrl ||
    normalizeExtension(attachment.fileType) ||
    MIME_EXTENSIONS[String(attachment.mimeType || '').split(';')[0]] ||
    '';
  if (/\.[a-z0-9]{1,10}$/i.test(attachment.title)) {
    return safeAttachmentName(attachment.title);
  }
  const cleanTitle = safeFilenameStem(attachment.title);
  return `${cleanTitle || `rokn-${attachment.id}`}${
    extension ? `.${extension}` : ''
  }`;
};
