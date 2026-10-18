import {
  DEFAULT_READ_RECOVERY_BUDGET_MS,
  publicRequest,
  type RoknRequestConfig,
} from '../../constants/api';
import {isCourseRevisionChangedError} from './courseReadFailures';

export {
  courseReadFailureStatus,
  isCourseRevisionChangedError,
} from './courseReadFailures';

/** Return the alias-resolution response untouched to the attachment owner. */
export const requestCourseAttachmentRenewal = (
  courseId: string,
  attachmentId: string,
) =>
  publicRequest.get(`courses/${courseId}/pdfs/${attachmentId}`, {
    timeout: 10_000,
    roknNetworkRetryCount: Number.MAX_SAFE_INTEGER,
  } as RoknRequestConfig);

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
