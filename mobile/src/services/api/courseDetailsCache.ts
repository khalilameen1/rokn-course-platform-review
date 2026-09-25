import AsyncStorage from '@react-native-async-storage/async-storage';
import {accountScopedStorageKey} from '../../constants/helpers';
import {isServerTimestampFresh, serverNowMs} from '../../utils/serverClock';
import {createKeyedAsyncQueue} from '../../utils/keyedAsyncQueue';
import type {CourseDetails} from './courseContractTypes';

export const COURSE_DETAILS_CACHE_KEY = '@rokn/course-details/v5';
const COURSE_DETAILS_CACHE_MAX_AGE_MS = 24 * 60 * 60 * 1000;
const COURSE_DETAILS_CACHE_LIMIT = 8;
// All courses share their account's bounded index, never another account's tail.
const withCourseDetailsCacheLock = createKeyedAsyncQueue();

type CourseDetailsCacheRecord = {
  version: 5;
  savedAt: number;
  course: CourseDetails;
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
  withCourseDetailsCacheLock(scopedBaseKey, async () => {
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
  withCourseDetailsCacheLock(scopedBaseKey, async () => {
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
  await withCourseDetailsCacheLock(resolvedBaseKey, async () => {
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
