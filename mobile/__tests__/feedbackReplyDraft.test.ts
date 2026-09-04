import AsyncStorage from '@react-native-async-storage/async-storage';

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string) => `${key}:test-account`,
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 0,
    scope: 'test-account',
  })),
}));

jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));

import {
  loadProductFeedbackCases,
  loadProductFeedbackDraft,
  loadProductFeedbackReplyDraft,
  saveProductFeedbackDraft,
  saveProductFeedbackReplyDraft,
  submitProductFeedback,
} from '../src/services/productFeedback';
import {learnerDraftFileIsReadable} from '../src/services/learnerDraftFiles';
import {publicRequest} from '../src/constants/api';

const formFieldNames = (body: unknown): string[] => {
  const candidate = body as {
    _parts?: Array<[string, unknown]>;
    entries?: () => IterableIterator<[string, unknown]>;
    getParts?: () => Array<{fieldName?: string}>;
  };
  if (candidate._parts) return candidate._parts.map(([name]) => name);
  if (candidate.getParts) {
    return candidate
      .getParts()
      .map(part => String(part.fieldName || ''))
      .filter(Boolean);
  }
  if (candidate.entries) {
    return [...candidate.entries()].map(([name]) => name);
  }
  return [];
};

describe('support reply recovery', () => {
  beforeEach(async () => {
    jest.mocked(learnerDraftFileIsReadable).mockResolvedValue(true);
    await AsyncStorage.clear();
  });

  it('keeps the same idempotency key with the unsent reply', async () => {
    const draft = {
      attachment: {
        uri: 'file:///persistent/reply.jpg',
        type: 'image/jpeg',
      },
      clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
      message: 'الرد الذي لم تصل استجابته بعد',
    };

    await saveProductFeedbackReplyDraft('01TESTCASE0000000000000000', draft);

    await expect(
      loadProductFeedbackReplyDraft('01TESTCASE0000000000000000'),
    ).resolves.toEqual(draft);
  });

  it('clears a sent reply without leaving a replayable request id', async () => {
    const publicId = '01TESTCASE0000000000000000';
    await saveProductFeedbackReplyDraft(publicId, {
      clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
      message: 'تم الإرسال',
    });

    await saveProductFeedbackReplyDraft(publicId, null);

    await expect(loadProductFeedbackReplyDraft(publicId)).resolves.toBeNull();
  });

  it('keeps an attached image while the learner is still writing the reply', async () => {
    const publicId = '01TESTCASE0000000000000000';
    const draft = {
      attachment: {uri: 'file:///persistent/reply.jpg', type: 'image/jpeg'},
      clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
      message: '',
    };

    await saveProductFeedbackReplyDraft(publicId, draft);

    await expect(loadProductFeedbackReplyDraft(publicId)).resolves.toEqual(
      draft,
    );
  });

  it('does not replay one key with a changed body when its image disappeared', async () => {
    const publicId = '01TESTCASE0000000000000000';
    await saveProductFeedbackReplyDraft(publicId, {
      attachment: {uri: 'file:///missing/reply.jpg', type: 'image/jpeg'},
      clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
      message: 'رد بصورة',
    });
    jest.mocked(learnerDraftFileIsReadable).mockResolvedValue(false);

    await expect(loadProductFeedbackReplyDraft(publicId)).resolves.toEqual({
      attachment: undefined,
      clientRequestId: '',
      message: 'رد بصورة',
    });
  });
});

describe('support case ownership and response contract', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
  });

  it('does not call the account-only case index for a guest', async () => {
    await expect(
      loadProductFeedbackCases({epoch: 0, scope: 'guest-device'}),
    ).resolves.toEqual([]);
    expect(publicRequest.get).not.toHaveBeenCalled();
  });

  it('rejects a damaged request id before starting a write', async () => {
    await expect(
      submitProductFeedback(
        {
          category: 'problem',
          clientRequestId: '------------------------------------',
          message: 'يتوقف الفيديو عند الانتقال إلى المقطع التالي',
        },
        {epoch: 0, scope: 'user-a'},
      ),
    ).rejects.toThrow('INVALID_FEEDBACK_ATTEMPT');
    expect(publicRequest.post).not.toHaveBeenCalled();
  });

  it('rejects a partial account case snapshot instead of hiding a malformed case', async () => {
    jest.mocked(publicRequest.get).mockResolvedValueOnce({
      data: {data: {items: [{public_id: 'broken'}]}},
    } as never);

    await expect(
      loadProductFeedbackCases({epoch: 0, scope: 'user-a'}),
    ).rejects.toThrow('INVALID_SUPPORT_CASE');
  });

  it('keeps the logical request durable with its diagnostics choice', async () => {
    const draft = {
      category: 'problem' as const,
      clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
      includeDiagnostics: true,
      message: 'يتوقف الفيديو عند الانتقال إلى المقطع التالي',
      updatedAt: Date.now(),
    };

    await saveProductFeedbackDraft(draft, {
      epoch: 0,
      scope: 'user-a',
    });

    await expect(
      loadProductFeedbackDraft({epoch: 0, scope: 'user-a'}),
    ).resolves.toEqual(draft);
  });

  it('does not send runtime diagnostics unless the learner chooses them', async () => {
    jest.mocked(publicRequest.post).mockResolvedValue({
      data: {
        data: {
          attachments: [],
          case_number: 'RKN12345',
          created_at: '2026-09-04T12:00:00.000Z',
          messages: [],
          public_id: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
          status: 'new',
        },
      },
    } as never);
    const boundary = {epoch: 0, scope: 'user-a'} as const;

    await submitProductFeedback(
      {
        category: 'problem',
        clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
        context: {
          includeDiagnostics: false,
          locale: 'ar',
          sourceScreen: 'settings',
        },
        message: 'يتوقف الفيديو عند الانتقال إلى المقطع التالي',
      },
      boundary,
    );

    const compactBody = jest.mocked(publicRequest.post).mock.calls[0][1];
    expect(formFieldNames(compactBody)).toEqual([
      'client_request_id',
      'category',
      'message',
    ]);

    await submitProductFeedback(
      {
        category: 'problem',
        clientRequestId: 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388',
        context: {
          includeDiagnostics: true,
          locale: 'ar',
          sourceScreen: 'settings',
        },
        message: 'تتعذر العودة إلى صفحة المقطع بعد فتح الدعم',
      },
      boundary,
    );

    const diagnosticBody = jest.mocked(publicRequest.post).mock.calls[1][1];
    expect(formFieldNames(diagnosticBody)).toEqual(
      expect.arrayContaining([
        'platform',
        'app_version',
        'screen_key',
        'locale',
        'screen_size',
        'font_scale',
      ]),
    );
  });

  it('does not fetch a case twice when the account index already returned it', async () => {
    const publicId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    jest.mocked(publicRequest.post).mockResolvedValue({
      data: {
        data: {
          attachments: [],
          case_number: 'RKN12345',
          created_at: '2026-09-04T12:00:00.000Z',
          messages: [],
          public_id: publicId,
          status: 'new',
        },
      },
    } as never);
    const boundary = {epoch: 0, scope: 'user-a'} as const;
    await submitProductFeedback(
      {
        category: 'problem',
        clientRequestId: '63efe954-8f6d-4d9e-8859-1bb02108b166',
        message: 'يتوقف الفيديو عند الانتقال إلى المقطع التالي',
      },
      boundary,
    );
    jest.mocked(publicRequest.get).mockResolvedValue({
      data: {
        data: {
          items: [
            {
              attachments: [],
              case_number: 'RKN12345',
              category: 'bug',
              created_at: '2026-09-04T12:00:00.000Z',
              message: 'يتوقف الفيديو عند الانتقال إلى المقطع التالي',
              messages: [],
              public_id: publicId,
              status: 'new',
              updated_at: '2026-09-04T12:00:00.000Z',
            },
          ],
        },
      },
    } as never);

    await expect(loadProductFeedbackCases(boundary)).resolves.toHaveLength(1);
    expect(publicRequest.get).toHaveBeenCalledTimes(1);
    expect(publicRequest.get).toHaveBeenCalledWith('feedback');
  });
});
