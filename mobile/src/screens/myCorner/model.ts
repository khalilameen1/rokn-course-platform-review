import {
  roknCalendarDay,
  shiftRoknCalendarDay,
} from '../../constants/roknCalendar';
import type {LearningCourse, LearningDashboard} from '../../services/roknApi';
import {serverNow} from '../../utils/serverClock';

export type LearningBadge = LearningDashboard['badges'][number];

export type LearningResumeTarget =
  | {
      courseId: string;
      lessonId: string;
      initialPositionSeconds?: number;
    }
  | {courseId: string; projectId: string};

/**
 * `next_section` is the server's canonical progression decision. The latest
 * watched lesson only contributes a seek position when it is that same next
 * section; it must never override a project gate or reopen a completed step.
 */
export const learningResumeTarget = (
  course: LearningCourse,
  ownershipIsFresh: boolean,
): LearningResumeTarget | null => {
  if (
    !ownershipIsFresh ||
    !course.started ||
    course.progress >= 100 ||
    !course.nextSectionId
  ) {
    return null;
  }
  if (course.nextSectionType === 'project') {
    return {courseId: course.id, projectId: course.nextSectionId};
  }
  if (course.nextSectionType !== 'lesson') return null;
  const position =
    course.lastLessonId === course.nextSectionId &&
    Number.isSafeInteger(course.resumePositionSeconds) &&
    Number(course.resumePositionSeconds) > 0
      ? Number(course.resumePositionSeconds)
      : undefined;
  return {
    courseId: course.id,
    lessonId: course.nextSectionId,
    initialPositionSeconds: position,
  };
};

const lastSevenDays = (activeDays: string[]) =>
  Array.from({length: 7}, (_, index) => {
    const key = shiftRoknCalendarDay(
      roknCalendarDay(serverNow()),
      -(6 - index),
    );
    return {
      key,
      day: new Date(`${key}T12:00:00Z`).toLocaleDateString('ar-EG', {
        weekday: 'narrow',
        timeZone: 'Africa/Cairo',
      }),
      complete: activeDays.includes(key),
    };
  });

const currentStreakFromDays = (activeDays: string[]) => {
  const active = new Set(activeDays);
  const today = roknCalendarDay(serverNow());
  let cursor = active.has(today) ? today : shiftRoknCalendarDay(today, -1);
  let count = 0;
  while (active.has(cursor)) {
    count += 1;
    cursor = shiftRoknCalendarDay(cursor, -1);
  }
  return count;
};

const orderedByResume = (courses: LearningCourse[]) =>
  [...courses].sort((first, second) => {
    const completionOrder =
      Number(first.progress >= 100) - Number(second.progress >= 100);
    if (completionOrder !== 0) return completionOrder;
    const firstSeen = Date.parse(first.lastWatchedAt || '') || 0;
    const secondSeen = Date.parse(second.lastWatchedAt || '') || 0;
    if (firstSeen !== secondSeen) return secondSeen - firstSeen;
    return second.progress - first.progress;
  });

export const buildMyCornerModel = ({
  dashboard,
  selectedPathId,
  signedIn,
}: {
  dashboard: LearningDashboard | null;
  selectedPathId: string | null;
  signedIn: boolean;
}) => {
  const courses = signedIn ? dashboard?.courses || [] : [];
  const orderedCourses = orderedByResume(courses);
  const hasActiveCourses = orderedCourses.some(
    course => course.started && course.progress < 100,
  );
  const allCoursesCompleted =
    orderedCourses.length > 0 &&
    orderedCourses.every(course => course.progress >= 100);
  const primaryResumeId = orderedCourses.find(
    course => course.started && course.progress < 100,
  )?.id;
  const professionalCourses = courses.filter(
    course => course.category === 'freelance',
  );
  const professionalProgress = professionalCourses.length
    ? Math.max(...professionalCourses.map(course => course.progress))
    : 0;
  const learningPaths = dashboard?.paths || [];
  const selectedPath =
    learningPaths.find(path => path.id === selectedPathId) || learningPaths[0];
  const pathProgress = selectedPath?.progress ?? professionalProgress;
  const nextPathLevel = selectedPath?.nextLevel;
  const professionalCourseIds = new Set(
    professionalCourses.map(course => String(course.id)),
  );
  const earnedBadges: LearningBadge[] = signedIn
    ? (dashboard?.badges || []).filter(
        badge =>
          ['professional', 'freelance'].includes(
            String(badge.track || '').toLowerCase(),
          ) ||
          (badge.courseId
            ? professionalCourseIds.has(String(badge.courseId))
            : false),
      )
    : [];
  const activityDays = dashboard?.activityDays || [];
  const currentStreak =
    signedIn && Number.isFinite(dashboard?.currentStreakDays)
      ? Math.max(0, Number(dashboard?.currentStreakDays))
      : currentStreakFromDays(activityDays);

  return {
    activityDays,
    courses,
    currentStreak,
    displayedBadges: earnedBadges.length
      ? earnedBadges
      : ([
          {
            id: 'next-junior-badge',
            title: 'Junior',
            courseTitle: 'أول شارة في مسارك المهني',
          },
        ] as LearningBadge[]),
    earnedProfessionalBadge: earnedBadges.length > 0,
    allCoursesCompleted,
    hasActiveCourses,
    learningPaths,
    nextPathLevel,
    orderedCourses,
    pathProgress,
    primaryResumeId,
    professionalCourses,
    selectedPath,
    week: lastSevenDays(activityDays),
  };
};
