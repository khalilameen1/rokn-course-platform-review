import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import type {
  ProjectFeedbackMessage,
  ProjectFeedbackThread,
} from '../src/components/VideoPlayer/types';

const mockLoadThread = jest.fn<
  Promise<ProjectFeedbackThread | null>,
  string[]
>();
let mockEpoch = 1;
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => 'request-7',
}));

jest.mock('expo-document-picker', () => ({getDocumentAsync: jest.fn()}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn((boundary: {epoch: number}) => {
    if (boundary.epoch !== mockEpoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: mockEpoch,
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
import {sendProjectFeedbackMessage} from '../src/components/VideoPlayer/courseLearningApi';
import {
  clearProjectFeedbackDraft,
  saveProjectFeedbackDraft,
} from '../src/services/projectFeedbackDraft';

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

  it.each([
    ['background', 'ready'],
    ['close', 'ready'],
    ['background', 'failed'],
    ['close', 'failed'],
  ] as const)(
    'resumes report loading after %s with status %s without accepting the interrupted response',
    async (interruption, reportStatus) => {
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
          reportStatus,
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
        expect(current.canReply).toBe(reportStatus === 'ready');
      } finally {
        if (renderer) act(() => renderer.unmount());
      }
    },
  );
});

describe('project inquiry lost acknowledgement', () => {
  let current!: ReturnType<typeof useProjectFeedback>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Harness = ({
    projectId = '7',
    seedThread = loadedThread,
    away = false,
  }: {
    projectId?: string;
    seedThread?: ProjectFeedbackThread;
    away?: boolean;
  }) => {
    current = useProjectFeedback({
      active: !away,
      appIsActive: !away,
      projectId,
      seedThread,
      feedbackLevel: 'enhanced',
      replyEnabled: true,
      reportStatus: 'ready',
    });
    return null;
  };
  const accepted = (
    status: ProjectFeedbackMessage['status'] = 'queued',
  ): ProjectFeedbackThread => ({
    ...loadedThread,
    remainingMessages: 4,
    messages: [
      ...loadedThread.messages,
      {
        id: 'user-7',
        role: 'user',
        status,
        text: 'هل التنفيذ مناسب',
        clientRequestId: 'request-7',
      },
    ],
  });
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => current.changeDraft('هل التنفيذ مناسب'));
  };
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockLoadThread.mockReset();
    jest.mocked(sendProjectFeedbackMessage).mockReset();
    mockEpoch = 1;
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it('keeps a loaded report and queued reply across a same-thread course summary and foreground return', async () => {
    await mount();
    jest.mocked(sendProjectFeedbackMessage).mockResolvedValueOnce(accepted());
    await act(async () => current.send());
    const summary: ProjectFeedbackThread = {
      ...emptyThread,
      transcriptIncluded: false,
      remainingMessages: 0,
    };
    await act(async () => renderer!.update(<Harness away />));
    await act(async () => renderer!.update(<Harness seedThread={summary} />));
    expect(current.thread?.messages).toEqual(accepted().messages);
    expect(current.thread?.remainingMessages).toBe(4);
    expect(current.pending).toBe(true);
    expect(current.hydrating).toBe(false);
    const complete = accepted('completed');
    mockLoadThread.mockResolvedValueOnce(complete);
    await act(async () => jest.advanceTimersByTime(2100));
    expect(current.thread).toEqual(complete);
    expect(current.pending).toBe(false);
    expect(sendProjectFeedbackMessage).toHaveBeenCalledTimes(1);
  });

  it('applies a revoked reply permission without deleting the same-thread report or inventing an exhausted quota', async () => {
    await mount();
    const summary: ProjectFeedbackThread = {
      ...emptyThread,
      transcriptIncluded: false,
      canReply: false,
      remainingMessages: 0,
    };
    await act(async () => renderer!.update(<Harness seedThread={summary} />));
    expect(current.thread?.messages).toEqual(loadedThread.messages);
    expect(current.canReply).toBe(false);
    expect(current.thread?.remainingMessages).toBe(5);
  });

  it('replaces a different thread and hydrates its summary instead of carrying the old report across', async () => {
    await mount();
    const read = deferred<ProjectFeedbackThread>();
    mockLoadThread.mockReturnValueOnce(read.promise);
    const summary: ProjectFeedbackThread = {
      ...emptyThread,
      id: 'thread-8',
      transcriptIncluded: false,
    };
    await act(async () =>
      renderer!.update(<Harness projectId="8" seedThread={summary} />),
    );
    expect(current.thread?.messages).toEqual([]);
    expect(current.hydrating).toBe(true);
    expect(current.draft).toBe('');
    expect(mockLoadThread).toHaveBeenCalledWith('8', 'thread-8');
    const complete = {...loadedThread, id: 'thread-8'};
    await act(async () => read.resolve(complete));
    expect(current.thread).toEqual(complete);
  });

  it.each([0, 500])(
    'recovers accepted request after HTTP %s/lost ACK and continues the existing reply poller',
    async status => {
      await mount();
      jest
        .mocked(sendProjectFeedbackMessage)
        .mockRejectedValueOnce(status ? {status} : new Error('timeout'));
      mockLoadThread.mockResolvedValueOnce(accepted());
      await act(async () => current.send());
      expect(mockLoadThread).toHaveBeenCalledWith('7', 'thread-7');
      expect(current.pending).toBe(true);
      expect(current.draft).toBe('');
      expect(current.error).toBe('');
      const completed: ProjectFeedbackThread = {
        ...accepted('completed'),
        messages: [
          ...accepted('completed').messages,
          {
            id: 'answer-7',
            role: 'assistant',
            status: 'completed',
            text: 'التنفيذ مناسب',
          },
        ],
      };
      mockLoadThread.mockResolvedValueOnce(completed);
      await act(async () => jest.advanceTimersByTime(2100));
      expect(current.thread).toEqual(completed);
      expect(current.pending).toBe(false);
      expect(sendProjectFeedbackMessage).toHaveBeenCalledTimes(1);
    },
  );

  it.each(['failed-read', 'unrelated-request'])(
    'keeps draft and request ID after %s for explicit same-ID retry',
    async kind => {
      await mount();
      jest
        .mocked(sendProjectFeedbackMessage)
        .mockRejectedValueOnce(new Error('timeout'));
      if (kind === 'failed-read')
        mockLoadThread.mockRejectedValueOnce(new Error('offline'));
      else
        mockLoadThread.mockResolvedValueOnce({
          ...accepted(),
          messages: accepted().messages.map(message => ({
            ...message,
            clientRequestId: 'older',
          })),
        });
      await act(async () => current.send());
      expect(mockLoadThread).toHaveBeenCalledTimes(1);
      expect(current.draft).toBe('هل التنفيذ مناسب');
      expect(current.thread).toEqual(loadedThread);
      expect(clearProjectFeedbackDraft).not.toHaveBeenCalled();
      expect(saveProjectFeedbackDraft).toHaveBeenCalledWith(
        'thread-7',
        expect.objectContaining({requestId: 'request-7'}),
        expect.anything(),
      );
      jest.mocked(sendProjectFeedbackMessage).mockResolvedValueOnce(accepted());
      await act(async () => current.send());
      expect(
        jest.mocked(sendProjectFeedbackMessage).mock.calls.map(call => call[2]),
      ).toEqual(['request-7', 'request-7']);
    },
  );

  it.each(['completed', 'failed'] as const)(
    'adopts an already %s reply without labelling its accepted message unsent',
    async status => {
      await mount();
      jest
        .mocked(sendProjectFeedbackMessage)
        .mockRejectedValueOnce({status: 502});
      const resolved: ProjectFeedbackThread = {
        ...accepted('completed'),
        messages: [
          ...accepted('completed').messages,
          {
            id: 'answer-7',
            role: 'assistant',
            status,
            text: status === 'completed' ? 'الرد الكامل' : 'الرد الجزئي',
            canRetry: status === 'failed',
          },
        ],
      };
      mockLoadThread.mockResolvedValueOnce(resolved);
      await act(async () => current.send());
      expect(current.thread).toEqual(resolved);
      expect(current.pending).toBe(false);
      expect(current.error).toBe('');
      expect(current.draft).toBe('');
      expect(sendProjectFeedbackMessage).toHaveBeenCalledTimes(1);
    },
  );

  it.each([400, 403, 409, 422, 429])(
    'does not turn definitive HTTP %s rejection into accepted recovery',
    async status => {
      await mount();
      jest
        .mocked(sendProjectFeedbackMessage)
        .mockRejectedValueOnce({response: {status}});
      await act(async () => current.send());
      expect(mockLoadThread).not.toHaveBeenCalled();
      expect(current.draft).toBe('هل التنفيذ مناسب');
      expect(current.pending).toBe(false);
    },
  );

  it.each(['account', 'project', 'unmount'])(
    'does not publish recovery after %s changes',
    async change => {
      await mount();
      const read = deferred<ProjectFeedbackThread>();
      mockLoadThread.mockReturnValueOnce(read.promise);
      jest
        .mocked(sendProjectFeedbackMessage)
        .mockRejectedValueOnce(new Error('timeout'));
      let sending!: Promise<void>;
      await act(async () => {
        sending = current.send();
      });
      expect(mockLoadThread).toHaveBeenCalledTimes(1);
      await act(async () => {
        if (change === 'account') mockEpoch += 1;
        else if (change === 'project')
          renderer!.update(<Harness projectId="8" />);
        else {
          renderer!.unmount();
          renderer = undefined;
        }
      });
      await act(async () => {
        read.resolve(accepted());
        await sending;
      });
      expect(clearProjectFeedbackDraft).not.toHaveBeenCalled();
      expect(current.thread).toEqual(loadedThread);
      expect(sendProjectFeedbackMessage).toHaveBeenCalledTimes(1);
    },
  );
});
