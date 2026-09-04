import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {publicRequest} from '../../constants/api';
import {getLearningCourses, type CourseProgress} from './courses';
import {
  firstBoolean,
  isApiRecord,
  isResourceListPayload,
  payload,
  resourceList,
} from './common';
import {isServerTimestampFresh, serverNowMs} from '../../utils/serverClock';

type EarnedBadgeDto = {
  id?: unknown;
  level_id?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  badge_image?: unknown;
  course_id?: unknown;
  course_name_ar?: unknown;
  course_name_en?: unknown;
  track?: unknown;
  earned_at?: unknown;
};

type ProfileLearningDto = {earned_badges?: unknown};
type StreakDayDto = {has_streak?: unknown; date?: unknown};
type StreakDto = {
  week?: {days?: unknown};
  current_streak?: unknown;
  last_streak_before_gap?: unknown;
};

type LearningPathLevelDto = {
  id?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  badge_image_url?: unknown;
  order?: unknown;
};

type LearningPathDto = {
  path?: {
    id?: unknown;
    title?: unknown;
    title_ar?: unknown;
    title_en?: unknown;
  };
  current_level?: LearningPathLevelDto | null;
  next_level?: LearningPathLevelDto | null;
  levels?: unknown;
  progress_percentage?: unknown;
  required_progress_percentage?: unknown;
  completed_sections?: unknown;
  total_sections?: unknown;
};

export type LearningCourse = CourseProgress;

export type LearningDashboard = {
  courses: LearningCourse[];
  paths: LearningPathProgress[];
  badges: Array<{
    id: string;
    levelId?: string;
    title: string;
    imageUrl?: string;
    courseId?: string;
    courseTitle?: string;
    track?: string;
    earnedAt?: string;
  }>;
  activityDays: string[];
  currentStreakDays: number;
  /** Present when fresh courses arrived but a secondary panel stayed cached. */
  partialError?: string;
};

export type LearningPathLevel = {
  id: string;
  name: string;
  imageUrl?: string;
  order: number;
};

export type LearningPathProgress = {
  id: string;
  title: string;
  currentLevel?: LearningPathLevel;
  nextLevel?: LearningPathLevel;
  upcomingLevels: LearningPathLevel[];
  progress: number;
  remainingToNextLevel: number;
  completedSections: number;
  totalSections: number;
};

const mapPathLevel = (
  level?: LearningPathLevelDto | null,
): LearningPathLevel | undefined => {
  if (level === null || level === undefined) return undefined;
  if (
    !isApiRecord(level) ||
    level.id === null ||
    level.id === undefined ||
    !/^\d+$/.test(String(level.id).trim()) ||
    (!String(level.name_ar || '').trim() &&
      !String(level.name_en || '').trim()) ||
    !Number.isSafeInteger(Number(level.order)) ||
    Number(level.order) < 0
  ) {
    throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
  }
  return {
    id: String(level.id),
    name: String(level.name_ar || level.name_en || 'المستوى التالي'),
    imageUrl: level.badge_image_url ? String(level.badge_image_url) : undefined,
    order: Math.max(0, Number(level.order) || 0),
  };
};

const getLearningPaths = async (): Promise<LearningPathProgress[]> => {
  const data = payload<unknown>(await publicRequest.get('user/paths'));
  if (!isResourceListPayload(data)) {
    throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
  }
  return resourceList<LearningPathDto>(data).map(item => {
    if (
      !isApiRecord(item) ||
      !isApiRecord(item.path) ||
      item.path.id === null ||
      item.path.id === undefined
    ) {
      throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
    }
    const currentLevel = mapPathLevel(item.current_level);
    const nextLevel = mapPathLevel(item.next_level);
    const seenLevelIds = new Set<string>();
    if (!isResourceListPayload(item.levels)) {
      throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
    }
    const upcomingLevels = resourceList<LearningPathLevelDto>(item.levels)
      .map(level => {
        const mapped = mapPathLevel(level);
        if (
          !mapped ||
          mapped.id === currentLevel?.id ||
          seenLevelIds.has(mapped.id)
        ) {
          throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
        }
        seenLevelIds.add(mapped.id);
        return mapped;
      })
      .sort((left, right) => left.order - right.order);
    if (nextLevel && upcomingLevels[0]?.id !== nextLevel.id) {
      throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
    }
    return {
      id: String(item.path.id),
      title: String(
        item.path.title ||
          item.path.title_ar ||
          item.path.title_en ||
          'مسارك المهني',
      ),
      currentLevel,
      nextLevel,
      upcomingLevels,
      progress: Math.min(
        100,
        Math.max(0, Number(item.progress_percentage) || 0),
      ),
      remainingToNextLevel: Math.min(
        100,
        Math.max(0, Number(item.required_progress_percentage) || 0),
      ),
      completedSections: Math.max(0, Number(item.completed_sections) || 0),
      totalSections: Math.max(0, Number(item.total_sections) || 0),
    };
  });
};

const LEARNING_DASHBOARD_CACHE = '@rokn/learning-dashboard/v3';
const LEARNING_DASHBOARD_CACHE_TTL_MS = 6 * 60 * 60 * 1000;

type LearningDashboardCache = {
  version: 3;
  savedAt: number;
  dashboard: LearningDashboard;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const normalizeCachedLearningDashboard = (
  value: unknown,
): LearningDashboard | null => {
  if (!isRecord(value)) return null;
  if (
    !Array.isArray(value.courses) ||
    value.courses.some(
      course =>
        !isRecord(course) ||
        typeof course.id !== 'string' ||
        !/^[1-9]\d*$/.test(course.id) ||
        typeof course.title !== 'string' ||
        course.title.trim().length === 0 ||
        !Number.isFinite(course.progress) ||
        Number(course.progress) < 0 ||
        Number(course.progress) > 100 ||
        !Number.isFinite(course.completedSections) ||
        !Number.isSafeInteger(course.completedSections) ||
        Number(course.completedSections) < 0 ||
        !Number.isFinite(course.totalSections) ||
        !Number.isSafeInteger(course.totalSections) ||
        Number(course.totalSections) < 1 ||
        Number(course.completedSections) > Number(course.totalSections) ||
        !['freelance', 'language', 'religious'].includes(
          String(course.category || ''),
        ) ||
        typeof course.accessType !== 'string' ||
        course.accessType.length === 0 ||
        typeof course.chatAvailable !== 'boolean' ||
        typeof course.certificateAvailable !== 'boolean' ||
        (course.nextSectionType !== undefined &&
          (!['lesson', 'project'].includes(String(course.nextSectionType)) ||
            typeof course.nextSectionId !== 'string' ||
            !/^[1-9]\d*$/.test(course.nextSectionId) ||
            typeof course.nextSectionTitle !== 'string' ||
            course.nextSectionTitle.trim().length === 0)),
    ) ||
    !Array.isArray(value.paths) ||
    value.paths.some(
      path =>
        !isRecord(path) ||
        typeof path.id !== 'string' ||
        path.id.length === 0 ||
        typeof path.title !== 'string' ||
        !Array.isArray(path.upcomingLevels) ||
        !Number.isFinite(path.progress) ||
        !Number.isFinite(path.remainingToNextLevel),
    ) ||
    !Array.isArray(value.badges) ||
    value.badges.some(
      badge =>
        !isRecord(badge) ||
        typeof badge.id !== 'string' ||
        badge.id.length === 0 ||
        typeof badge.title !== 'string',
    ) ||
    !Array.isArray(value.activityDays) ||
    value.activityDays.some(
      day => typeof day !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(day),
    )
  ) {
    return null;
  }
  const courses = value.courses as LearningCourse[];
  const paths = value.paths as LearningPathProgress[];
  const badges = value.badges as LearningDashboard['badges'];
  const activityDays = value.activityDays as string[];
  const currentStreakDays = Number(value.currentStreakDays);
  if (!Number.isFinite(currentStreakDays)) return null;
  return {
    courses: courses.slice(0, 100),
    paths: paths.slice(0, 50),
    badges: badges.slice(0, 200),
    activityDays: Array.from(new Set(activityDays)).slice(-31),
    currentStreakDays: Math.max(0, Math.floor(currentStreakDays)),
  };
};

export const getCachedLearningDashboard = async () => {
  const accountBoundary = await captureAccountSessionBoundary();
  try {
    const raw = await AsyncStorage.getItem(
      await accountScopedStorageKey(LEARNING_DASHBOARD_CACHE, accountBoundary),
    );
    if (!raw) return null;
    const cached = JSON.parse(raw) as Partial<LearningDashboardCache>;
    if (
      cached.version !== 3 ||
      !isServerTimestampFresh(
        Number(cached.savedAt),
        LEARNING_DASHBOARD_CACHE_TTL_MS,
      )
    ) {
      return null;
    }
    const dashboard = normalizeCachedLearningDashboard(cached.dashboard);
    assertAccountSessionBoundary(accountBoundary);
    return dashboard;
  } catch {
    assertAccountSessionBoundary(accountBoundary);
    return null;
  }
};

export const getLearningDashboard = async (): Promise<LearningDashboard> => {
  const accountBoundary = await captureAccountSessionBoundary();
  const cacheKeyRequest = accountScopedStorageKey(
    LEARNING_DASHBOARD_CACHE,
    accountBoundary,
  );
  const cachedDashboardRequest = getCachedLearningDashboard();
  const dashboardRequest = Promise.allSettled([
    publicRequest.get('user/profile', {
      params: {include_learning: 0, include_badges: 1},
    }),
    publicRequest.get('streaks'),
    getLearningCourses(),
    getLearningPaths(),
  ]);
  const [dashboardCacheKey, cachedDashboard, dashboardResults] =
    await Promise.all([
      cacheKeyRequest,
      cachedDashboardRequest,
      dashboardRequest,
    ]);
  const [profileResult, streakResult, learningResult, pathsResult] =
    dashboardResults;
  assertAccountSessionBoundary(accountBoundary);
  if (learningResult.status === 'rejected') throw learningResult.reason;
  const partialFailure = [profileResult, streakResult, pathsResult].some(
    result => result.status === 'rejected',
  );
  const profile =
    profileResult.status === 'fulfilled'
      ? payload<ProfileLearningDto>(profileResult.value)
      : {};
  const streak =
    streakResult.status === 'fulfilled'
      ? payload<StreakDto>(streakResult.value)
      : {};
  const dashboard: LearningDashboard = {
    courses: learningResult.value,
    paths:
      pathsResult.status === 'fulfilled'
        ? pathsResult.value
        : cachedDashboard?.paths || [],
    badges:
      profileResult.status === 'fulfilled'
        ? resourceList<EarnedBadgeDto>(profile.earned_badges).flatMap(badge => {
            const id = String(badge.id ?? '').trim();
            if (!id) return [];
            return [
              {
                id,
                levelId: badge.level_id ? String(badge.level_id) : undefined,
                title: String(badge.name_ar || badge.name_en || 'شارة مهنية'),
                imageUrl: badge.badge_image
                  ? String(badge.badge_image)
                  : undefined,
                courseId: badge.course_id ? String(badge.course_id) : undefined,
                courseTitle:
                  badge.course_name_ar || badge.course_name_en
                    ? String(badge.course_name_ar || badge.course_name_en)
                    : undefined,
                track: badge.track ? String(badge.track) : undefined,
                earnedAt: badge.earned_at ? String(badge.earned_at) : undefined,
              },
            ];
          })
        : cachedDashboard?.badges || [],
    activityDays:
      streakResult.status === 'fulfilled'
        ? resourceList<StreakDayDto>(streak.week?.days)
            .filter(
              day =>
                firstBoolean(day?.has_streak) === true &&
                typeof day?.date === 'string',
            )
            .map(day => String(day.date))
        : cachedDashboard?.activityDays || [],
    currentStreakDays:
      streakResult.status === 'fulfilled'
        ? Math.max(
            0,
            Number(
              streak.current_streak ?? streak.last_streak_before_gap ?? 0,
            ) || 0,
          )
        : cachedDashboard?.currentStreakDays || 0,
    ...(partialFailure
      ? {partialError: 'تعذّر تحديث بعض بيانات تقدمك\nنعرض آخر نسخة متاحة'}
      : {}),
  };
  // The backend already caps active courses at 100. Keeping the complete
  // metadata set prevents older active courses from disappearing offline.
  if (!partialFailure) {
    await AsyncStorage.setItem(
      dashboardCacheKey,
      JSON.stringify({
        version: 3,
        savedAt: serverNowMs(),
        dashboard: {...dashboard, courses: dashboard.courses.slice(0, 100)},
      } satisfies LearningDashboardCache),
    ).catch(() => undefined);
  }
  assertAccountSessionBoundary(accountBoundary);
  return dashboard;
};
