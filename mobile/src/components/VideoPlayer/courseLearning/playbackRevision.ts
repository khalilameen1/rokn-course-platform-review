export type CourseRevisionChange = {
  courseId: string;
  sourceLessonId?: string;
  currentLessonId?: string;
  currentSectionId?: string;
};

const listeners = new Set<(change: CourseRevisionChange) => void>();

export const subscribeCourseRevisionChanges = (
  listener: (change: CourseRevisionChange) => void,
) => {
  listeners.add(listener);
  return () => listeners.delete(listener);
};

/** Notify screens only after the server rejects evidence from an old revision. */
export const publishCourseRevisionChange = (
  response: unknown,
  sourceLessonId?: string,
) => {
  const candidate = response as {
    data?: Record<string, unknown>;
    response?: {data?: Record<string, unknown>};
  };
  const envelope = candidate?.response?.data || candidate?.data;
  const raw = (envelope?.data || envelope) as
    | Record<string, unknown>
    | undefined;
  const revisionChanged =
    raw?.course_revision_changed === true ||
    String(envelope?.code || '').toLowerCase() === 'course_revision_changed';
  if (!revisionChanged || !raw) return;
  const courseId = String(raw.course_id || '').trim();
  if (!courseId) return;

  const change: CourseRevisionChange = {
    courseId,
    ...(sourceLessonId ? {sourceLessonId} : {}),
    ...(raw.current_lesson_id !== null && raw.current_lesson_id !== undefined
      ? {currentLessonId: String(raw.current_lesson_id)}
      : {}),
    ...(raw.current_section_id !== null && raw.current_section_id !== undefined
      ? {currentSectionId: String(raw.current_section_id)}
      : {}),
  };
  listeners.forEach(listener => {
    try {
      listener(change);
    } catch {
      // An observer cannot turn committed evidence into a retry.
    }
  });
};
