import type {CourseAttachment} from './types';
import {requestCourseAttachmentRenewal} from '../../services/api/courseDetailsRequest';
import {loadCourseLearningData} from './courseLearning/mapping';
import {mapCourseAttachments} from './courseLearning/coursePayload';
import {asRecord} from './courseLearning/shared';
import {remainingServerMilliseconds} from '../../utils/serverClock';

// Refresh access, not a transfer. The caller owns and validates the account generation.
export const isAllowedRemoteUrl = (value: string) => {
  try {
    const parsed = new URL(value) as unknown as {
      hostname: string;
      protocol: string;
      username: string;
      password: string;
    };
    return (
      Boolean(parsed.hostname) &&
      !parsed.username &&
      !parsed.password &&
      (parsed.protocol === 'https:' || (__DEV__ && parsed.protocol === 'http:'))
    );
  } catch {
    return false;
  }
};

const attachmentUrlNeedsRefresh = (attachment: CourseAttachment) => {
  if (!attachment.temporary) return false;
  const remaining = remainingServerMilliseconds(attachment.expiresAt);
  const safeWindow = attachment.platform === 'computer' ? 10 * 60_000 : 90_000;
  return remaining === null || remaining <= safeWindow;
};

const refreshAttachment = async (
  attachment: CourseAttachment,
  assertCurrent: () => void,
) => {
  if (!attachment.courseId) return null;
  assertCurrent();
  const endpoint = /^\/api\/v1\/courses\/([1-9]\d*)\/pdfs\/([1-9]\d*)$/.exec(
    attachment.downloadRefreshEndpoint || '',
  );
  if (
    endpoint &&
    endpoint[1] === attachment.courseId &&
    endpoint[2] === attachment.id
  ) {
    // The endpoint resolves published-revision aliases even when the original
    // card's attachment ID no longer appears in a full course response.
    const response = await requestCourseAttachmentRenewal(
      endpoint[1],
      endpoint[2],
    );
    assertCurrent();
    return (
      mapCourseAttachments(
        [asRecord(asRecord(response.data).data)],
        'mobile',
        attachment.courseId,
      )[0] || null
    );
  }
  const {course} = await loadCourseLearningData(attachment.courseId, {
    reconcilePending: false,
  });
  assertCurrent();
  return course.attachments.find(item => item.id === attachment.id) || null;
};

export const usableAttachment = async (
  attachment: CourseAttachment,
  assertCurrent: () => void,
  forceRefresh = false,
) => {
  assertCurrent();
  if (!forceRefresh && !attachmentUrlNeedsRefresh(attachment)) {
    return attachment;
  }
  const refreshed = await refreshAttachment(attachment, assertCurrent);
  if (!refreshed || !isAllowedRemoteUrl(refreshed.url)) {
    throw new Error('ATTACHMENT_URL_REFRESH_FAILED');
  }
  return refreshed;
};
