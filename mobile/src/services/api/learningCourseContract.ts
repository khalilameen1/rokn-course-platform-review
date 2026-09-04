import {
  firstBoolean,
  isApiRecord,
  resourceList,
  valueAsBoolean,
} from './common';
import {
  displayImageUrl,
  displayText,
  nonNegativeNumberOr,
  stableCourseContentId,
} from './courseFields';
import {courseCategory} from './courseContractShared';
import type {CourseDto, CourseProgress} from './courseContractTypes';

const LEARNING_ACCESS_TYPES = new Set([
  'paid',
  'scholarship',
  'course_code',
  'free',
]);

const invalidLearningCourse = (item: CourseDto): boolean => {
  const courseId = String(item.course_id ?? '').trim();
  const progress = Number(item.progress_percentage);
  const completed = Number(item.completed_sections);
  const total = Number(item.total_sections);
  const resume = isApiRecord(item.resume) ? item.resume : null;
  const resumeAvailable = resume ? firstBoolean(resume.available) : undefined;
  const resumePosition = Number(resume?.position_seconds);
  const resumeDuration =
    resume?.duration_seconds === null || resume?.duration_seconds === undefined
      ? null
      : Number(resume.duration_seconds);
  const next = item.next_section;
  const learningStarted = firstBoolean(item.learning_started);

  return (
    !/^\d+$/.test(courseId) ||
    !displayText(item.title) ||
    !Number.isFinite(progress) ||
    progress < 0 ||
    progress > 100 ||
    !Number.isSafeInteger(completed) ||
    completed < 0 ||
    !Number.isSafeInteger(total) ||
    total < 1 ||
    completed > total ||
    learningStarted === undefined ||
    !LEARNING_ACCESS_TYPES.has(
      String(item.access_type || '')
        .trim()
        .toLowerCase(),
    ) ||
    firstBoolean(item.chat_available) === undefined ||
    firstBoolean(item.certificate_available) === undefined ||
    !resume ||
    resumeAvailable === undefined ||
    (resumeAvailable &&
      (!stableCourseContentId(resume.lesson_id) ||
        !String(resume.lesson_title || '').trim() ||
        !Number.isSafeInteger(resumePosition) ||
        resumePosition < 0 ||
        (resumeDuration !== null &&
          (!Number.isSafeInteger(resumeDuration) || resumeDuration < 1)))) ||
    (next !== null &&
      (!isApiRecord(next) ||
        !stableCourseContentId(next.id) ||
        !String(next.title || '').trim() ||
        !['lesson', 'project'].includes(String(next.type || '').toLowerCase())))
  );
};

const mapLearningCourse = (item: CourseDto): CourseProgress => {
  const resume = item.resume || {};
  const nextSection = item.next_section || {};
  return {
    id: String(item.course_id),
    title: displayText(item.title) || 'كورس ركن',
    started: valueAsBoolean(item.learning_started),
    imageUrl: displayImageUrl(item.image),
    progress: Math.min(100, nonNegativeNumberOr(item.progress_percentage, 0)),
    completedSections: nonNegativeNumberOr(item.completed_sections, 0),
    totalSections: nonNegativeNumberOr(item.total_sections, 0),
    category: courseCategory(item),
    accessType: item.access_type ? String(item.access_type) : undefined,
    chatAvailable: valueAsBoolean(item.chat_available),
    certificateAvailable:
      item.certificate_available === undefined
        ? false
        : valueAsBoolean(item.certificate_available),
    lastLessonId:
      !valueAsBoolean(resume.available) || resume.lesson_id == null
        ? undefined
        : String(resume.lesson_id),
    lastLessonTitle:
      valueAsBoolean(resume.available) && resume.lesson_title
        ? String(resume.lesson_title)
        : undefined,
    resumePositionSeconds: valueAsBoolean(resume.available)
      ? Number(resume.position_seconds)
      : undefined,
    resumeDurationSeconds:
      valueAsBoolean(resume.available) && resume.duration_seconds != null
        ? Number(resume.duration_seconds)
        : undefined,
    nextSectionId:
      item.next_section === null || nextSection.id == null
        ? undefined
        : String(nextSection.id),
    nextSectionTitle:
      item.next_section !== null && nextSection.title
        ? String(nextSection.title)
        : undefined,
    nextSectionType:
      item.next_section !== null && nextSection.type
        ? String(nextSection.type).toLowerCase()
        : undefined,
    lastWatchedAt:
      String(resume.watched_at || item.last_activity_at || '') || undefined,
  };
};

export const mapLearningCoursesPayload = (
  rawData: unknown,
): CourseProgress[] => {
  if (!isApiRecord(rawData) || !Array.isArray(rawData.items)) {
    throw new Error('LEARNING_COURSES_CONTRACT_INVALID');
  }
  const items = resourceList<CourseDto>(rawData.items);
  if (items.some(item => !isApiRecord(item) || invalidLearningCourse(item))) {
    throw new Error('LEARNING_COURSES_CONTRACT_INVALID');
  }
  return items.map(mapLearningCourse);
};
