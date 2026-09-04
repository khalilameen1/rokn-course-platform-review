import {
  resolveProjectJourneyState,
  resolveProjectReportViewState,
} from '../src/components/VideoPlayer/courseLearning/projectJourney';

describe('project journey state', () => {
  it.each([
    [
      {
        status: 'draft',
        draftReady: false,
        submitting: false,
        editingRetry: false,
      },
      'details',
    ],
    [
      {
        status: 'draft',
        draftReady: true,
        submitting: false,
        editingRetry: false,
      },
      'draft',
    ],
    [
      {
        status: 'draft',
        draftReady: true,
        submitting: true,
        editingRetry: false,
      },
      'submitting',
    ],
    [
      {
        status: 'evaluating',
        draftReady: true,
        submitting: false,
        editingRetry: false,
      },
      'reviewing',
    ],
    [
      {
        status: 'passed',
        draftReady: true,
        submitting: false,
        editingRetry: false,
      },
      'passed',
    ],
    [
      {
        status: 'needs_changes',
        draftReady: true,
        submitting: false,
        editingRetry: false,
      },
      'needs_changes',
    ],
    [
      {
        status: 'needs_changes',
        draftReady: true,
        submitting: false,
        editingRetry: true,
      },
      'draft',
    ],
  ] as const)('resolves %o to %s', (input, expected) => {
    expect(resolveProjectJourneyState(input)).toBe(expected);
  });

  it('never calls an unacknowledged submission reviewing', () => {
    expect(
      resolveProjectJourneyState({
        status: 'evaluating',
        draftReady: true,
        submitting: true,
        editingRetry: false,
      }),
    ).toBe('submitting');
  });
});

describe('project report view state', () => {
  const defaultInput: Parameters<typeof resolveProjectReportViewState>[0] = {
    projectStatus: 'passed',
    reportEnabled: true,
    reportStatus: 'ready',
    hydrating: false,
    retryAvailable: false,
    thread: {
      id: 'thread-1',
      feedbackLevel: 'report',
      canReply: false,
      status: 'ready',
      remainingMessages: 0,
      messages: [
        {
          id: 'message-1',
          role: 'assistant',
          status: 'completed',
          text: 'تقرير المشروع',
        },
      ],
    },
  };
  const report = (overrides: Partial<typeof defaultInput> = {}) =>
    resolveProjectReportViewState({...defaultInput, ...overrides});

  it('does not show an empty report thread as a completed report', () => {
    expect(report({thread: undefined})).toBe('failed');
    expect(report({thread: {...defaultInput.thread!, messages: []}})).toBe(
      'failed',
    );
  });

  it('keeps report loading, ready and retry states mutually exclusive', () => {
    expect(report({reportStatus: 'queued'})).toBe('preparing');
    expect(report({hydrating: true})).toBe('loading');
    expect(report()).toBe('ready');
    expect(report({reportStatus: 'failed', retryAvailable: true})).toBe(
      'failed_retryable',
    );
    expect(report({projectStatus: 'evaluating'})).toBe('hidden');
  });
});
