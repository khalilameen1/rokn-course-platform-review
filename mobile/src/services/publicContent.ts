import {publicRequest, type RoknRequestConfig} from '../constants/api';
import {getItem, saveItem} from '../constants/helpers';
import {isServerTimestampFresh, serverNowMs} from '../utils/serverClock';
import bundledPages from '../content/publicPages.ar.json';

export type PublicContentPage = keyof typeof bundledPages;

export type PublicContentDocument = {
  title: string;
  intro_title: string;
  intro_text: string;
  sections: {title: string; body: string[]}[];
  closing: string;
  last_updated: string;
  contact_label: string;
};

type CachedPublicContent = {
  document: PublicContentDocument;
  savedAt: number;
};

const CACHE_VERSION = 3;
const CACHE_TTL_MS = 60 * 1000;
const MAX_STALE_MS = 24 * 60 * 60 * 1000;
const cacheKey = (page: PublicContentPage) =>
  `@rokn/public-content/v${CACHE_VERSION}/ar/${page}`;

const plainText = (value: unknown): string =>
  (typeof value === 'string' ? value : '')
    .replace(/<\s*br\s*\/?\s*>/gi, '\n')
    .replace(/<\/(p|div|li|h[1-6])>/gi, '\n')
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;/gi, "'")
    .replace(/\r/g, '')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();

const record = (value: unknown): Record<string, unknown> =>
  value && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};

const paragraphs = (value: unknown): string[] =>
  (Array.isArray(value) ? value : [value]).map(plainText).filter(Boolean);

export const bundledPublicContent = (
  page: PublicContentPage,
): PublicContentDocument => parsePublicContent(bundledPages[page])!;

export const parsePublicContent = (
  value: unknown,
): PublicContentDocument | null => {
  const content = record(value);
  const title = plainText(content.title);
  if (!title || !Array.isArray(content.sections)) return null;
  const sections = content.sections.map(value => {
    const section = record(value);
    return {
      title: plainText(section.title),
      body: [
        ...paragraphs(section.body),
        ...paragraphs(section.points),
        ...paragraphs(section.footer),
      ],
    };
  });
  if (!sections.length || sections.some(section => !section.body.length)) {
    return null;
  }
  return {
    title,
    intro_title: plainText(content.intro_title),
    intro_text: plainText(content.intro_text),
    sections,
    closing: plainText(content.closing || content.final_acknowledgement),
    last_updated: plainText(content.last_updated),
    contact_label: plainText(content.contact_label) || 'تواصل معنا',
  };
};

const responseDocument = (
  value: unknown,
  page: PublicContentPage,
): PublicContentDocument | null => {
  const data = record(value);
  const managedBody = plainText(data.managed_body);
  if (data.source === 'dashboard' && managedBody) {
    return {
      title: plainText(data.title) || bundledPages[page].title,
      intro_title: '',
      intro_text: '',
      sections: [{title: '', body: managedBody.split(/\n+/).filter(Boolean)}],
      closing: '',
      last_updated: '',
      contact_label: 'تواصل معنا',
    };
  }
  return parsePublicContent(data.content);
};

export const getPublicContent = async (
  page: PublicContentPage,
): Promise<PublicContentDocument> => {
  const key = cacheKey(page);
  const cached = await getItem<CachedPublicContent>(key).catch(() => null);
  const cachedDocument = parsePublicContent(cached?.document);
  if (
    cachedDocument &&
    Number.isFinite(cached?.savedAt) &&
    isServerTimestampFresh(cached!.savedAt, CACHE_TTL_MS)
  ) {
    return cachedDocument;
  }
  try {
    const response = await publicRequest.get(`content/pages/${page}`, {
      skipAuthorization: true,
      headers: {'Accept-Language': 'ar'},
    } as RoknRequestConfig);
    const envelope = response?.data ?? {};
    const document = responseDocument(envelope.data ?? envelope, page);
    if (!document) throw new Error('Invalid public content document');
    await saveItem(key, {document, savedAt: serverNowMs()}).catch(
      () => undefined,
    );
    return document;
  } catch {
    if (
      cachedDocument &&
      Number.isFinite(cached?.savedAt) &&
      isServerTimestampFresh(cached!.savedAt, MAX_STALE_MS)
    ) {
      return cachedDocument;
    }
    return bundledPublicContent(page);
  }
};
