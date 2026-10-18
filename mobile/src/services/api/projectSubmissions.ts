import {publicRequest, type RoknRequestConfig} from '../../constants/api';

/**
 * Project submission requests. Delivery, retry and the durable record live in
 * the submission outbox adapter (services/projectSubmissionStore).
 */
export const getProjectSubmission = (publicId: string, timeout: number) =>
  publicRequest.get(`project-submissions/${publicId}`, {timeout});

export const lookupProjectSubmission = (
  projectId: string,
  clientSubmissionId: string,
  timeout: number,
) =>
  publicRequest.get(`projects/${projectId}/submissions/lookup`, {
    params: {client_submission_id: clientSubmissionId},
    timeout,
  });

export const postProjectSubmission = (
  projectId: string,
  form: FormData,
  config: RoknRequestConfig,
) => publicRequest.post(`projects/${projectId}/submissions`, form, config);
