import {publicRequest} from '../../constants/api';

/** Project, review and feedback-thread requests used by the course flow. */
export const getProject = (projectId: string, timeout?: number) =>
  timeout === undefined
    ? publicRequest.get(`projects/${projectId}`)
    : publicRequest.get(`projects/${projectId}`, {timeout});

export const getProjectFeedbackThread = (threadId: string) =>
  publicRequest.get(`project-feedback-threads/${threadId}`);

/** `route` is a validated server endpoint relative to /api/v1. */
export const postProjectSubmissionAction = (route: string, timeout?: number) =>
  timeout === undefined
    ? publicRequest.post(route)
    : publicRequest.post(route, undefined, {timeout});

export const postProjectFeedbackMessage = (
  threadId: string,
  body: Record<string, unknown>,
  clientRequestId: string,
) =>
  publicRequest.post(`project-feedback-threads/${threadId}/messages`, body, {
    headers: {'Idempotency-Key': clientRequestId},
    timeout: 30000,
  });

export const postProjectFeedbackAttachment = (
  threadId: string,
  body: FormData,
) =>
  publicRequest.post(`project-feedback-threads/${threadId}/attachments`, body, {
    headers: {'Content-Type': 'multipart/form-data'},
    timeout: 45000,
  });
