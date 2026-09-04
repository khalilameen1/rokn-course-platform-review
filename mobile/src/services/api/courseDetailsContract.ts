import {courseDurationMinutes} from '../../utils/courseDetailsPresentation';
import {
  firstBoolean,
  isApiRecord,
  resourceList,
  valueAsBoolean,
} from './common';
import {
  displayImageUrl,
  displayText,
  stableCourseContentId,
} from './courseFields';
import type {
  CourseAccessPlan,
  CourseAccessPlanDto,
  CourseDetails,
  CourseDto,
  CourseModulePreview,
  CourseSectionDto,
} from './courseContractTypes';

const LEARNING_ACCESS_TYPES = new Set([
  'paid',
  'scholarship',
  'course_code',
  'free',
]);

const hasLearningAccess = (course: CourseDto): boolean =>
  LEARNING_ACCESS_TYPES.has(
    String(course.access_type || 'none')
      .trim()
      .toLowerCase(),
  );

const courseUserRating = (value: unknown): number | null => {
  const raw = isApiRecord(value) ? value.rating : value;
  const rating = Number(raw);
  return rating >= 1 && rating <= 5 ? rating : null;
};

const hasValidCourseModuleContract = (rawModules: unknown): boolean => {
  if (!Array.isArray(rawModules) || !rawModules.length) return false;
  const moduleIds = new Set<string>();
  const sectionIds = new Set<string>();
  const contentIds = new Set<string>();
  return rawModules.every(rawModule => {
    if (!isApiRecord(rawModule)) return false;
    const moduleId = stableCourseContentId(rawModule.id);
    if (
      !moduleId ||
      moduleIds.has(moduleId) ||
      !String(rawModule.title || '').trim()
    ) {
      return false;
    }
    moduleIds.add(moduleId);
    if (!Array.isArray(rawModule.sections) || !rawModule.sections.length) {
      return false;
    }
    return rawModule.sections.every(rawSection => {
      if (!isApiRecord(rawSection)) return false;
      const type = String(rawSection.type || '').toLowerCase();
      if (!['lesson', 'project'].includes(type)) return false;
      const sectionId = stableCourseContentId(rawSection.id);
      if (
        !sectionId ||
        sectionIds.has(sectionId) ||
        !String(rawSection.title || '').trim()
      ) {
        return false;
      }
      sectionIds.add(sectionId);
      const content = isApiRecord(rawSection.content) ? rawSection.content : {};
      const contentId = stableCourseContentId(
        content.id || rawSection.content_id,
      );
      const contentKey = `${type}:${contentId}`;
      if (!contentId || contentIds.has(contentKey)) return false;
      contentIds.add(contentKey);
      return true;
    });
  });
};

const hasValidAccessPlans = (plans: unknown): boolean => {
  const planCodes = new Set<string>();
  return (
    Array.isArray(plans) &&
    plans.length === 3 &&
    plans.every(plan => {
      if (!isApiRecord(plan)) return false;
      const code = String(plan.code || '')
        .trim()
        .toLowerCase();
      const price = Number(plan.price_coins);
      const minimumPaid = Number(plan.minimum_paid_coins);
      const feedback = String(plan.project_feedback_level || '');
      const booleans = [
        plan.chat_enabled,
        plan.project_report_enabled,
        plan.project_thread_reply_enabled,
        plan.project_output_enabled,
        plan.certificate_enabled,
      ];
      if (
        !['basic', 'guided', 'mentor'].includes(code) ||
        planCodes.has(code) ||
        !String(plan.name || '').trim() ||
        !Number.isSafeInteger(price) ||
        price < 0 ||
        !Number.isSafeInteger(minimumPaid) ||
        minimumPaid < 0 ||
        minimumPaid > price ||
        !['pass_only', 'report', 'enhanced'].includes(feedback) ||
        booleans.some(value => firstBoolean(value) === undefined)
      ) {
        return false;
      }
      planCodes.add(code);
      return true;
    })
  );
};

const assertCourseDetailsContract = (course: unknown): CourseDto => {
  if (!isApiRecord(course)) {
    throw new Error('API_CONTRACT_INVALID_COURSE_DETAILS');
  }
  const id = stableCourseContentId(course.id);
  const title = displayText(course.title);
  const comingSoon = firstBoolean(course.is_coming_soon);
  const ratingsCount = Number(course.ratings_count);
  const ratingAverageRaw = course.average_rating;
  const ratingAverage =
    ratingAverageRaw === null || ratingAverageRaw === undefined
      ? null
      : Number(ratingAverageRaw);
  const studentsCount = Number(
    isApiRecord(course.metadata) ? course.metadata.students_count : NaN,
  );
  const publishedRevision = Number(course.published_revision);
  if (
    !id ||
    !title ||
    comingSoon === undefined ||
    !Number.isSafeInteger(ratingsCount) ||
    ratingsCount < 0 ||
    (ratingsCount === 0 && ratingAverage !== null) ||
    (ratingsCount > 0 &&
      (ratingAverage === null ||
        !Number.isFinite(ratingAverage) ||
        ratingAverage < 1 ||
        ratingAverage > 5)) ||
    !Number.isSafeInteger(studentsCount) ||
    studentsCount < 0 ||
    (!comingSoon &&
      (!Number.isSafeInteger(publishedRevision) || publishedRevision < 1))
  ) {
    throw new Error('API_CONTRACT_INVALID_COURSE_DETAILS');
  }

  if (
    !comingSoon &&
    (!hasValidAccessPlans(course.access_plans) ||
      courseDurationMinutes(course) === null ||
      Number(courseDurationMinutes(course)) <= 0 ||
      !hasValidCourseModuleContract(course.modules))
  ) {
    throw new Error('API_CONTRACT_INVALID_COURSE_DETAILS');
  }
  return course as CourseDto;
};

const courseModules = (course: CourseDto): CourseModulePreview[] => {
  if (!hasValidCourseModuleContract(course.modules)) {
    throw new Error('API_CONTRACT_INVALID_COURSE_CONTENT');
  }
  let reelNumber = 0;
  return (Array.isArray(course.modules) ? course.modules : []).flatMap(
    module => {
      const moduleId = String(module.id ?? '').trim();
      if (!moduleId) return [];
      const sections = Array.isArray(module.sections) ? module.sections : [];
      const sectionType = (section: CourseSectionDto) =>
        String(section.type || '').toLowerCase();
      const lessons = sections.filter(
        section => sectionType(section) === 'lesson',
      );
      const isPreviewSection = (section: CourseSectionDto) =>
        valueAsBoolean(section.is_preview);
      const items = sections.flatMap(section => {
        const rawType = sectionType(section);
        const content = section.content || {};
        const type =
          rawType === 'project'
            ? 'project'
            : rawType === 'lesson'
            ? 'reel'
            : 'other';
        if (type === 'other') return [];
        const sectionId = String(section.id ?? '').trim();
        if (!sectionId) return [];
        const currentReelNumber = type === 'reel' ? ++reelNumber : undefined;
        return [
          {
            id: sectionId,
            title: String(section.title),
            type,
            isPreview: type === 'reel' && isPreviewSection(section),
            reelNumber: currentReelNumber,
            reelId:
              type === 'reel'
                ? String(content.id || section.content_id)
                : undefined,
          } as CourseModulePreview['items'][number],
        ];
      });
      return [
        {
          id: moduleId,
          title: String(module.title),
          reelCount: lessons.length,
          projectCount: sections.filter(
            section => sectionType(section) === 'project',
          ).length,
          previewReelCount: lessons.filter(isPreviewSection).length,
          items,
        },
      ];
    },
  );
};

const mapAccessPlans = (course: CourseDto): CourseAccessPlan[] => {
  const planOrder: Record<string, number> = {basic: 0, guided: 1, mentor: 2};
  const seenPlanCodes = new Set<string>();
  return resourceList<CourseAccessPlanDto>(course.access_plans)
    .filter(plan => {
      const price = Number(plan.price_coins);
      return (
        String(plan.code || '').trim().length > 0 &&
        plan.price_coins !== '' &&
        plan.price_coins !== null &&
        plan.price_coins !== undefined &&
        Number.isSafeInteger(price) &&
        price >= 0
      );
    })
    .map(plan => {
      const code = String(plan.code).trim().toLowerCase();
      return {
        code,
        name: String(plan.name).trim(),
        priceCoins: Number(plan.price_coins),
        minimumPaidCoins: Math.max(0, Number(plan.minimum_paid_coins) || 0),
        chatEnabled: valueAsBoolean(plan.chat_enabled),
        chatMessageLimit: Math.max(0, Number(plan.chat_message_limit) || 0),
        projectFeedbackLevel: String(
          ['pass_only', 'report', 'enhanced'].includes(
            String(plan.project_feedback_level),
          )
            ? plan.project_feedback_level
            : 'pass_only',
        ) as CourseAccessPlan['projectFeedbackLevel'],
        projectReportEnabled: valueAsBoolean(plan.project_report_enabled),
        projectFollowupEnabled: valueAsBoolean(
          plan.project_thread_reply_enabled,
        ),
        projectFollowupMessageLimit: Math.max(
          0,
          Number(plan.project_message_limit) || 0,
        ),
        projectFollowupTokenBudget: Math.max(
          0,
          Number(plan.project_token_budget) || 0,
        ),
        projectOutputEnabled: valueAsBoolean(plan.project_output_enabled),
        certificateEnabled: valueAsBoolean(plan.certificate_enabled),
      };
    })
    .filter(plan => {
      if (!plan.code || seenPlanCodes.has(plan.code)) return false;
      seenPlanCodes.add(plan.code);
      return true;
    })
    .sort(
      (left, right) =>
        (planOrder[left.code] ?? 100) - (planOrder[right.code] ?? 100) ||
        left.priceCoins - right.priceCoins,
    );
};

export const mapCourseDetailsPayload = (value: unknown): CourseDetails => {
  const course = assertCourseDetailsContract(value);
  const modules = valueAsBoolean(course.is_coming_soon)
    ? []
    : courseModules(course);
  const previewReelCount = modules.reduce(
    (total, module) => total + module.previewReelCount,
    0,
  );
  const teacher = Array.isArray(course.teachers) ? course.teachers[0] : null;
  const accessPlans = mapAccessPlans(course);

  return {
    id: String(course.id ?? '').trim(),
    publishedRevision: Math.max(0, Number(course.published_revision) || 0),
    title: displayText(course.title) || 'كورس ركن',
    description: displayText(course.description),
    imageUrl: displayImageUrl(course.image),
    price:
      accessPlans.length > 0
        ? Math.min(...accessPlans.map(plan => plan.priceCoins))
        : null,
    instructor: displayText(teacher?.name) || 'فريق ركن',
    instructorBio: displayText(teacher?.bio) || displayText(teacher?.job_title),
    instructorImage: displayImageUrl(teacher?.image),
    owned: hasLearningAccess(course),
    started:
      hasLearningAccess(course) && valueAsBoolean(course.learning_started),
    modules,
    reelCount: modules.reduce((sum, module) => sum + module.reelCount, 0),
    projectCount: modules.reduce((sum, module) => sum + module.projectCount, 0),
    // The authored module graph is also what the player receives. Driving
    // the preview CTA from a second metadata counter could advertise a free
    // sample while the graph contained no playable preview (or hide a real
    // one after a stale counter), ending in an empty player.
    previewReelCount,
    ratingAverage:
      Number(course.average_rating) > 0 ? Number(course.average_rating) : null,
    ratingsCount: Math.max(0, Number(course.ratings_count ?? 0) || 0),
    userRating: courseUserRating(course.user_rating),
    ratingVersion: Math.max(0, Number(course.rating_eligibility?.version) || 0),
    ratingEligible: valueAsBoolean(course.rating_eligibility?.can_rate),
    ratingEligibilityReason: String(
      course.rating_eligibility?.reason || 'course_access_required',
    ),
    studentsCount: Math.max(0, Number(course.metadata?.students_count) || 0),
    durationMinutes: courseDurationMinutes(course),
    accessPlans,
  };
};
