import AsyncStorage from '@react-native-async-storage/async-storage';
import {roknApiUrl} from '../../constants/apiBaseUrl';
import type {Course} from '../../types/Course';
import {isServerTimestampFresh, serverNowMs} from '../../utils/serverClock';
import {settleWithin} from '../../utils/settleWithin';
import {createKeyedAsyncQueue} from '../../utils/keyedAsyncQueue';
import type {PublishedCoursesPage} from './courseContracts';
import {isApiRecord} from './common';
import {displayImageUrl} from './courseFields';

export const CATALOGUE_CACHE_KEY = `@rokn/catalogue-page/v6:${encodeURIComponent(
  roknApiUrl,
)}`;

const CATALOGUE_CACHE_MAX_AGE_MS = 2 * 60 * 60 * 1000;
const CATALOGUE_CACHE_PAGE_LIMIT = 4;
const COURSE_COVER_FALLBACK = require('../../assets/images/courseSlider.jpg');

const withCatalogueWrite = createKeyedAsyncQueue();
let catalogueGeneration = 0;
let catalogueCacheUsable = true;

export const getCatalogueGeneration = () => catalogueGeneration;

const queueCatalogueWrite = (operation: () => Promise<void>) =>
  withCatalogueWrite(CATALOGUE_CACHE_KEY, operation);

type CatalogueCacheRecord = {
  version: 6;
  savedAt: number;
  courses: Array<
    Omit<Course, 'image' | 'owned' | 'progress'> & {image?: unknown}
  >;
  page: number;
  hasMore: boolean;
  total: number;
  revision: number;
};

const catalogueCacheKey = (page: number, baseKey = CATALOGUE_CACHE_KEY) =>
  `${baseKey}:${page}`;

const readCatalogueCacheAfterWrites = async (
  page: number,
  scopedBaseKey?: string,
  expectedRevision?: number,
  allowStale = false,
): Promise<PublishedCoursesPage | null> => {
  if (page > CATALOGUE_CACHE_PAGE_LIMIT) return null;
  const generation = catalogueGeneration;
  try {
    await withCatalogueWrite.waitForPending(CATALOGUE_CACHE_KEY);
    if (!catalogueCacheUsable || generation !== catalogueGeneration)
      return null;
    const raw = await AsyncStorage.getItem(
      catalogueCacheKey(page, scopedBaseKey),
    );
    if (!raw || !catalogueCacheUsable || generation !== catalogueGeneration)
      return null;
    const cached = JSON.parse(raw) as CatalogueCacheRecord;
    if (
      cached.version !== 6 ||
      !Array.isArray(cached.courses) ||
      cached.courses.some(
        course =>
          !course ||
          typeof course.id !== 'string' ||
          typeof course.title !== 'string',
      ) ||
      cached.page !== page ||
      !Number.isSafeInteger(cached.revision) ||
      cached.revision < 1 ||
      (expectedRevision !== undefined &&
        cached.revision !== expectedRevision) ||
      !isServerTimestampFresh(cached.savedAt, Number.MAX_SAFE_INTEGER) ||
      (!allowStale &&
        !isServerTimestampFresh(cached.savedAt, CATALOGUE_CACHE_MAX_AGE_MS))
    ) {
      return null;
    }
    return {
      courses: cached.courses.map(course => {
        const remoteUri = isApiRecord(course.image)
          ? displayImageUrl(course.image.uri)
          : undefined;
        return {
          ...course,
          image: remoteUri ? {uri: remoteUri} : COURSE_COVER_FALLBACK,
          owned: false,
        } as Course;
      }),
      page: cached.page,
      hasMore: cached.hasMore,
      total: cached.total,
      fromCache: true,
      revision: cached.revision,
    };
  } catch {
    return null;
  }
};

export const readCatalogueCache = (
  page: number,
  scopedBaseKey?: string,
  expectedRevision?: number,
  allowStale = false,
): Promise<PublishedCoursesPage | null> =>
  settleWithin(
    readCatalogueCacheAfterWrites(
      page,
      scopedBaseKey,
      expectedRevision,
      allowStale,
    ),
    null,
  );

const writeCatalogueCache = async (
  result: Omit<PublishedCoursesPage, 'fromCache'>,
  scopedBaseKey?: string,
) => {
  if (result.page > CATALOGUE_CACHE_PAGE_LIMIT) return;
  const record: CatalogueCacheRecord = {
    version: 6,
    savedAt: serverNowMs(),
    courses: result.courses.map(course => {
      const remoteUri = isApiRecord(course.image)
        ? displayImageUrl(course.image.uri)
        : undefined;
      const publicCourse = {...course};
      delete publicCourse.owned;
      delete publicCourse.progress;
      return {
        ...publicCourse,
        image: remoteUri ? {uri: remoteUri} : undefined,
      };
    }),
    page: result.page,
    hasMore: result.hasMore,
    total: result.total,
    revision: result.revision,
  };
  await AsyncStorage.setItem(
    catalogueCacheKey(result.page, scopedBaseKey),
    JSON.stringify(record),
  );
};

export const removeCatalogueCachePages = async (
  firstPage: number,
  scopedBaseKey?: string,
) => {
  const pages = Array.from(
    {length: Math.max(0, CATALOGUE_CACHE_PAGE_LIMIT - firstPage + 1)},
    (_, index) => firstPage + index,
  );
  if (!pages.length) return;
  await AsyncStorage.multiRemove(
    pages.map(page => catalogueCacheKey(page, scopedBaseKey)),
  );
};

export const cacheCatalogueResult = (
  result: Omit<PublishedCoursesPage, 'fromCache'>,
  generation = catalogueGeneration,
) => {
  return queueCatalogueWrite(async () => {
    if (generation !== catalogueGeneration) return;
    if (result.page === 1) {
      await removeCatalogueCachePages(2);
    } else if (!result.hasMore) {
      await removeCatalogueCachePages(result.page + 1);
    }
    await writeCatalogueCache(result);
    if (result.page === 1 && generation === catalogueGeneration) {
      catalogueCacheUsable = true;
    }
  });
};

/** Retire the public snapshot, not an account's learning entitlement. */
export const invalidateCatalogueCache = () => {
  const generation = ++catalogueGeneration;
  catalogueCacheUsable = false;
  return queueCatalogueWrite(async () => {
    await removeCatalogueCachePages(1);
    // A failed native deletion must not make the old disk snapshot usable.
    // A later successful fresh page-one write can establish it again.
    if (generation === catalogueGeneration) catalogueCacheUsable = true;
  });
};
