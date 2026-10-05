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
// Transport recovery cases exercise an already-consented account.
jest.mock('../src/services/aiConsent', () => ({
  requestAiConsent: jest.fn(async () => true),
}));
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

  it('reloads quota and reply permissions after upgrading a report-only enrollment without losing its draft', async () => {
    const reportOnly = {
      ...loadedThread,
      feedbackLevel: 'report' as const,
      canReply: false,
      remainingMessages: 0,
    };
    mockLoadThread.mockResolvedValue(loadedThread);
    let current!: ReturnType<typeof useProjectFeedback>;
    const Harness = ({upgraded = false}: {upgraded?: boolean}) => {
      current = useProjectFeedback({
        active: true,
        appIsActive: true,
        projectId: '7',
        seedThread: reportOnly,
        feedbackLevel: upgraded ? 'enhanced' : 'report',
        replyEnabled: upgraded,
        reportStatus: 'ready',
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      act(() => current.changeDraft('سؤالي محفوظ'));
      expect(current.canReply).toBe(false);
      expect(mockLoadThread).not.toHaveBeenCalled();
      await act(async () => {
        renderer.update(<Harness upgraded />);
      });
      expect(mockLoadThread).toHaveBeenCalledWith('7', 'thread-7');
      expect(current.thread?.remainingMessages).toBe(5);
      expect(current.canReply).toBe(true);
      expect(current.draft).toBe('سؤالي محفوظ');
    } finally {
      if (renderer) await act(async () => renderer.unmount());
    }
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

describe('project feedback read recovery', () => {
  let current!: ReturnType<typeof useProjectFeedback>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Harness = ({
    seedThread = emptyThread,
    projectId = '7',
    active = true,
    appIsActive = true,
    reportStatus = 'ready',
  }: {
    seedThread?: ProjectFeedbackThread;
    projectId?: string;
    active?: boolean;
    appIsActive?: boolean;
    reportStatus?: 'ready' | 'failed';
  }) => {
    current = useProjectFeedback({
      active,
      appIsActive,
      projectId,
      seedThread,
      reportStatus,
      feedbackLevel: 'enhanced',
      replyEnabled: true,
    });
    return null;
  };
  const failInitialRead = async () => {
    mockLoadThread.mockRejectedValue(new Error('offline'));
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    for (const delay of [1200, 2400]) {
      await act(async () => {
        jest.advanceTimersByTime(delay);
      });
    }
    expect(mockLoadThread).toHaveBeenCalledTimes(3);
    expect(current.readError).toBe('تعذّر تحميل التقرير');
    expect(current.hydrating).toBe(false);
  };
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockLoadThread.mockReset();
    mockEpoch = 1;
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it('retries one GET in place and keeps the draft without a paid send', async () => {
    await failInitialRead();
    act(() => current.changeDraft('سؤالي محفوظ'));
    const next = deferred<ProjectFeedbackThread>();
    mockLoadThread.mockReturnValue(next.promise);
    const retry = current.retryRead;
    await act(async () => {
      retry();
      retry();
    });
    expect(mockLoadThread).toHaveBeenCalledTimes(4);
    expect(current.readRetrying).toBe(true);
    await act(async () => {
      next.resolve(loadedThread);
    });
    expect(current.readError).toBe('');
    expect(current.readRetrying).toBe(false);
    expect(current.thread).toEqual(loadedThread);
    expect(current.draft).toBe('سؤالي محفوظ');
    act(() => retry());
    expect(mockLoadThread).toHaveBeenCalledTimes(4);
    expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
    expect(clearProjectFeedbackDraft).not.toHaveBeenCalled();
  });

  it('acknowledges a failed explicit read immediately and retires the old failure action', async () => {
    await failInitialRead();
    const oldRetry = current.retryRead;
    await act(async () => {
      oldRetry();
    });
    expect(mockLoadThread).toHaveBeenCalledTimes(4);
    expect(current.readError).toBe('تعذّر تحميل التقرير');
    expect(current.readRetrying).toBe(false);
    await act(async () => {
      jest.advanceTimersByTime(10000);
      oldRetry();
    });
    expect(mockLoadThread).toHaveBeenCalledTimes(4);
    mockLoadThread.mockResolvedValue(loadedThread);
    await act(async () => {
      current.retryRead();
    });
    expect(mockLoadThread).toHaveBeenCalledTimes(5);
    expect(current.readError).toBe('');
  });

  it.each(['close', 'background'] as const)(
    'retires the failed visit callback on %s even when reopening the same thread',
    async interruption => {
      await failInitialRead();
      const oldRetry = current.retryRead;
      await act(async () => {
        renderer!.update(
          <Harness
            active={interruption !== 'close'}
            appIsActive={interruption !== 'background'}
          />,
        );
      });
      const next = deferred<ProjectFeedbackThread>();
      mockLoadThread.mockReturnValue(next.promise);
      await act(async () => {
        renderer!.update(<Harness />);
      });
      expect(mockLoadThread).toHaveBeenCalledTimes(4);
      await act(async () => {
        oldRetry();
      });
      expect(mockLoadThread).toHaveBeenCalledTimes(4);
      await act(async () => {
        next.resolve(loadedThread);
      });
      act(() => oldRetry());
      expect(mockLoadThread).toHaveBeenCalledTimes(4);
      expect(current.readError).toBe('');
    },
  );

  it('keeps a received report when polling exhausts and reloads the pending reply without resending', async () => {
    const pendingThread: ProjectFeedbackThread = {
      ...loadedThread,
      messages: [
        ...loadedThread.messages,
        {id: 'reply-7', role: 'assistant', status: 'queued', text: ''},
      ],
    };
    mockLoadThread.mockResolvedValue(pendingThread);
    await act(async () => {
      renderer = TestRenderer.create(<Harness seedThread={pendingThread} />);
    });
    act(() => current.changeDraft('سؤال آخر محفوظ'));
    for (let attempt = 0; attempt < 30; attempt += 1) {
      await act(async () => {
        jest.runOnlyPendingTimers();
      });
    }
    expect(mockLoadThread).toHaveBeenCalledTimes(30);
    expect(current.readError).toBe('تعذّر تحديث الرد');
    expect(current.thread?.messages[0]).toEqual(loadedThread.messages[0]);
    expect(current.draft).toBe('سؤال آخر محفوظ');
    mockLoadThread.mockResolvedValue({
      ...pendingThread,
      messages: [
        ...loadedThread.messages,
        {
          id: 'reply-7',
          role: 'assistant',
          status: 'completed',
          text: 'ردك جاهز',
        },
      ],
    });
    await act(async () => {
      current.retryRead();
    });
    expect(mockLoadThread).toHaveBeenCalledTimes(31);
    expect(current.pending).toBe(false);
    expect(current.readError).toBe('');
    expect(current.draft).toBe('سؤال آخر محفوظ');
    expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
    expect(clearProjectFeedbackDraft).not.toHaveBeenCalled();
  });

  it('does not apply a manual retry result to another project', async () => {
    await failInitialRead();
    const next = deferred<ProjectFeedbackThread>();
    mockLoadThread.mockReturnValue(next.promise);
    await act(async () => {
      current.retryRead();
    });
    const other = {...loadedThread, id: 'thread-8'};
    await act(async () => {
      renderer!.update(<Harness projectId="8" seedThread={other} />);
    });
    await act(async () => {
      next.resolve(loadedThread);
    });
    expect(current.thread?.id).toBe('thread-8');
    expect(current.readError).toBe('');
    expect(current.readRetrying).toBe(false);
  });

  it('accepts the server failure message without mislabelling it as a failed GET', async () => {
    const failedReport: ProjectFeedbackThread = {
      ...loadedThread,
      status: 'failed',
      messages: [
        {
          id: 'report-7',
          role: 'assistant',
          status: 'failed',
          text: '',
          canRetry: true,
        },
      ],
    };
    mockLoadThread.mockResolvedValue(failedReport);
    await act(async () => {
      renderer = TestRenderer.create(<Harness reportStatus="failed" />);
    });
    expect(mockLoadThread).toHaveBeenCalledTimes(1);
    expect(current.thread).toEqual(failedReport);
    expect(current.readError).toBe('');
    expect(current.hydrating).toBe(false);
    expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
  });
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

  it.each([0, 0.5, 0.999])(
    'keeps a loaded report and queued reply across a same-thread course summary and foreground return with jitter sample %s',
    async jitterSample => {
      const randomness = jest.spyOn(Math, 'random').mockReturnValue(jitterSample);
      try {
        await mount();
        jest.mocked(sendProjectFeedbackMessage).mockResolvedValueOnce(accepted());
        await act(async () => current.send());
        const summary: ProjectFeedbackThread = {
          ...emptyThread,
          transcriptIncluded: false,
          remainingMessages: 0,
        };
        await act(async () => renderer!.update(<Harness away />));
        const resumed = deferred<ProjectFeedbackThread>();
        mockLoadThread.mockReturnValueOnce(resumed.promise);
        await act(async () => renderer!.update(<Harness seedThread={summary} />));
        expect(current.thread?.messages).toEqual(accepted().messages);
        expect(current.thread?.remainingMessages).toBe(4);
        expect(current.pending).toBe(true);
        expect(current.hydrating).toBe(true);
        expect(mockLoadThread).toHaveBeenCalledTimes(1);
        await act(async () => {resumed.resolve(accepted());});
        expect(current.hydrating).toBe(false);
        const complete = accepted('completed');
        mockLoadThread.mockResolvedValueOnce(complete);
        // The first post-hydration poll uses 1800 * 1.35 * [0.82, 1.12),
        // not a fixed 2100ms. This covers that bounded window at every sample.
        await act(async () => jest.advanceTimersByTimeAsync(3000));
        expect(current.thread).toEqual(complete);
        expect(current.pending).toBe(false);
        expect(mockLoadThread).toHaveBeenCalledTimes(2);
        expect(mockLoadThread).toHaveBeenNthCalledWith(2, '7', 'thread-7');
        await act(async () => jest.advanceTimersByTimeAsync(20000));
        expect(mockLoadThread).toHaveBeenCalledTimes(2);
        expect(sendProjectFeedbackMessage).toHaveBeenCalledTimes(1);
      } finally {
        randomness.mockRestore();
      }
    },
  );

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

  it('does not clear an edited question when an older uncertain request appears in a later transcript', async () => {
    await mount();
    jest
      .mocked(sendProjectFeedbackMessage)
      .mockRejectedValueOnce(new Error('timeout'));
    mockLoadThread.mockRejectedValueOnce(new Error('offline'));
    await act(async () => current.send());
    act(() => current.changeDraft('سؤال جديد لم أرسله'));
    await act(async () =>
      renderer!.update(<Harness seedThread={accepted('completed')} />),
    );
    expect(current.draft).toBe('سؤال جديد لم أرسله');
    expect(clearProjectFeedbackDraft).not.toHaveBeenCalled();
    expect(sendProjectFeedbackMessage).toHaveBeenCalledTimes(1);
  });

  it('does not send a paid request when its durable identity could not be saved', async () => {
    await mount();
    jest
      .mocked(saveProjectFeedbackDraft)
      .mockRejectedValueOnce(new Error('storage full'));
    await act(async () => current.send());
    expect(current.draftSaveError).toBe(true);
    expect(current.draft).toBe('هل التنفيذ مناسب');
    expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
    await act(async () => current.retryDraftSave());
    expect(current.draftSaveError).toBe(false);
    jest.mocked(sendProjectFeedbackMessage).mockResolvedValueOnce(accepted());
    await act(async () => current.send());
    expect(sendProjectFeedbackMessage).toHaveBeenCalledWith(
      'thread-7',
      'هل التنفيذ مناسب',
      'request-7',
      [],
    );
    expect(current.draft).toBe('');
  });

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

  it('refreshes a definitive quota race without consuming the rejected durable draft', async () => {
    await mount();
    jest.mocked(sendProjectFeedbackMessage).mockRejectedValueOnce({
      status: 422, data: {code: 'project_discussion_limit_reached'},
    });
    mockLoadThread.mockResolvedValueOnce({
      ...loadedThread, remainingMessages: 0, replyLimitReached: true,
    });
    await act(async () => {await current.send();});
    expect(mockLoadThread).toHaveBeenCalledTimes(1);
    expect(current.draft).toBe('هل التنفيذ مناسب');
    expect(current.canReply).toBe(false);
    expect(current.thread?.replyLimitReached).toBe(true);
    expect(clearProjectFeedbackDraft).not.toHaveBeenCalled();
    expect(sendProjectFeedbackMessage).toHaveBeenCalledTimes(1);
  });

  it('does not send when token/cost capacity is exhausted despite a positive message count', async () => {
    await mount();
    await act(async () => {
      renderer!.update(<Harness seedThread={{
        ...loadedThread, remainingMessages: 3, replyLimitReached: true,
      }} />);
    });
    expect(current.canReply).toBe(false);
    await act(async () => {await current.send();});
    expect(current.draft).toBe('هل التنفيذ مناسب');
    expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
    expect(clearProjectFeedbackDraft).not.toHaveBeenCalled();
  });

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
