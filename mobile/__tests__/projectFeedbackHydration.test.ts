const mockGet = jest.fn();
const mockPost = jest.fn();
const mockOpenExternal = jest.fn();

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    delete: jest.fn(),
  },
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: (...args: unknown[]) => mockOpenExternal(...args),
}));

jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    scope: 'user:17',
    epoch: 4,
  })),
  assertAccountSessionBoundary: jest.fn(),
}));

import {mapCourseProject} from '../src/components/VideoPlayer/courseLearning/projectMapping';
import {
  loadProjectFeedbackThread,
  loadProjectResolution,
  sendProjectFeedbackMessage,
  uploadProjectFeedbackAttachment,
  openProjectInputAttachment,
} from '../src/components/VideoPlayer/courseLearning/projectRemote';

const THREAD_ID = '11111111-1111-4111-8111-111111111111';
const axiosResponse = (data: unknown) => ({
  status: 200,
  headers: {},
  data: {status: 200, success: true, message: 'تم', data},
});

const feedbackThreadPayload = (canRetry: boolean) => ({
  id: THREAD_ID,
  feedback_level: 'enhanced',
  can_reply: true,
  status: 'ready',
  remaining_messages: 3,
  attachments_enabled: true,
  attachment_max_files: 4,
  messages: [
    {
      id: 'message-7',
      client_request_id: 'request-7',
      role: 'user',
      status: 'failed',
      error_code: 'provider_unavailable',
      can_retry: canRetry,
      text: 'هذه محاولة المشروع',
      created_at: '2026-09-05T10:00:00Z',
      attachments: [
        {
          id: 'attachment-9',
          name: 'project.pdf',
          mime_type: 'application/pdf',
          size_bytes: 4096,
          download_url: 'https://cdn.rokn.test/project.pdf?signature=server',
          download_url_expires_at: '2026-09-05T10:10:00Z',
        },
      ],
    },
  ],
});

const submissionPayload = (canRetry: boolean) => ({
  id: 'submission-3',
  submission_status: 'passed',
  can_submit: false,
  can_continue: true,
  feedback_level: 'enhanced',
  report_enabled: true,
  report_status: 'ready',
  reply_enabled: true,
  feedback: 'اجتزت المشروع',
  can_retry_report: false,
  report_retry_endpoint: null,
  feedback_thread: feedbackThreadPayload(canRetry),
});

const initialThreadFromCourseMap = (canRetry: boolean) =>
  mapCourseProject(
    {
      id: 'section-2',
      content_id: '7',
      title: 'مشروع العبور',
      order: 2,
      is_locked: false,
      content: {
        requirements_text: 'ارفع المشروع',
        submission_text_enabled: true,
        submission_files_enabled: true,
        submission_max_files: 4,
        project_feedback: {
          level: 'enhanced',
          output_enabled: true,
          report_enabled: true,
          reply_enabled: true,
        },
        latest_submission: submissionPayload(canRetry),
      },
    },
    'module-1',
  )?.feedbackThread;

describe('project feedback hydration parity', () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    mockOpenExternal.mockReset();
  });

  it.each([true, false])(
    'preserves server retry capability and attachment metadata when can_retry=%s',
    async canRetry => {
      const threadPayload = feedbackThreadPayload(canRetry);
      const projectPayload = {latest_submission: submissionPayload(canRetry)};
      mockGet
        .mockResolvedValueOnce(axiosResponse(threadPayload))
        .mockResolvedValueOnce(axiosResponse(projectPayload));

      const initial = initialThreadFromCourseMap(canRetry);
      const hydrated = await loadProjectFeedbackThread('7', THREAD_ID);
      const resolution = await loadProjectResolution('7');

      expect(hydrated).toEqual(initial);
      expect(resolution.feedbackThread).toEqual(initial);
      expect(resolution.reviewFeedback).toBeUndefined();
      expect(initial?.messages[0]).toMatchObject({
        canRetry,
        errorCode: 'provider_unavailable',
        attachments: [
          {
            uploadId: 'attachment-9',
            serverId: 'attachment-9',
            type: 'application/pdf',
            size: 4096,
            downloadUrl: 'https://cdn.rokn.test/project.pdf?signature=server',
            downloadExpiresAt: '2026-09-05T10:10:00Z',
          },
        ],
      });
      expect(mockGet.mock.calls.map(call => call[0])).toEqual([
        `project-feedback-threads/${THREAD_ID}`,
        'projects/7',
      ]);
    },
  );

  it('uses the same real response envelope for project-based feedback hydration', async () => {
    mockGet.mockResolvedValueOnce(
      axiosResponse({latest_submission: submissionPayload(true)}),
    );
    await expect(loadProjectFeedbackThread('7')).resolves.toEqual(
      initialThreadFromCourseMap(true),
    );
  });

  it('keeps a successful feedback send and attachment upload from becoming false transport failures', async () => {
    mockPost
      .mockResolvedValueOnce(axiosResponse(feedbackThreadPayload(true)))
      .mockResolvedValueOnce(axiosResponse({id: THREAD_ID}));
    await expect(
      sendProjectFeedbackMessage(THREAD_ID, 'هذا سؤالي', THREAD_ID),
    ).resolves.toEqual(initialThreadFromCourseMap(true));
    await expect(
      uploadProjectFeedbackAttachment(THREAD_ID, {
        uploadId: THREAD_ID,
        name: 'مشروعي.png',
        uri: 'file:///project.png',
        type: 'image/png',
      }),
    ).resolves.toBe(THREAD_ID);
    expect(mockPost).toHaveBeenCalledTimes(2);
  });

  it('opens refreshed attachment metadata from the server rather than reporting it unavailable', async () => {
    const url = 'https://cdn.rokn.test/project.png?signature=new';
    mockGet.mockResolvedValueOnce(
      axiosResponse({
        id: THREAD_ID,
        download_url: url,
        download_url_expires_at: '2099-01-01T00:00:00Z',
      }),
    );
    await openProjectInputAttachment({
      projectId: '7',
      file: {
        uploadId: THREAD_ID,
        serverId: THREAD_ID,
        name: 'مشروعي.png',
        uri: '',
        type: 'image/png',
      },
    });
    expect(mockOpenExternal).toHaveBeenCalledWith(
      url,
      undefined,
      `project-input-attachment:${THREAD_ID}`,
    );
  });
});
