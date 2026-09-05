import {requestCourseDetails} from '../../../services/api/courseDetailsRequest';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import type {
  CourseLearningData,
  CourseLearningModule,
  CourseReel,
  VideoQuality,
} from '../types';
import {
  courseRecord,
  courseSectionType,
  courseVideoUrl,
  hasValidLearningContract,
  mapCourseAttachments,
  stableCourseContentId,
  type CoursePayloadDto,
  uniqueCourseAttachments,
} from './coursePayload';
import {retryPendingSectionCompletions} from './playback';
import {mapCourseProject} from './projectMapping';
import {
  asArray,
  explicitBoolean,
  valueAsBoolean,
  valueAsString,
} from './shared';

export const mapCoursePayload = (
  rawPayload: unknown,
): CourseLearningData | null => {
  const root = courseRecord(rawPayload);
  const rawCourseValue = root.data;
  if (
    !rawCourseValue ||
    typeof rawCourseValue !== 'object' ||
    Array.isArray(rawCourseValue)
  ) {
    return null;
  }

  const rawCourse = courseRecord(rawCourseValue);
  const courseId = valueAsString(rawCourse.id).trim();
  const rawModules = asArray<CoursePayloadDto>(rawCourse.modules);
  if (!courseId || !hasValidLearningContract(rawModules)) return null;
  const attachments = uniqueCourseAttachments(
    mapCourseAttachments(rawCourse.attachments, 'any', courseId),
  );

  let reelNumber = 0;
  const modules: CourseLearningModule[] = rawModules
    .sort((left, right) => Number(left.order || 0) - Number(right.order || 0))
    .map((module, moduleIndex) => {
      const moduleId = stableCourseContentId(module.id);
      const sections = asArray<CoursePayloadDto>(module.sections).sort(
        (left, right) => Number(left.order || 0) - Number(right.order || 0),
      );
      const reels: CourseReel[] = [];
      const projects = [] as NonNullable<CourseLearningModule['projects']>;

      sections.forEach(section => {
        const type = courseSectionType(section);
        if (type === 'project') {
          const project = mapCourseProject(section, moduleId);
          if (project) projects.push(project);
          return;
        }
        if (type !== 'lesson') return;

        const content = courseRecord(section.content);
        const rawDuration =
          Number(content.duration_seconds) ||
          Number(content.duration_minutes) * 60;
        reelNumber += 1;
        reels.push({
          id: stableCourseContentId(section.content_id),
          lessonId: stableCourseContentId(section.content_id),
          sectionId: stableCourseContentId(section.id),
          moduleId,
          title: valueAsString(section.title, `المقطع ${reelNumber}`),
          caption: valueAsString(content.description),
          videoUrl: courseVideoUrl(content),
          thumbnailUrl: valueAsString(content.thumbnail_url) || undefined,
          durationSeconds:
            Number.isFinite(rawDuration) && rawDuration > 0
              ? rawDuration
              : undefined,
          availableQualities: ['auto'] as VideoQuality[],
          isPreview: valueAsBoolean(section.is_preview),
          isLocked: valueAsBoolean(section.is_locked),
          lockReason: valueAsString(section.lock_reason).trim() || undefined,
          isCompleted: valueAsBoolean(section.is_completed),
          reelNumber,
          sectionOrder: Number.isFinite(Number(section.order))
            ? Number(section.order)
            : undefined,
        });
      });

      const firstSection = sections[0];
      const firstSectionLocked = valueAsBoolean(firstSection?.is_locked);
      return {
        id: moduleId,
        title: valueAsString(module.title, `الوحدة ${moduleIndex + 1}`),
        order: Number(module.order || moduleIndex + 1),
        isLocked: valueAsBoolean(module.is_locked) || firstSectionLocked,
        lockReason:
          valueAsString(module.lock_reason).trim() ||
          (firstSectionLocked
            ? valueAsString(firstSection?.lock_reason).trim() || undefined
            : undefined),
        reels,
        projects,
      };
    })
    .filter(module => module.reels.length || Boolean(module.projects?.length));

  if (!modules.some(module => module.reels.length)) return null;

  const hasAttachmentPrompt =
    rawCourse.attachment_prompt !== null &&
    typeof rawCourse.attachment_prompt === 'object' &&
    !Array.isArray(rawCourse.attachment_prompt);
  const attachmentPrompt = courseRecord(rawCourse.attachment_prompt);
  const certificateAvailable = explicitBoolean(rawCourse.certificate_available);
  const certificateIncluded = explicitBoolean(rawCourse.certificate_included);

  return {
    id: courseId,
    title: valueAsString(rawCourse.title, 'الكورس'),
    image: valueAsString(rawCourse.image) || undefined,
    totalReels: reelNumber,
    attachments,
    modules,
    accessType:
      valueAsString(rawCourse.access_type).trim().toLowerCase() || undefined,
    chatAvailable: explicitBoolean(rawCourse.chat_available),
    chatAttachmentsEnabled: explicitBoolean(rawCourse.chat_attachments_enabled),
    chatAttachmentMaxFiles: Math.min(
      5,
      Math.max(0, Number(rawCourse.chat_attachment_max_files ?? 0) || 0),
    ),
    certificateAvailable,
    certificateIncluded:
      certificateIncluded === undefined
        ? certificateAvailable
        : certificateIncluded,
    attachmentPrompt: hasAttachmentPrompt
      ? {
          enabled: valueAsBoolean(attachmentPrompt.enabled),
          atSeconds: Math.max(0, Number(attachmentPrompt.at_seconds) || 0),
          title: valueAsString(
            attachmentPrompt.title,
            'مرفقات تساعدك في التطبيق',
          ),
          body: valueAsString(
            attachmentPrompt.body,
            'يحتوي الكورس على ملفات تساعدك في التطبيق',
          ),
          buttonText: valueAsString(
            attachmentPrompt.button_text,
            'عرض المرفقات',
          ),
          frequency: 'once_per_course',
        }
      : undefined,
  };
};

export const loadCourseLearningData = async (
  courseId?: string | number,
  options: {reconcilePending?: boolean; signal?: AbortSignal} = {},
): Promise<{course: CourseLearningData}> => {
  const requestedCourseId = String(courseId ?? '').trim();
  if (!requestedCourseId) throw new Error('COURSE_ID_MISSING');
  if (!/^\d+$/.test(requestedCourseId)) {
    throw new Error('COURSE_ID_INVALID');
  }

  if (options.reconcilePending !== false) {
    void retryPendingSectionCompletions().catch(() => undefined);
  }

  try {
    const response = await requestCourseDetails(requestedCourseId, {
      signal: options.signal,
    });
    const course = mapCoursePayload(response.data);
    if (!course) throw new Error('COURSE_CONTENT_UNPUBLISHED');
    return {course};
  } catch (caught: unknown) {
    const error = new Error('COURSE_LEARNING_UNAVAILABLE') as Error & {
      cause?: unknown;
      learnerMessage?: string;
    };
    error.cause = caught;
    error.learnerMessage = learnerErrorMessage(
      caught,
      'تعذّر تحميل الكورس الآن',
    );
    throw error;
  }
};
