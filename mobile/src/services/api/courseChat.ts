import {publicRequest, type RoknRequestConfig} from '../../constants/api';

/** Course assistant requests. Mapping and ownership stay with the course flow. */
export const getCourseChatMessages = (params: {
  course_id: string;
  lesson_id?: string;
  per_page: number;
}) => publicRequest.get('course-chat/messages', {params});

export const getCourseChatTurn = (
  clientRequestId: string,
  config: RoknRequestConfig,
) =>
  publicRequest.get(
    `course-chat/turns/${encodeURIComponent(clientRequestId)}`,
    config,
  );

export const cancelCourseChatTurn = (clientRequestId: string, timeout: number) =>
  publicRequest.delete(
    `course-chat/turns/${encodeURIComponent(clientRequestId)}`,
    {timeout},
  );

export const postCourseChatMessage = (
  courseId: string,
  body: Record<string, unknown>,
  timeout: number,
) => publicRequest.post(`courses/${courseId}/chat`, body, {timeout});

export const uploadCourseChatAttachment = (
  courseId: string,
  body: FormData,
  config: RoknRequestConfig,
) => publicRequest.post(`courses/${courseId}/chat/attachments`, body, config);

/** A fresh, short-lived download link for an AI input attachment. */
export const getAiInputAttachment = (attachmentId: string) =>
  publicRequest.get(`ai-input-attachments/${encodeURIComponent(attachmentId)}`);
