import type {
  CourseLearningData,
  CourseLearningGateState,
  CourseLearningModule,
  CourseProject,
  CourseReel,
} from '../types';

export type CourseModuleStep =
  | {type: 'reel'; reel: CourseReel}
  | {type: 'project'; project: CourseProject};

export type CourseStepLocation = {
  courseStepIndex: number;
  module: CourseLearningModule;
  moduleIndex: number;
  step: CourseModuleStep;
  stepIndex: number;
  steps: CourseModuleStep[];
};

export const orderedModuleSteps = (
  module: CourseLearningModule,
): CourseModuleStep[] => {
  const projects = module.projects || [];
  return [
    ...module.reels.map((reel, fallback) => ({
      order: reel.sectionOrder ?? fallback + 1,
      fallback,
      step: {type: 'reel' as const, reel},
    })),
    ...projects.map((project, index) => ({
      order: project.sectionOrder ?? module.reels.length + index + 1,
      fallback: module.reels.length + index,
      step: {type: 'project' as const, project},
    })),
  ]
    .sort(
      (left, right) =>
        left.order - right.order || left.fallback - right.fallback,
    )
    .map(entry => entry.step);
};

export const moduleStepIsComplete = (step: CourseModuleStep): boolean =>
  step.type === 'reel'
    ? step.reel.isCompleted
    : step.project.status === 'passed';

/** The only canonical traversal order used by feed, resume and progression. */
export const orderedCourseSteps = (
  course: CourseLearningData,
): CourseStepLocation[] => {
  const locations: CourseStepLocation[] = [];
  course.modules.forEach((module, moduleIndex) => {
    const steps = orderedModuleSteps(module);
    steps.forEach((step, stepIndex) => {
      locations.push({
        courseStepIndex: locations.length,
        module,
        moduleIndex,
        step,
        stepIndex,
        steps,
      });
    });
  });
  return locations;
};

const stepMatchesReel = (step: CourseModuleStep, reel: CourseReel) =>
  step.type === 'reel' &&
  step.reel.moduleId === reel.moduleId &&
  step.reel.id === reel.id;

export const courseReelStep = (
  course: CourseLearningData,
  reel: CourseReel,
): CourseStepLocation | undefined =>
  orderedCourseSteps(course).find(location =>
    stepMatchesReel(location.step, reel),
  );

export const courseStepAfterReel = (
  course: CourseLearningData,
  reel: CourseReel,
): CourseStepLocation | undefined => {
  const locations = orderedCourseSteps(course);
  const current = locations.find(location =>
    stepMatchesReel(location.step, reel),
  );
  return current ? locations[current.courseStepIndex + 1] : undefined;
};

const stepLockReason = (step: CourseModuleStep): string =>
  step.type === 'reel'
    ? String(step.reel.lockReason || '')
    : String(step.project.lockReason || '');

const incompleteStepText = (step?: CourseModuleStep): string => {
  if (step?.type === 'project') return 'اجتز مشروع العبور لفتح ما بعده';
  if (step?.type === 'reel') return 'أكمل المقطع السابق للمتابعة';
  return 'أكمل الخطوة السابقة للمتابعة';
};

const stepIsLocked = (step: CourseModuleStep): boolean =>
  step.type === 'reel' ? step.reel.isLocked : step.project.isLocked === true;

export const courseLearningGateState = (
  module: CourseLearningModule,
  steps: CourseModuleStep[],
  stepIndex: number,
): CourseLearningGateState => {
  const step = steps[stepIndex];
  if (!step) return 'locked_project';
  const locked = module.isLocked || stepIsLocked(step);
  if (!locked) {
    return moduleStepIsComplete(step) ? 'completed' : 'available';
  }
  const stepReason = stepLockReason(step);
  const moduleReason = String(module.lockReason || '');
  if (
    stepReason === 'course_purchase_required' ||
    moduleReason === 'course_purchase_required'
  ) {
    return 'locked_purchase';
  }
  return 'locked_project';
};

export const learningGateText = (reason?: string): string => {
  switch (String(reason || '').trim()) {
    case 'locked_purchase':
    case 'course_purchase_required':
      return 'اختر فئة الكورس لفتح هذا المحتوى';
    case 'locked_project':
    case 'module_project_not_passed':
    case 'project_submission_required':
      return 'اجتز مشروع العبور لفتح ما بعده';
    case 'media_not_ready':
      return 'نجهّز هذا المقطع';
    case 'lesson_unavailable':
      return 'المقطع غير متاح الآن';
    default:
      return 'أكمل الخطوة السابقة للمتابعة';
  }
};

/**
 * Gate state drives navigation while the concrete blocking step drives copy.
 * Keeping those separate prevents every non-purchase lock from being labelled
 * as a project, which was especially misleading after an unfinished reel or
 * project.
 */
export const learningGateTextForStep = (
  module: CourseLearningModule,
  steps: CourseModuleStep[],
  stepIndex: number,
): string => {
  const step = steps[stepIndex];
  if (!step) return learningGateText();
  const reason = stepLockReason(step) || String(module.lockReason || '');
  if (reason && reason !== 'previous_section_incomplete') {
    return learningGateText(reason);
  }
  return reason === 'previous_section_incomplete'
    ? incompleteStepText(steps[stepIndex - 1])
    : learningGateText();
};

export const courseLearningProgress = (
  modules: CourseLearningModule[],
): {completed: number; total: number} =>
  modules.reduce(
    (summary, module) => {
      const steps = orderedModuleSteps(module);
      summary.total += steps.length;
      summary.completed += steps.filter(moduleStepIsComplete).length;
      return summary;
    },
    {completed: 0, total: 0},
  );
