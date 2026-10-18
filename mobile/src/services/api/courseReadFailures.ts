/** Pure classifiers for course details read failures (no transport). */
export const courseReadFailureStatus = (error: unknown): number => {
  const failure = error as {
    status?: unknown;
    response?: {status?: unknown};
  } | null;
  return Number(failure?.status ?? failure?.response?.status ?? 0) || 0;
};

export const isCourseRevisionChangedError = (error: unknown): boolean => {
  const failure = error as {
    code?: unknown;
    data?: {code?: unknown; data?: {code?: unknown}};
    response?: {data?: {code?: unknown; data?: {code?: unknown}}};
  } | null;
  const code = String(
    failure?.data?.code ??
      failure?.data?.data?.code ??
      failure?.response?.data?.code ??
      failure?.response?.data?.data?.code ??
      failure?.code ??
      '',
  )
    .trim()
    .toLowerCase();
  return (
    courseReadFailureStatus(error) === 409 && code === 'course_revision_changed'
  );
};
