import {queryClient} from './queryClient';
import {accountQueryKeys} from './queryKeys';

/**
 * A delivered learning write (section completion, a passed project, a
 * completed lesson) changes the learner's progress on the server. Mark the
 * related account-scoped reads stale instead of each feature refreshing its own
 * copy (ARCHITECTURE.md M1/M2). Inactive queries refetch on their next mount.
 */
export const invalidateLearningProgress = (scope: string, courseId?: string) => {
  const keys = accountQueryKeys(scope);
  const targets = [keys.learningCourses(), keys.learningDashboard()];
  if (courseId) targets.push(keys.courseDetails(String(courseId)) as never);
  targets.forEach(queryKey => {
    void queryClient.invalidateQueries({queryKey}).catch(() => undefined);
  });
};
