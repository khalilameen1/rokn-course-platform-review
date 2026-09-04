type CourseDurationPayload = {
  metadata?: {
    duration_minutes?: unknown;
  };
};

export const courseDurationMinutes = (
  course: CourseDurationPayload | null | undefined,
): number | null => {
  const minutes = Number(course?.metadata?.duration_minutes);

  return Number.isFinite(minutes) && minutes > 0 ? Math.ceil(minutes) : null;
};
