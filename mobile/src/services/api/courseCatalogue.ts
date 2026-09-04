import {
  DEFAULT_READ_RECOVERY_BUDGET_MS,
  publicRequest,
  type RoknRequestConfig,
} from '../../constants/api';
import type {Course} from '../../types/Course';
import {normalizeText} from '../../utils/searchText';
import {settleWithin} from '../../utils/settleWithin';
import {truncateGraphemes} from '../../utils/unicodeText';
import {transientReadFailureAllowsCache} from '../networkExperience';
import {payload} from './common';
import {cacheCatalogueResult, readCatalogueCache} from './courseCache';
import {
  mapCatalogueCoursesPayload,
  type PublishedCoursesPage,
} from './courseContracts';

type CatalogueFailure = {
  code?: unknown;
  status?: unknown;
  data?: {code?: unknown; data?: {code?: unknown}};
  response?: {
    status?: unknown;
    data?: {code?: unknown; data?: {code?: unknown}};
  };
};

const isCatalogueChanged = (error: unknown) => {
  const failure = error as CatalogueFailure;
  const status = Number(failure.status ?? failure.response?.status ?? 0);
  const code = String(
    failure.data?.code ??
      failure.data?.data?.code ??
      failure.response?.data?.code ??
      failure.response?.data?.data?.code ??
      '',
  );
  return (
    failure.code === 'catalogue_changed' ||
    (status === 409 && code === 'catalogue_changed')
  );
};

export const getPublishedCoursesPage = async ({
  page = 1,
  perPage = 30,
  search = '',
  revision,
  signal,
  revisionConflictRetry = true,
}: {
  page?: number;
  perPage?: number;
  search?: string;
  revision?: number;
  signal?: AbortSignal;
  revisionConflictRetry?: boolean;
} = {}): Promise<PublishedCoursesPage> => {
  const safePage = Math.max(1, Math.floor(page));
  const normalizedSearch = truncateGraphemes(normalizeText(search), 120);
  const expectedRevision =
    Number.isSafeInteger(revision) && Number(revision) > 0
      ? Number(revision)
      : undefined;
  const safePerPage = Math.max(
    1,
    Math.min(normalizedSearch ? 20 : 50, Math.floor(perPage)),
  );
  const retryDeadlineAt = Date.now() + DEFAULT_READ_RECOVERY_BUDGET_MS;

  try {
    const response = await publicRequest.get(
      normalizedSearch ? 'search/courses' : 'courses/list',
      {
        // Published discovery is account-neutral. Binding it to an optional
        // bearer makes a cold-start guest request fail if secure-session
        // restore finishes while the response is in flight. Ownership is
        // applied independently by useCourseAccessOverlay after bootstrap.
        skipAuthorization: true,
        roknNetworkRetryDeadlineAt: retryDeadlineAt,
        signal,
        params: {
          page: safePage,
          per_page: safePerPage,
          ...(normalizedSearch ? {q: normalizedSearch} : {}),
          ...(safePage > 1 && expectedRevision
            ? {catalogue_revision: expectedRevision}
            : {}),
        },
      } as RoknRequestConfig,
    );
    const data = payload(response);
    const responseRevision = Number(data?.catalogue_revision);
    if (!Number.isSafeInteger(responseRevision) || responseRevision < 1) {
      throw new Error('COURSE_CATALOGUE_CONTRACT_INVALID');
    }
    if (
      safePage > 1 &&
      expectedRevision !== undefined &&
      responseRevision !== expectedRevision
    ) {
      throw Object.assign(new Error('CATALOGUE_CHANGED'), {
        code: 'catalogue_changed',
      });
    }
    const list = normalizedSearch ? data?.items : data?.courses;
    const pagination = data?.pagination;
    if (!Array.isArray(list) || !pagination || typeof pagination !== 'object') {
      throw new Error('COURSE_CATALOGUE_CONTRACT_INVALID');
    }
    const pageRecord = pagination as Record<string, unknown>;
    const currentPage = Number(pageRecord.current_page);
    const lastPage = Number(pageRecord.last_page);
    const total = Number(pageRecord.total);
    if (
      !Number.isSafeInteger(currentPage) ||
      currentPage !== safePage ||
      !Number.isSafeInteger(lastPage) ||
      lastPage < 1 ||
      !Number.isSafeInteger(total) ||
      total < 0
    ) {
      throw new Error('COURSE_CATALOGUE_CONTRACT_INVALID');
    }
    const result = {
      courses: mapCatalogueCoursesPayload(list, Boolean(normalizedSearch)),
      page: currentPage,
      hasMore: currentPage < lastPage,
      total,
      revision: responseRevision,
    };
    if (!normalizedSearch) {
      void cacheCatalogueResult(result).catch(() => undefined);
    }
    return {...result, fromCache: false};
  } catch (error) {
    const catalogueChanged = isCatalogueChanged(error);
    if (catalogueChanged && revisionConflictRetry) {
      const replacement = await getPublishedCoursesPage({
        page: safePage > 1 ? 1 : safePage,
        perPage: safePerPage,
        search: normalizedSearch,
        signal,
        revisionConflictRetry: false,
      });
      return safePage > 1 ? {...replacement, reset: true} : replacement;
    }
    if (catalogueChanged) throw error;
    if (!normalizedSearch && transientReadFailureAllowsCache(error)) {
      const cached = await settleWithin(
        readCatalogueCache(safePage, undefined, expectedRevision),
        null,
      );
      if (cached) return cached;
    }
    throw error;
  }
};

export const getPublishedCourses = async (): Promise<Course[]> =>
  (await getPublishedCoursesPage()).courses;

export const getCachedPublishedCourses = async (): Promise<Course[]> => {
  const cached = await readCatalogueCache(1);
  return cached?.courses ?? [];
};
