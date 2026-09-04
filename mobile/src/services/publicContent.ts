import {publicRequest} from '../constants/api';
import {getItem, removeItem, saveItem} from '../constants/helpers';
import {isServerTimestampFresh, serverNowMs} from '../utils/serverClock';

export type PublicContentPage = 'about' | 'privacy' | 'terms';

type PublicContentResponse = {
  managed_body?: unknown;
  source?: unknown;
};

type CachedPublicContent = {
  body: string;
  savedAt: number;
};

const PUBLIC_CONTENT_CACHE_VERSION = 2;
const PUBLIC_CONTENT_CACHE_TTL_MS = 60 * 1000;
const PUBLIC_CONTENT_MAX_STALE_MS = 24 * 60 * 60 * 1000;
const cacheKey = (page: PublicContentPage) =>
  `@rokn/public-content/v${PUBLIC_CONTENT_CACHE_VERSION}/ar/${page}`;

const plainText = (value: unknown) =>
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

export const getManagedPublicContent = async (page: PublicContentPage) => {
  const key = cacheKey(page);
  const cached = await getItem<CachedPublicContent>(key);
  if (
    cached?.body &&
    Number.isFinite(cached.savedAt) &&
    isServerTimestampFresh(cached.savedAt, PUBLIC_CONTENT_CACHE_TTL_MS)
  ) {
    return plainText(cached.body);
  }
  try {
    const response = await publicRequest.get(`content/pages/${page}`);
    const envelope = response?.data ?? {};
    const data = (envelope.data ?? envelope) as PublicContentResponse;
    const body =
      data.source === 'dashboard' ? plainText(data.managed_body) : '';
    if (body) {
      await saveItem(key, {body, savedAt: serverNowMs()});
    } else {
      await removeItem(key);
    }
    return body;
  } catch (error) {
    if (
      cached?.body &&
      Number.isFinite(cached.savedAt) &&
      isServerTimestampFresh(cached.savedAt, PUBLIC_CONTENT_MAX_STALE_MS)
    ) {
      return plainText(cached.body);
    }
    throw error;
  }
};
