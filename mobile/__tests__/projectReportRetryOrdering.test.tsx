import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGet = jest.fn();
const mockPost = jest.fn();
let mockEpoch = 1;
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    scope: 'user-a',
    epoch: mockEpoch,
  })),
  assertAccountSessionBoundary: (boundary: {epoch: number}) => {
    if (boundary.epoch !== mockEpoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () =>
  jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/projectRemote',
  ),
);

import {useProjectResolution} from '../src/components/VideoPlayer/projectTransition/useProjectResolution';
import {
  loadProjectResolution,
  retryProjectReport,
} from '../src/components/VideoPlayer/courseLearning/projectRemote';
import {mapCourseProject} from '../src/components/VideoPlayer/courseLearning/projectMapping';
import type {
  CourseProject,
  ProjectReportStatus,
} from '../src/components/VideoPlayer/types';

const id = '11111111-1111-4111-8111-111111111111';
const endpoint = `/api/v1/project-submissions/${id}/report/retry`;
const submission = (status: ProjectReportStatus = 'failed', retry = true) => ({
  id,
  submission_status: 'passed',
  can_submit: false,
  can_continue: true,
  feedback_level: 'report',
  report_enabled: true,
  report_status: status,
  reply_enabled: false,
  can_retry_report: status === 'failed' && retry,
  report_retry_endpoint: status === 'failed' && retry ? endpoint : null,
});
const response = (data: unknown) => ({
  status: 202,
  data: {status: 202, success: true, data},
});
const project: CourseProject = {
  id: '7',
  sectionId: '9',
  moduleId: '3',
  title: 'مشروع',
  requirements: 'صمم',
  status: 'passed',
  isGraduationProject: false,
  canSubmit: false,
  canContinue: true,
  feedbackLevel: 'report',
  reportEnabled: true,
  replyEnabled: false,
  reportStatus: 'failed',
  canRetryReport: true,
  reportRetryEndpoint: endpoint,
};
const courseProjectFromSummary = (
  status: ProjectReportStatus = 'failed',
  retry = true,
) =>
  mapCourseProject(
    {
      id: 9,
      content_id: 7,
      title: 'مشروع',
      content: {
        requirements_text: 'صمم',
        latest_submission: {
          ...submission(status, retry),
          feedback_thread: {
            id: '33333333-3333-4333-8333-333333333333',
            feedback_level: 'report',
            can_reply: false,
            status,
            remaining_messages: 0,
            messages: [],
          },
        },
      },
    },
    '3',
  )!;
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const mount = (input = project) => {
  let current!: ReturnType<typeof useProjectResolution>;
  function Harness({value}: {value: CourseProject}) {
    current = useProjectResolution({
      active: true,
      appIsActive: true,
      project: value,
    });
    return null;
  }
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => {
    renderer = TestRenderer.create(<Harness value={input} />);
  });
  return {
    get current() {
      return current;
    },
    update: (value: CourseProject) =>
      act(() => renderer.update(<Harness value={value} />)),
    close: () => act(() => renderer.unmount()),
  };
};

describe('report retry acknowledgement ordering', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    mockEpoch += 1;
    mockGet
      .mockReset()
      .mockResolvedValue(response({latest_submission: submission()}));
    mockPost.mockReset();
  });
  afterEach(() => jest.useRealTimers());

  it('waits for the retry ACK before polling and then observes ready without a second POST', async () => {
    const post = deferred<ReturnType<typeof response>>();
    mockPost.mockReturnValue(post.promise);
    const screen = mount();
    try {
      let retry!: Promise<void>;
      await act(async () => {
        retry = screen.current.retryReport();
        await screen.current.retryReport();
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(mockGet).not.toHaveBeenCalled();
      expect(screen.current.reportRetrying).toBe(true);
      expect(screen.current.contract.canContinue).toBe(true);

      mockGet.mockResolvedValue(
        response({latest_submission: submission('queued')}),
      );
      await act(async () => {
        post.resolve(response(submission('queued')));
        await retry;
      });
      expect(screen.current.reportStatus).toBe('queued');
      expect(screen.current.reportRetrying).toBe(false);
      mockGet.mockResolvedValue(
        response({latest_submission: submission('ready')}),
      );
      await act(async () => jest.runOnlyPendingTimers());
      expect(screen.current.reportStatus).toBe('ready');
      expect(screen.current.contract).toMatchObject({
        canContinue: true,
        replyEnabled: false,
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
    } finally {
      screen.close();
    }
  });

  it('applies an already-ready committed POST response without a stale failure read', async () => {
    mockPost.mockResolvedValue(response(submission('ready')));
    const screen = mount();
    try {
      await act(async () => screen.current.retryReport());
      expect(screen.current.reportStatus).toBe('ready');
      expect(screen.current.reportRetryAvailable).toBe(false);
      expect(mockGet).not.toHaveBeenCalled();
    } finally {
      screen.close();
    }
  });

  it.each(['before', 'after'])(
    'keeps the retry result when an equivalent course summary arrives %s its ACK',
    async timing => {
      const post = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValue(post.promise);
      const screen = mount(courseProjectFromSummary());
      let retry!: Promise<void>;
      try {
        await act(async () => {
          retry = screen.current.retryReport();
        });
        // Resolving another accepted project refreshes the whole course map;
        // mapping the same failed summary creates a fresh thread object too.
        if (timing === 'before') screen.update(courseProjectFromSummary());
        const waitingAfterCourseRefresh = screen.current.reportRetrying;
        await act(async () => {
          post.resolve(response(submission('ready')));
          await retry;
        });
        if (timing === 'after') screen.update(courseProjectFromSummary());
        expect({
          waitingAfterCourseRefresh,
          reportStatus: screen.current.reportStatus,
        }).toEqual({waitingAfterCourseRefresh: true, reportStatus: 'ready'});
        expect(mockPost).toHaveBeenCalledTimes(1);
        expect(mockGet).not.toHaveBeenCalled();
      } finally {
        post.resolve(response(submission('ready')));
        await act(async () => retry);
        screen.close();
      }
    },
  );

  it.each(['ready', 'revoked'])(
    'lets a genuinely changed %s course summary supersede the pending retry',
    async change => {
      const post = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValue(post.promise);
      const screen = mount(courseProjectFromSummary());
      let retry!: Promise<void>;
      try {
        await act(async () => {
          retry = screen.current.retryReport();
        });
        const freshStatus = change === 'ready' ? 'ready' : 'failed';
        mockGet.mockResolvedValue(
          response({latest_submission: submission(freshStatus, false)}),
        );
        screen.update(courseProjectFromSummary(freshStatus, false));
        await act(async () => {
          post.resolve(response(submission('queued')));
          await retry;
        });
        expect(screen.current.reportStatus).toBe(freshStatus);
        expect(screen.current.reportRetryAvailable).toBe(false);
        expect(screen.current.reportRetrying).toBe(false);
        expect(mockPost).toHaveBeenCalledTimes(1);
        expect(mockGet).toHaveBeenCalledTimes(change === 'revoked' ? 1 : 0);
      } finally {
        post.resolve(response(submission('queued')));
        await act(async () => retry);
        screen.close();
      }
    },
  );

  it.each(['before', 'after'])(
    'keeps the retry when the accepted review full thread becomes a course summary %s its ACK',
    async timing => {
      const thread = {
        id: '33333333-3333-4333-8333-333333333333',
        feedback_level: 'report',
        can_reply: false,
        status: 'failed',
        remaining_messages: 8,
        messages: [
          {
            id: '44444444-4444-4444-8444-444444444444',
            role: 'assistant',
            status: 'failed',
            text: 'الجزء المحفوظ من التقرير',
          },
        ],
      };
      mockGet.mockResolvedValueOnce(
        response({
          latest_submission: {
            ...submission(),
            can_continue: false,
            feedback_thread: thread,
          },
        }),
      );
      const full = await loadProjectResolution('7');
      const screen = mount({
        ...courseProjectFromSummary(),
        ...full,
        feedbackThread: full.feedbackThread ?? undefined,
      });
      const post = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValue(post.promise);
      const ready = response({
        ...submission('ready'),
        feedback_thread: {
          ...thread,
          status: 'ready',
          messages: [
            {
              ...thread.messages[0],
              status: 'completed',
              text: 'التقرير الكامل',
            },
          ],
        },
      });
      let retry!: Promise<void>;
      try {
        await act(async () => {
          retry = screen.current.retryReport();
        });
        // The parent review owner refreshes media entitlements after publishing
        // the small review result. That map omits transcript/quota by design.
        if (timing === 'before') screen.update(courseProjectFromSummary());
        const waitingAfterCourseRefresh = screen.current.reportRetrying;
        await act(async () => {
          post.resolve(ready);
          await retry;
        });
        if (timing === 'after') screen.update(courseProjectFromSummary());
        expect({
          waitingAfterCourseRefresh,
          reportStatus: screen.current.reportStatus,
          canContinue: screen.current.contract.canContinue,
        }).toEqual({
          waitingAfterCourseRefresh: true,
          reportStatus: 'ready',
          canContinue: true,
        });
        expect(screen.current.feedbackThread).toMatchObject({
          status: 'ready',
          transcriptIncluded: true,
          messages: [{status: 'completed', text: 'التقرير الكامل'}],
        });
        expect(mockPost).toHaveBeenCalledTimes(1);
        expect(mockGet).toHaveBeenCalledTimes(1);
      } finally {
        post.resolve(ready);
        await act(async () => retry);
        screen.close();
      }
    },
  );

  it('keeps a genuine failed retry actionable and leaves passed continuation intact', async () => {
    mockPost.mockRejectedValue(new Error('server unavailable'));
    const screen = mount();
    try {
      await act(async () => screen.current.retryReport());
      expect(screen.current.reportStatus).toBe('failed');
      expect(screen.current.reportRetryAvailable).toBe(true);
      expect(screen.current.reportRetrying).toBe(false);
      expect(screen.current.contract.canContinue).toBe(true);
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(mockGet).toHaveBeenCalledTimes(1);
      await act(async () => jest.advanceTimersByTime(30_000));
      expect(mockGet).toHaveBeenCalledTimes(1);
    } finally {
      screen.close();
    }
  });

  it('reconciles a lost POST acknowledgement as queued without reposting', async () => {
    mockPost.mockRejectedValue(new Error('timeout'));
    mockGet.mockResolvedValue(
      response({latest_submission: submission('queued')}),
    );
    const screen = mount();
    try {
      await act(async () => screen.current.retryReport());
      expect(screen.current.reportStatus).toBe('queued');
      mockGet.mockResolvedValue(
        response({latest_submission: submission('ready')}),
      );
      await act(async () => jest.runOnlyPendingTimers());
      expect(screen.current.reportStatus).toBe('ready');
      expect(mockPost).toHaveBeenCalledTimes(1);
    } finally {
      screen.close();
    }
  });

  it.each(['account change', 'unmount'] as const)(
    'does not start recovery for a former owner after %s',
    async transition => {
      const post = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValue(post.promise);
      const screen = mount();
      let retry!: Promise<void>;
      await act(async () => {
        retry = screen.current.retryReport();
      });
      mockGet.mockClear();
      if (transition === 'unmount') screen.close();
      else mockEpoch += 1;
      try {
        await act(async () => {
          post.reject(new Error('timeout'));
          await retry;
        });
        expect(mockGet).not.toHaveBeenCalled();
      } finally {
        if (transition !== 'unmount') screen.close();
      }
    },
  );

  it('ignores an older retry ACK after the project selection changes', async () => {
    const post = deferred<ReturnType<typeof response>>();
    mockPost.mockReturnValue(post.promise);
    const screen = mount();
    try {
      let retry!: Promise<void>;
      await act(async () => {
        retry = screen.current.retryReport();
      });
      screen.update({
        ...project,
        id: '8',
        reportStatus: 'ready',
        canRetryReport: false,
      });
      await act(async () => {
        post.resolve(response(submission('queued')));
        await retry;
      });
      expect(screen.current.reportStatus).toBe('ready');
      expect(screen.current.reportRetrying).toBe(false);
    } finally {
      screen.close();
    }
  });

  it('rejects a successful response for a different submission', async () => {
    mockPost.mockResolvedValue(
      response({
        ...submission('queued'),
        id: '22222222-2222-4222-8222-222222222222',
      }),
    );
    await expect(retryProjectReport(endpoint)).rejects.toThrow(
      'PROJECT_SUBMISSION_CONTRACT_INVALID',
    );
  });
});
