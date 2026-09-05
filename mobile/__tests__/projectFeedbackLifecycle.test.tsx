import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import type {ProjectFeedbackThread} from '../src/components/VideoPlayer/types';

const mockLoadThread = jest.fn<
  Promise<ProjectFeedbackThread | null>,
  string[]
>();

jest.mock('expo-document-picker', () => ({getDocumentAsync: jest.fn()}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user:7',
  })),
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));
jest.mock('../src/services/projectFeedbackDraft', () => ({
  cacheProjectFeedbackFile: jest.fn(),
  clearProjectFeedbackDraft: jest.fn(async () => undefined),
  loadProjectFeedbackDraft: jest.fn(async () => null),
  saveProjectFeedbackDraft: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  loadProjectFeedbackThread: (...args: string[]) => mockLoadThread(...args),
  sendProjectFeedbackMessage: jest.fn(),
  uploadProjectFeedbackAttachment: jest.fn(),
}));

import {useProjectFeedback} from '../src/components/VideoPlayer/projectTransition/useProjectFeedback';

const emptyThread: ProjectFeedbackThread = {
  id: 'thread-7',
  feedbackLevel: 'enhanced',
  canReply: true,
  status: 'ready',
  remainingMessages: 5,
  messages: [],
};
const loadedThread: ProjectFeedbackThread = {
  ...emptyThread,
  messages: [
    {
      id: 'report-7',
      role: 'assistant',
      status: 'completed',
      text: 'نتيجة مشروعك',
    },
  ],
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(next => {
    resolve = next;
  });
  return {promise, resolve};
};

describe('project feedback interrupted hydration', () => {
  beforeEach(() => {
    mockLoadThread.mockReset();
  });

  it.each(['background', 'close'] as const)(
    'resumes report loading after %s without accepting the interrupted response',
    async interruption => {
      const interrupted = deferred<ProjectFeedbackThread>();
      const resumed = deferred<ProjectFeedbackThread>();
      mockLoadThread
        .mockReturnValueOnce(interrupted.promise)
        .mockReturnValueOnce(resumed.promise);
      let current!: ReturnType<typeof useProjectFeedback>;
      const Harness = ({away = false}: {away?: boolean}) => {
        current = useProjectFeedback({
          active: interruption === 'close' ? !away : true,
          appIsActive: interruption === 'background' ? !away : true,
          projectId: '7',
          seedThread: emptyThread,
          feedbackLevel: 'enhanced',
          replyEnabled: true,
          reportStatus: 'ready',
        });
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Harness />);
        });
        expect(mockLoadThread).toHaveBeenCalledTimes(1);
        expect(current.hydrating).toBe(true);
        await act(async () => {
          renderer.update(<Harness away />);
        });
        await act(async () => {
          renderer.update(<Harness />);
        });
        expect(mockLoadThread).toHaveBeenCalledTimes(2);

        await act(async () => {
          interrupted.resolve({
            ...loadedThread,
            messages: [
              {
                id: 'stale',
                role: 'assistant',
                status: 'completed',
                text: 'رد قديم',
              },
            ],
          });
        });
        expect(current.thread?.messages).toEqual([]);
        expect(current.hydrating).toBe(true);
        await act(async () => {
          resumed.resolve(loadedThread);
        });
        expect(current.thread).toEqual(loadedThread);
        expect(current.hydrating).toBe(false);
        expect(current.canReply).toBe(true);
      } finally {
        if (renderer) act(() => renderer.unmount());
      }
    },
  );
});
