import {
  DEFAULT_READ_RECOVERY_BUDGET_MS,
  publicRequest,
  type RoknRequestConfig,
} from '../../constants/api';

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

/** Both course presentation and playback read a fresh, stable publication. */
export const requestCourseDetails = async (
  id: string,
  options: {signal?: AbortSignal; optionalAuthorization?: boolean} = {},
) => {
  const config: RoknRequestConfig = {
    ...options,
    roknNetworkRetryDeadlineAt: Date.now() + DEFAULT_READ_RECOVERY_BUDGET_MS,
  };
  try {
    return await publicRequest.get(`courses/${id}/details`, config);
  } catch (error) {
    if (!isCourseRevisionChangedError(error) || options.signal?.aborted)
      throw error;
    // Re-read once within the original transport budget, never return mixed
    // revisions or fall back to cached media/entitlements in the player.
    return publicRequest.get(`courses/${id}/details`, config);
  }
};
