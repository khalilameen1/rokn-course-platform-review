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
