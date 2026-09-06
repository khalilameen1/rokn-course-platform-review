import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGet = jest.fn();
const mockPost = jest.fn();
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    scope: 'user-a',
    epoch: 1,
  })),
  assertAccountSessionBoundary: jest.fn(),
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
import {assertAccountSessionBoundary} from '../src/constants/helpers';
import {retryProjectReview} from '../src/components/VideoPlayer/courseLearning/projectRemote';
import {mapCourseProject} from '../src/components/VideoPlayer/courseLearning/projectMapping';
import {resolveProjectJourneyState} from '../src/components/VideoPlayer/courseLearning/projectJourney';
import type {
  CourseProject,
  ProjectStatus,
} from '../src/components/VideoPlayer/types';

const id = '11111111-1111-4111-8111-111111111111';
const endpoint = `/api/v1/project-submissions/${id}/review/retry`;
const submission = (status: ProjectStatus = 'evaluating') => ({
  id,
  submission_status: status,
  can_submit: false,
  can_continue: false,
  feedback_level: 'pass_only',
  report_enabled: false,
  report_status: 'not_included',
  can_retry_review: status === 'review_unavailable',
  review_retry_endpoint: endpoint,
  review_failure_category:
    status === 'review_unavailable' ? 'provider_unavailable' : null,
});
const response = (data: unknown) => ({
  status: 202,
  data: {status: 202, success: true, message: 'تم', data},
});
const project: CourseProject = {
  id: '7',
  sectionId: '9',
  moduleId: '3',
  title: 'مشروع',
  requirements: 'صمم',
  status: 'review_unavailable',
  isGraduationProject: false,
  canSubmit: false,
  canContinue: false,
  canRetryReview: true,
  reviewRetryEndpoint: endpoint,
  reportEnabled: false,
  reportStatus: 'not_included',
};
const mount = (input = project) => {
  let current!: ReturnType<typeof useProjectResolution>;
  const onResolution = jest.fn();
  function Harness() {
    current = useProjectResolution({
      active: true,
      appIsActive: true,
      project: input,
      onReviewResolution: onResolution,
    });
    return null;
  }
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => {
    renderer = TestRenderer.create(<Harness />);
  });
  return {
    get current() {
      return current;
    },
    onResolution,
    close: () => act(() => renderer.unmount()),
  };
};

describe('retrying an unavailable saved review', () => {
  beforeEach(() => jest.clearAllMocks());

  it('maps the distinct state and action into the course without exposing rejection feedback', () => {
    const mapped = mapCourseProject(
      {
        id: 9,
        content_id: 7,
        title: 'مشروع',
        content: {
          requirements_text: 'صمم',
          submission_max_file_bytes: 8388608,
          latest_submission: {
            ...submission('review_unavailable'),
            feedback: 'technical error',
          },
        },
      },
      '3',
    )!;
    expect(mapped).toMatchObject({
      status: 'review_unavailable',
      canSubmit: false,
      canContinue: false,
      canRetryReview: true,
      reviewRetryEndpoint: endpoint,
      submissionMaxFileBytes: 8388608,
    });
    expect(mapped.reviewFeedback).toBeUndefined();
    expect(
      resolveProjectJourneyState({
        status: mapped.status,
        draftReady: true,
        submitting: false,
        editingRetry: true,
      }),
    ).toBe('review_unavailable');
  });

  it('uses the same UUID once with no files and publishes pending to the course watcher', async () => {
    let resolve!: (value: unknown) => void;
    mockPost.mockReturnValue(
      new Promise(next => {
        resolve = next;
      }),
    );
    const screen = mount();
    try {
      let first!: Promise<void>;
      await act(async () => {
        first = screen.current.retryReview();
        await screen.current.retryReview();
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(mockPost).toHaveBeenCalledWith(
        `project-submissions/${id}/review/retry`,
        undefined,
        {timeout: 30000},
      );
      await act(async () => {
        resolve(response(submission()));
        await first;
      });
      expect(screen.current.status).toBe('evaluating');
      expect(screen.current.contract.canContinue).toBe(false);
      expect(screen.onResolution).toHaveBeenCalledWith(
        expect.objectContaining({status: 'evaluating', reportEnabled: false}),
      );
      expect(mockGet).not.toHaveBeenCalled();
    } finally {
      screen.close();
    }
  });

  it('does not retry without server permission', async () => {
    const screen = mount({...project, canRetryReview: false});
    try {
      await act(async () => {
        await screen.current.retryReview();
      });
      expect(mockPost).not.toHaveBeenCalled();
      expect(screen.current.status).toBe('review_unavailable');
    } finally {
      screen.close();
    }
  });

  it('recovers an uncertain POST through GET and never starts a second review', async () => {
    mockPost.mockRejectedValueOnce(new Error('timeout'));
    mockGet.mockResolvedValueOnce(response({latest_submission: submission()}));
    const screen = mount();
    try {
      await act(async () => {
        await screen.current.retryReview();
      });
      expect(screen.current.status).toBe('evaluating');
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(mockGet).toHaveBeenCalledWith('projects/7');
    } finally {
      screen.close();
    }
  });

  it('after both requests fail the next action only reads status and keeps the saved submission', async () => {
    mockPost.mockRejectedValueOnce(new Error('timeout'));
    mockGet.mockRejectedValueOnce(new Error('offline'));
    const screen = mount();
    try {
      await act(async () => {
        await screen.current.retryReview();
      });
      expect(screen.current.status).toBe('review_unavailable');
      expect(screen.current.reviewRecoveryRequired).toBe(true);
      expect(screen.current.reviewRetrying).toBe(false);
      mockGet.mockResolvedValueOnce(
        response({latest_submission: submission('review_unavailable')}),
      );
      await act(async () => {
        await screen.current.retryReview();
      });
      expect(screen.current.reviewRecoveryRequired).toBe(false);
      expect(screen.current.status).toBe('review_unavailable');
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(mockGet).toHaveBeenCalledTimes(2);
    } finally {
      screen.close();
    }
  });

  it('rejects an unrelated response identity and external retry URL', async () => {
    await expect(
      retryProjectReview(`https://other.test${endpoint}`),
    ).rejects.toThrow('INVALID_PROJECT_REVIEW_RETRY_ENDPOINT');
    expect(mockPost).not.toHaveBeenCalled();
    mockPost.mockResolvedValueOnce(
      response({...submission(), id: '22222222-2222-4222-8222-222222222222'}),
    );
    await expect(retryProjectReview(endpoint)).rejects.toThrow(
      'PROJECT_SUBMISSION_CONTRACT_INVALID',
    );
  });

  it('does not read another account after a lost retry response or deliver after unmount', async () => {
    let resolve!: (value: unknown) => void;
    mockPost.mockReturnValue(
      new Promise(next => {
        resolve = next;
      }),
    );
    const screen = mount();
    let request!: Promise<void>;
    await act(async () => {
      request = screen.current.retryReview();
    });
    screen.close();
    await act(async () => {
      resolve(response(submission()));
      await request;
    });
    expect(screen.onResolution).not.toHaveBeenCalled();

    mockPost.mockRejectedValueOnce(new Error('offline'));
    jest.mocked(assertAccountSessionBoundary).mockImplementationOnce(() => {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    });
    const next = mount();
    try {
      await act(async () => {
        await next.current.retryReview();
      });
      expect(mockGet).not.toHaveBeenCalled();
      expect(next.onResolution).not.toHaveBeenCalled();
    } finally {
      next.close();
    }
  });
});
