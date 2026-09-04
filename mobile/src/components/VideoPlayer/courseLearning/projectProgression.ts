import type {CourseLearningData, CourseReel} from '../types';
import {
  courseReelStep,
  courseStepAfterReel,
  type CourseStepLocation,
} from './sequence';

const PREVIOUS_STEP_LOCK = 'previous_section_incomplete';

const unlockStep = (
  course: CourseLearningData,
  location?: CourseStepLocation,
): CourseLearningData => {
  if (!location) return course;
  const lockReason =
    location.step.type === 'reel'
      ? location.step.reel.lockReason
      : location.step.project.lockReason;
  // Completing a reel resolves only the exact progression gate that the
  // server attached to its successor. Media, purchase and project-review
  // locks remain server-owned and must arrive through a refreshed contract.
  if (lockReason !== PREVIOUS_STEP_LOCK) return course;
  return {
    ...course,
    modules: course.modules.map((module, moduleIndex) => {
      if (moduleIndex !== location.moduleIndex) return module;
      const step = location.step;
      return {
        ...module,
        isLocked: false,
        lockReason: undefined,
        reels: module.reels.map(reel =>
          step.type === 'reel' && step.reel.id === reel.id
            ? {...reel, isLocked: false, lockReason: undefined}
            : reel,
        ),
        projects: (module.projects || []).map(project =>
          step.type === 'project' && step.project.id === project.id
            ? {...project, isLocked: false, lockReason: undefined}
            : project,
        ),
      };
    }),
  };
};

/** Apply the optimistic projection of one acknowledged reel completion. */
export const advanceAfterReel = (
  course: CourseLearningData,
  reel: CourseReel,
  unlockSuccessor = true,
): CourseLearningData => {
  if (!courseReelStep(course, reel)) return course;
  const completed: CourseLearningData = {
    ...course,
    modules: course.modules.map(module =>
      module.id === reel.moduleId
        ? {
            ...module,
            reels: module.reels.map(candidate =>
              candidate.id === reel.id
                ? {...candidate, isCompleted: true}
                : candidate,
            ),
          }
        : module,
    ),
  };
  return unlockSuccessor
    ? unlockStep(completed, courseStepAfterReel(completed, reel))
    : completed;
};
