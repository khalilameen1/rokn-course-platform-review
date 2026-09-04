import AsyncStorage from '@react-native-async-storage/async-storage';
import {roknApiUrl} from '../../constants/apiBaseUrl';
import {accountScopedStorageKey} from '../../constants/helpers';
import type {Course} from '../../types/Course';
import {isServerTimestampFresh, serverNowMs} from '../../utils/serverClock';
import {settleWithin} from '../../utils/settleWithin';
import type {CourseDetails, PublishedCoursesPage} from './courseContracts';
import {isApiRecord} from './common';
import {displayImageUrl} from './courseFields';

export const CATALOGUE_CACHE_KEY = `@rokn/catalogue-page/v6:${encodeURIComponent(
  roknApiUrl,
)}`;
export const COURSE_DETAILS_CACHE_KEY = '@rokn/course-details/v5';

const CATALOGUE_CACHE_MAX_AGE_MS = 2 * 60 * 60 * 1000;
const CATALOGUE_CACHE_PAGE_LIMIT = 4;
const COURSE_DETAILS_CACHE_MAX_AGE_MS = 24 * 60 * 60 * 1000;
const COURSE_DETAILS_CACHE_LIMIT = 8;
const COURSE_COVER_FALLBACK = require('../../assets/images/courseSlider.jpg');

let catalogueWriteTail: Promise<void> = Promise.resolve();
let courseDetailsWriteTail: Promise<void> = Promise.resolve();

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

type CourseDetailsCacheRecord = {
  version: 5;
  savedAt: number;
  course: CourseDetails;
};

const catalogueCacheKey = (page: number, baseKey = CATALOGUE_CACHE_KEY) =>
  `${baseKey}:${page}`;

export const readCatalogueCache = async (
  page: number,
  scopedBaseKey?: string,
  expectedRevision?: number,
  allowStale = false,
): Promise<PublishedCoursesPage | null> => {
  if (page > CATALOGUE_CACHE_PAGE_LIMIT) return null;
  try {
    await settleWithin(catalogueWriteTail, undefined);
    const raw = await AsyncStorage.getItem(
      catalogueCacheKey(page, scopedBaseKey),
    );
    if (!raw) return null;
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
) => {
  const write = catalogueWriteTail
    .catch(() => undefined)
    .then(async () => {
      if (result.page === 1) {
        await removeCatalogueCachePages(2);
      } else if (!result.hasMore) {
        await removeCatalogueCachePages(result.page + 1);
      }
      await writeCatalogueCache(result);
    });
  catalogueWriteTail = write.catch(() => undefined);
  return write;
};

const courseDetailsCacheKey = async (
  courseId: string,
  scopedBaseKey?: string,
) =>
  `${
    scopedBaseKey || (await accountScopedStorageKey(COURSE_DETAILS_CACHE_KEY))
  }:${courseId}`;

const courseDetailsCacheIndexKey = (scopedBaseKey: string) =>
  `${scopedBaseKey}:index`;

const withCourseDetailsCacheLock = <T>(operation: () => Promise<T>) => {
  const result = courseDetailsWriteTail.then(operation, operation);
  courseDetailsWriteTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const readCourseDetailsCacheIndex = async (
  scopedBaseKey: string,
): Promise<string[]> => {
  try {
    const raw = await AsyncStorage.getItem(
      courseDetailsCacheIndexKey(scopedBaseKey),
    );
    if (raw === null) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed)
      ? Array.from(
          new Set(
            parsed
              .map(value => String(value))
              .filter(value => /^\d+$/.test(value)),
          ),
        )
      : [];
  } catch {
    return [];
  }
};

export const cacheCourseDetails = async (
  courseId: string,
  course: CourseDetails,
  scopedBaseKey: string,
) =>
  withCourseDetailsCacheLock(async () => {
    const current = await readCourseDetailsCacheIndex(scopedBaseKey);
    const ordered = [courseId, ...current.filter(id => id !== courseId)];
    const retained = ordered.slice(0, COURSE_DETAILS_CACHE_LIMIT);
    const evicted = ordered.slice(COURSE_DETAILS_CACHE_LIMIT);
    await AsyncStorage.setItem(
      courseDetailsCacheIndexKey(scopedBaseKey),
      JSON.stringify(retained),
    );
    if (evicted.length) {
      await AsyncStorage.multiRemove(
        await Promise.all(
          evicted.map(id => courseDetailsCacheKey(id, scopedBaseKey)),
        ),
      );
    }
    const record: CourseDetailsCacheRecord = {
      version: 5,
      savedAt: serverNowMs(),
      course,
    };
    await AsyncStorage.setItem(
      await courseDetailsCacheKey(courseId, scopedBaseKey),
      JSON.stringify(record),
    );
  });

export const touchCourseDetailsCache = async (
  courseId: string,
  scopedBaseKey: string,
) =>
  withCourseDetailsCacheLock(async () => {
    const current = await readCourseDetailsCacheIndex(scopedBaseKey);
    const retained = [courseId, ...current.filter(id => id !== courseId)].slice(
      0,
      COURSE_DETAILS_CACHE_LIMIT,
    );
    await AsyncStorage.setItem(
      courseDetailsCacheIndexKey(scopedBaseKey),
      JSON.stringify(retained),
    );
  });

export const readCourseDetailsCache = async (
  courseId: string,
  scopedBaseKey?: string,
  allowStale = false,
): Promise<CourseDetails | null> => {
  try {
    const raw = await AsyncStorage.getItem(
      await courseDetailsCacheKey(courseId, scopedBaseKey),
    );
    if (!raw) return null;
    const cached = JSON.parse(raw) as CourseDetailsCacheRecord;
    if (
      cached.version !== 5 ||
      !isServerTimestampFresh(cached.savedAt, Number.MAX_SAFE_INTEGER) ||
      (!allowStale &&
        !isServerTimestampFresh(
          cached.savedAt,
          COURSE_DETAILS_CACHE_MAX_AGE_MS,
        )) ||
      !cached.course ||
      cached.course.id !== courseId ||
      !Number.isInteger(cached.course.publishedRevision) ||
      cached.course.publishedRevision < 1 ||
      typeof cached.course.title !== 'string' ||
      !Array.isArray(cached.course.accessPlans)
    ) {
      return null;
    }
    return {...cached.course, fromCache: true};
  } catch {
    return null;
  }
};

export const removeCourseDetailsCache = async (
  courseId: string,
  scopedBaseKey?: string,
) => {
  const resolvedBaseKey =
    scopedBaseKey || (await accountScopedStorageKey(COURSE_DETAILS_CACHE_KEY));
  await withCourseDetailsCacheLock(async () => {
    const current = await readCourseDetailsCacheIndex(resolvedBaseKey);
    await Promise.all([
      AsyncStorage.removeItem(
        await courseDetailsCacheKey(courseId, resolvedBaseKey),
      ),
      AsyncStorage.setItem(
        courseDetailsCacheIndexKey(resolvedBaseKey),
        JSON.stringify(current.filter(id => id !== courseId)),
      ),
    ]);
  });
};
