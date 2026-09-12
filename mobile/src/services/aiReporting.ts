import {publicRequest} from '../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../constants/helpers';

export type AiReportTarget =
  | {scope: 'course_chat'; course_id: string; client_request_id: string}
  | {scope: 'project_feedback'; thread_id: string; message_id: string};

export const reportAiResponse = async (target: AiReportTarget) => {
  const boundary = await captureAccountSessionBoundary();
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.post('ai-content-reports', {
    ...target, reason: 'رد غير مناسب أو غير دقيق',
  }, {timeout: 15000});
  assertAccountSessionBoundary(boundary);
  if (response.data?.success !== true) throw new Error('AI_REPORT_FAILED');
};
