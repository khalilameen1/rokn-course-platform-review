import {
  projectFeedbackFailureText,
  projectFeedbackMessageCanRetry,
  projectFeedbackMessageRequiresFreshAttachments,
  projectFeedbackThreadIsPending,
} from '../src/components/VideoPlayer/projectFeedback/policy';

describe('project feedback retry policy', () => {
  it('never offers a second paid request while the provider outcome is unknown', () => {
    expect(
      projectFeedbackMessageCanRetry({
        errorCode: 'provider_outcome_unknown',
        canRetry: false,
      }),
    ).toBe(false);
    expect(projectFeedbackFailureText('provider_outcome_unknown')).toBe(
      'تعذّر تأكيد الرد الآن',
    );
  });

  it('offers retry only after a proven terminal retryable failure', () => {
    expect(
      projectFeedbackMessageCanRetry({canRetry: true, attachments: []}),
    ).toBe(true);
    expect(
      projectFeedbackMessageCanRetry({canRetry: false, attachments: []}),
    ).toBe(false);
  });

  it('reuses durable server attachments instead of forcing another upload', () => {
    expect(
      projectFeedbackMessageCanRetry({
        errorCode: 'provider_unavailable',
        canRetry: true,
        attachments: [{serverId: 'attachment-1'}],
      }),
    ).toBe(true);
    expect(
      projectFeedbackMessageRequiresFreshAttachments({
        errorCode: 'provider_unavailable',
        canRetry: true,
        attachments: [{serverId: 'attachment-1'}],
      }),
    ).toBe(false);

    expect(
      projectFeedbackMessageCanRetry({
        errorCode: 'provider_unavailable',
        canRetry: true,
        attachments: [{}],
      }),
    ).toBe(false);
    expect(
      projectFeedbackMessageRequiresFreshAttachments({
        errorCode: 'provider_unavailable',
        canRetry: true,
        attachments: [{}],
      }),
    ).toBe(true);
  });

  it('does not invent a paid retry when the server did not authorize it', () => {
    expect(
      projectFeedbackMessageCanRetry({
        errorCode: 'provider_unavailable',
        canRetry: false,
        attachments: [],
      }),
    ).toBe(false);
    expect(projectFeedbackFailureText('project_context_missing', false)).toBe(
      'تعذّر الرد الآن',
    );
  });

  it('polls only while the server still owns an unfinished message', () => {
    expect(projectFeedbackThreadIsPending([{status: 'queued'}])).toBe(true);
    expect(projectFeedbackThreadIsPending([{status: 'sent'}])).toBe(true);
    expect(projectFeedbackThreadIsPending([{status: 'streaming'}])).toBe(true);
    expect(
      projectFeedbackThreadIsPending([
        {status: 'completed'},
        {status: 'failed'},
      ]),
    ).toBe(false);
  });
});
