import type {CoinTask} from '../api/coinTasks';
import type {CourseDetails} from '../api/courseContractTypes';
import type {PublishedCoursesPage} from '../api/courseContractTypes';
import {isApiRecord} from '../api/common';
import {displayImageUrl} from '../api/courseFields';
import {normalizeCachedLearningDashboard} from '../api/learningDashboardSnapshot';
import type {WalletSnapshot} from '../api/walletEconomy';
import type {Course} from '../../types/Course';
import {isAccountScope, PUBLIC_QUERY_SCOPE} from './queryKeys';

/**
 * What each read persisted before TanStack Query, and for how long. The
 * device snapshot keeps exactly these reads and these ages; everything else
 * lives in memory only.
 *
 * `dehydrate` shapes data before it is written, `revive` validates and
 * rebuilds it on restore (returning `null` drops that entry, as the previous
 * hand-written readers did for damaged or foreign records).
 */
export type PersistedQueryPolicy = {
  maxAgeMs: number;
  /** Keep only the most recently updated entries of this family. */
  maxEntries?: number;
  accepts: (queryKey: readonly unknown[]) => boolean;
  dehydrate?: (data: unknown) => unknown;
  revive: (data: unknown, queryKey: readonly unknown[]) => unknown | null;
};

const HOUR = 60 * 60 * 1000;
const CATALOGUE_PAGE_LIMIT = 4;
const COURSE_COVER_FALLBACK = require('../../assets/images/courseSlider.jpg');

type InfinitePages<T> = {pages: T[]; pageParams: unknown[]};

const isInfinitePages = (value: unknown): value is InfinitePages<unknown> =>
  isApiRecord(value) &&
  Array.isArray(value.pages) &&
  Array.isArray(value.pageParams) &&
  value.pages.length === value.pageParams.length;

const remoteImage = (image: unknown) => {
  const remoteUri = isApiRecord(image) ? displayImageUrl(image.uri) : undefined;
  return remoteUri ? {uri: remoteUri} : undefined;
};

/** Public catalogue: the first four browse pages for two hours, as before. */
const cataloguePolicy: PersistedQueryPolicy = {
  maxAgeMs: 2 * HOUR,
  accepts: queryKey =>
    queryKey[0] === PUBLIC_QUERY_SCOPE &&
    queryKey[1] === 'catalogue' &&
    queryKey[2] === '',
  dehydrate: data => {
    if (!isInfinitePages(data)) return data;
    return {
      pages: (data.pages as PublishedCoursesPage[])
        .slice(0, CATALOGUE_PAGE_LIMIT)
        .map(page => ({
          ...page,
          // Bundled artwork ids are build-specific; keep only remote covers.
          courses: page.courses.map(course => {
            const publicCourse: Partial<Course> = {
              ...course,
              image: remoteImage(course.image),
            };
            delete publicCourse.owned;
            delete publicCourse.progress;
            return publicCourse;
          }),
        })),
      pageParams: data.pageParams.slice(0, CATALOGUE_PAGE_LIMIT),
    };
  },
  revive: data => {
    if (!isInfinitePages(data) || data.pages.length === 0) return null;
    const pages = data.pages as Array<Record<string, unknown>>;
    const first = pages[0];
    if (!isApiRecord(first) || first.page !== 1) return null;
    // One snapshot is one catalogue revision read from page one onward. A
    // page from another revision (or a gap) ends the usable prefix, as the
    // old per-page reader discarded a page whose revision did not match.
    const consistent: Array<Record<string, unknown>> = [];
    for (const [index, page] of pages.entries()) {
      if (
        !isApiRecord(page) ||
        page.page !== index + 1 ||
        !Number.isSafeInteger(page.revision) ||
        Number(page.revision) < 1 ||
        page.revision !== first.revision ||
        !Array.isArray(page.courses) ||
        !(page.courses as unknown[]).every(
          course =>
            isApiRecord(course) &&
            typeof course.id === 'string' &&
            typeof course.title === 'string',
        )
      ) {
        break;
      }
      consistent.push(page);
    }
    if (!consistent.length) return null;
    return {
      pages: consistent.map(page => ({
        ...page,
        reset: false,
        courses: (page.courses as Array<Record<string, unknown>>).map(
          course => ({
            ...course,
            image: remoteImage(course.image) ?? COURSE_COVER_FALLBACK,
            owned: false,
          }),
        ),
      })),
      pageParams: data.pageParams.slice(0, consistent.length),
    };
  },
};

/**
 * Course details were cached per account, but only a guest ever read them
 * back (signed-in failures stay errors, since details drive owned playback).
 * Keep the guest copy only, eight courses, 24 hours, without the learning
 * envelope.
 */
const courseDetailsPolicy: PersistedQueryPolicy = {
  maxAgeMs: 24 * HOUR,
  maxEntries: 8,
  accepts: queryKey =>
    typeof queryKey[0] === 'string' &&
    queryKey[0].startsWith('guest-') &&
    queryKey[1] === 'course-details',
  dehydrate: data =>
    isApiRecord(data)
      ? {course: data.course, learning: null, learningRead: null}
      : data,
  revive: (data, queryKey) => {
    if (!isApiRecord(data) || !isApiRecord(data.course)) return null;
    const course = data.course as CourseDetails;
    if (
      course.id !== queryKey[2] ||
      !Number.isInteger(course.publishedRevision) ||
      course.publishedRevision < 1 ||
      typeof course.title !== 'string' ||
      !Array.isArray(course.accessPlans)
    ) {
      return null;
    }
    return {
      course: {...course, fromCache: true},
      learning: null,
      learningRead: null,
    };
  },
};

const learningDashboardPolicy: PersistedQueryPolicy = {
  maxAgeMs: 6 * HOUR,
  accepts: queryKey =>
    isAccountScope(queryKey[0]) && queryKey[1] === 'learning-dashboard',
  dehydrate: data => {
    if (!isApiRecord(data)) return data;
    // A partial read is persisted with the panels it kept, without its notice.
    const dashboard = {...data};
    delete dashboard.partialError;
    return {
      ...dashboard,
      courses: Array.isArray(dashboard.courses)
        ? dashboard.courses.slice(0, 100)
        : dashboard.courses,
    };
  },
  revive: data => normalizeCachedLearningDashboard(data),
};

const isNonNegativeFinite = (value: unknown) =>
  Number.isFinite(Number(value)) && Number(value) >= 0;

const validWallet = (value: unknown): value is WalletSnapshot => {
  if (!value || typeof value !== 'object') return false;
  const wallet = value as WalletSnapshot;
  return (
    isNonNegativeFinite(wallet.balance) &&
    isNonNegativeFinite(wallet.paidBalance) &&
    isNonNegativeFinite(wallet.rewardBalance) &&
    isNonNegativeFinite(wallet.spendableBalance) &&
    isNonNegativeFinite(wallet.rewardContributionCap) &&
    Number(wallet.paidBalance) + Number(wallet.rewardBalance) ===
      Number(wallet.balance) &&
    wallet.spendPolicy === 'reward_first_then_paid' &&
    Number(wallet.spendableBalance) ===
      Number(wallet.paidBalance) +
        Math.min(
          Number(wallet.rewardBalance),
          Number(wallet.rewardContributionCap),
        ) &&
    Array.isArray(wallet.coinRules) &&
    wallet.coinRules.every(rule => typeof rule === 'string') &&
    (wallet.rewardRules === undefined ||
      (Array.isArray(wallet.rewardRules) &&
        wallet.rewardRules.every(rule => typeof rule === 'string'))) &&
    (wallet.rewardTransactions === undefined ||
      (Array.isArray(wallet.rewardTransactions) &&
        wallet.rewardTransactions.every(
          item =>
            item &&
            typeof item.id === 'string' &&
            item.id.trim().length > 0 &&
            typeof item.label === 'string' &&
            item.label.trim().length > 0 &&
            Number.isSafeInteger(item.amount) &&
            item.amount !== 0 &&
            (item.category === undefined ||
              typeof item.category === 'string') &&
            (item.occurred_at === undefined ||
              typeof item.occurred_at === 'string'),
        ))) &&
    Array.isArray(wallet.transactions) &&
    wallet.transactions.every(
      item =>
        item &&
        typeof item.id === 'string' &&
        item.id.length > 0 &&
        Number.isFinite(Number(item.amount)) &&
        typeof item.label === 'string' &&
        item.label.trim().length > 0 &&
        (item.occurred_at === undefined ||
          typeof item.occurred_at === 'string'),
    )
  );
};

const validTasks = (value: unknown): value is CoinTask[] =>
  Array.isArray(value) &&
  value.every(
    item =>
      item &&
      typeof item.id === 'string' &&
      /^\d+$/.test(item.serverId) &&
      Number.isSafeInteger(Number(item.serverId)) &&
      Number(item.serverId) > 0 &&
      item.id === `production-${item.serverId}` &&
      typeof item.title === 'string' &&
      item.title.trim().length > 0 &&
      isNonNegativeFinite(item.reward) &&
      Number.isSafeInteger(Number(item.reward)) &&
      Number(item.reward) > 0 &&
      ['available', 'started', 'ready_to_claim', 'claimed'].includes(
        item.status,
      ) &&
      typeof item.actionKey === 'string' &&
      item.actionKey.trim().length > 0 &&
      typeof item.requiresExternalVisit === 'boolean' &&
      (item.url === undefined || typeof item.url === 'string'),
  );

/** The wallet snapshot had no age limit; it is replaced by every read. */
const walletPolicy = (
  family: 'wallet' | 'coin-tasks',
  valid: (value: unknown) => boolean,
): PersistedQueryPolicy => ({
  maxAgeMs: Number.POSITIVE_INFINITY,
  accepts: queryKey => isAccountScope(queryKey[0]) && queryKey[1] === family,
  revive: data => (valid(data) ? data : null),
});

const policies: Record<string, PersistedQueryPolicy> = {
  catalogue: cataloguePolicy,
  'course-details': courseDetailsPolicy,
  'learning-dashboard': learningDashboardPolicy,
  wallet: walletPolicy('wallet', validWallet),
  'coin-tasks': walletPolicy('coin-tasks', validTasks),
};

export const persistedQueryPolicy = (
  queryKey: readonly unknown[],
): PersistedQueryPolicy | null => {
  const policy =
    typeof queryKey[1] === 'string' ? policies[queryKey[1]] : undefined;
  return policy && policy.accepts(queryKey) ? policy : null;
};

export const persistedQueryFamily = (queryKey: readonly unknown[]) =>
  `${String(queryKey[0])}:${String(queryKey[1])}`;
