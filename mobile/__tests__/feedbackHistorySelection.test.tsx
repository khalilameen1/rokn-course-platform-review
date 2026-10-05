import React from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {Text, TextInput} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockBoundary = {scope: 'user-7', epoch: 1};
let mockUuid = 0;
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({...mockBoundary})),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: async (key: string, boundary = mockBoundary) =>
    `${key}:${boundary.scope}`,
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
}));
jest.mock('../src/screens/feedback/pickFeedbackScreenshot', () => ({
  pickFeedbackScreenshot: jest.fn(async () => undefined),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () =>
    `11111111-1111-4111-8111-${String(++mockUuid).padStart(12, '0')}`,
}));
jest.mock('react-native-svg', () => ({
  __esModule: true,
  default: 'Svg',
  Path: 'Path',
}));

import {publicRequest} from '../src/constants/api';
import {captureAccountSessionBoundary} from '../src/constants/helpers';
import {persistProductFeedbackReceipt} from '../src/services/productFeedback';
import {useFeedbackCases} from '../src/screens/feedback/useFeedbackCases';
import {FeedbackConversation} from '../src/screens/feedback/FeedbackConversation';

const report = (index: number) => ({
  public_id: `01ARZ3NDEKTSV4RRFF${String(index).padStart(8, '0')}`,
  case_number: String(index).padStart(8, '0'),
  category: 'bug',
  status: 'in_progress',
  message: `بلاغ ${index}`,
  created_at: '2026-09-09T12:00:00Z',
  updated_at: '2026-09-09T12:00:00Z',
  attachments: [],
  messages: [],
});
const a = report(1);
const b = report(2);
const c = report(3);
const response = (payload: unknown) => ({data: {data: payload}});
const indexResponse = (items = [a, b]) =>
  response({
    items,
    pagination: {current_page: 1, last_page: 1, has_more: false},
  });
const drain = async () => {
  for (let index = 0; index < 100; index += 1) await Promise.resolve();
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const renderers: TestRenderer.ReactTestRenderer[] = [];
const mount = async (initialRoute = a.public_id) => {
  let cases!: ReturnType<typeof useFeedbackCases>;
  let requested = initialRoute;
  const Harness = () => {
    cases = useFeedbackCases(mockBoundary.scope, requested, true);
    return (
      <FeedbackConversation
        cases={cases.supportCases}
        casesBusy={cases.casesBusy}
        casesError={cases.casesError}
        onArtifactLoadError={cases.markArtifactLoadFailed}
        onChooseReplyAttachment={() => void cases.chooseReplyScreenshot()}
        onCloseArtifact={cases.closeArtifact}
        onOpenArtifact={(artifact, retry) =>
          void cases.openArtifact(artifact, retry)
        }
        onRefresh={() => void cases.reloadCases()}
        onRemoveReplyAttachment={cases.removeReplyScreenshot}
        onReplyChange={cases.setReply}
        onSelectCase={cases.selectCase}
        onSendReply={() => void cases.sendReply()}
        previewArtifact={cases.previewArtifact}
        previewLoadFailed={cases.previewLoadFailed}
        previewBusy={cases.previewBusy}
        previewRequestKey={cases.previewRequestKey}
        replyAttachment={cases.replyAttachment}
        replyAttachmentBusy={cases.replyAttachmentBusy}
        replyBusy={cases.replyBusy}
        replyError={cases.replyError}
        replyReady={cases.replyReady}
        replyRestoreError={cases.replyRestoreError}
        onRetryReplyRestore={cases.retryReplyRestore}
        replyMessage={cases.replyMessage}
        selectedCase={cases.selectedCase}
        selectedCaseId={cases.selectedCaseId}
      />
    );
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
    await drain();
  });
  renderers.push(renderer);
  return {
    get current() {
      return cases;
    },
    renderer,
    routeTo: (caseId: string) => {
      requested = caseId;
      renderer.update(<Harness />);
    },
    refreshButton: () =>
      renderer.root
        .findByProps({
          accessibilityRole: 'button',
          accessibilityLabel: 'تحديث الحالات',
        }),
    backToListButton: () =>
      renderer.root
        .findAllByProps({accessibilityRole: 'button'})
        .find(node =>
          node
            .findAllByType(Text)
            .some(text => text.props.children === 'كل الطلبات'),
        )!,
  };
};

beforeEach(async () => {
  jest.useFakeTimers();
  jest.clearAllMocks();
  mockBoundary = {scope: 'user-7', epoch: mockBoundary.epoch + 1};
  mockUuid = 0;
  jest.mocked(captureAccountSessionBoundary).mockImplementation(async () => ({
    ...mockBoundary,
  }));
  await AsyncStorage.clear();
  jest
    .mocked(publicRequest.get)
    .mockImplementation(
      async path =>
        (path === 'feedback' ? indexResponse() : response(c)) as never,
    );
});
afterEach(async () => {
  await act(async () => {
    renderers.splice(0).forEach(renderer => renderer.unmount());
    await drain();
  });
  jest.useRealTimers();
});

it('enters the route target once and refreshing the actual list does not reopen it', async () => {
  const view = await mount();
  expect(view.current.selectedCase?.publicId).toBe(a.public_id);
  await act(async () => {
    view.backToListButton().props.onPress();
    await drain();
  });
  expect(view.current.selectedCaseId).toBe('');
  expect(view.renderer.root.findAllByType(TextInput)).toHaveLength(0);
  await act(async () => {
    view.refreshButton().props.onPress();
    await drain();
  });
  expect(view.current.selectedCaseId).toBe('');
  expect(view.current.selectedCase).toBeUndefined();
  expect(view.current.casesError).toBe('');
  expect(view.current.supportCases).toHaveLength(2);
  expect(view.renderer.root.findAllByType(TextInput)).toHaveLength(0);
});

it('refreshes the learner-selected case rather than the initial route and keeps its reply', async () => {
  const view = await mount();
  await act(async () => {
    view.backToListButton().props.onPress();
    await drain();
  });
  const row = view.renderer.root.findByProps({
    accessibilityRole: 'button',
    accessibilityLabel: `الحالة ${b.case_number} قيد المراجعة`,
  });
  await act(async () => {
    row.props.onPress();
    await drain();
  });
  expect(view.current.selectedCase?.publicId).toBe(b.public_id);
  await act(async () => view.current.setReply('آخر تفاصيل البلاغ الثاني'));
  await act(async () => {
    view.refreshButton().props.onPress();
    await drain();
  });
  expect(view.current.selectedCase?.publicId).toBe(b.public_id);
  expect(view.current.replyMessage).toBe('آخر تفاصيل البلاغ الثاني');
  expect(view.renderer.root.findByType(TextInput).props.value).toBe(
    'آخر تفاصيل البلاغ الثاني',
  );
});

it('leaves a failed route target pending so plain refresh can recover it', async () => {
  jest.mocked(publicRequest.get).mockRejectedValueOnce(new Error('offline'));
  const view = await mount(b.public_id);
  expect(view.current.casesError).toBe('تعذّر تحديث الحالات الآن');
  expect(view.current.selectedCaseId).toBe(b.public_id);
  expect(view.current.selectedCase).toBeUndefined();
  await act(async () => {
    view.refreshButton().props.onPress();
    await drain();
  });
  expect(view.current.selectedCase?.publicId).toBe(b.public_id);
  expect(view.current.casesError).toBe('');
});

it.each(['list', 'case'])(
  'failed refresh and retry preserve the newer explicit %s choice',
  async choice => {
    const view = await mount();
    await act(async () => {
      view.current.selectCase(choice === 'list' ? '' : b.public_id);
      await drain();
    });
    const history = view.current.supportCases;
    jest.mocked(publicRequest.get).mockRejectedValueOnce(new Error('offline'));
    await act(async () => {
      await view.current.reloadCases();
    });
    expect(view.current.supportCases).toBe(history);
    expect(view.current.selectedCaseId).toBe(
      choice === 'list' ? '' : b.public_id,
    );
    expect(view.current.casesBusy).toBe(false);
    await act(async () => view.current.reloadCases());
    expect(view.current.selectedCaseId).toBe(
      choice === 'list' ? '' : b.public_id,
    );
    expect(view.current.casesError).toBe('');
  },
);

it('explicit follow-up targets a guest receipt and retains that intent through read failure', async () => {
  mockBoundary = {scope: 'guest-device', epoch: mockBoundary.epoch + 1};
  const view = await mount('');
  const token = 'guest-support-access-token-'.padEnd(40, 'x');
  const receipt = {
    accessToken: token,
    publicId: c.public_id,
    caseNumber: c.case_number,
    createdAt: c.created_at,
    attachments: [],
    messages: [],
    status: 'received',
    replayed: false,
  };
  await persistProductFeedbackReceipt(receipt, mockBoundary);
  jest.mocked(publicRequest.get).mockRejectedValueOnce(new Error('offline'));
  await act(async () => view.current.reloadCases(c.public_id, receipt));
  expect(view.current.selectedCaseId).toBe(c.public_id);
  expect(view.current.selectedCase).toBeUndefined();
  expect(view.current.casesError).toBe('تعذّر تحديث الحالات الآن');
  await act(async () => view.current.reloadCases());
  expect(view.current.selectedCase?.publicId).toBe(c.public_id);
  expect(view.current.selectedCase?.accessToken).toBe(token);
  expect(publicRequest.get).toHaveBeenLastCalledWith(
    `feedback/${c.public_id}`,
    {
      headers: {'X-Support-Access': token},
    },
  );
  await act(async () => view.current.selectCase(''));
  await act(async () => view.current.reloadCases('', receipt));
  expect(view.current.selectedCaseId).toBe('');
  expect(view.current.selectedCase).toBeUndefined();
});

it('new route navigation selects its target and clearing the route returns to the list', async () => {
  const view = await mount();
  await act(async () => {
    view.current.selectCase('');
    await drain();
  });
  await act(async () => {
    view.routeTo(b.public_id);
    await drain();
  });
  expect(view.current.selectedCase?.publicId).toBe(b.public_id);
  await act(async () => {
    view.routeTo('');
    await drain();
  });
  expect(view.current.selectedCaseId).toBe('');
  expect(view.current.selectedCase).toBeUndefined();
});

it.each(['resolve', 'reject'])(
  'late old-route %s cannot replace the current route data, error or busy state',
  async outcome => {
    const view = await mount();
    const oldRead = deferred<ReturnType<typeof indexResponse>>();
    const newRead = deferred<ReturnType<typeof indexResponse>>();
    jest
      .mocked(publicRequest.get)
      .mockReturnValueOnce(oldRead.promise as never);
    await act(async () => {
      void view.current.reloadCases();
      await drain();
    });
    jest
      .mocked(publicRequest.get)
      .mockReturnValueOnce(newRead.promise as never);
    await act(async () => {
      view.routeTo(b.public_id);
      await drain();
    });
    expect(view.current.selectedCase?.publicId).toBe(b.public_id);
    const history = view.current.supportCases;
    await act(async () => {
      if (outcome === 'resolve') oldRead.resolve(indexResponse([c]));
      else oldRead.reject(new Error('old route offline'));
      await drain();
    });
    expect(view.current.supportCases).toBe(history);
    expect(view.current.selectedCaseId).toBe(b.public_id);
    expect(view.current.casesError).toBe('');
    expect(view.current.casesBusy).toBe(true);
    await act(async () => {
      newRead.resolve(indexResponse());
      await drain();
    });
    expect(view.current.casesBusy).toBe(false);
    expect(view.current.selectedCase?.publicId).toBe(b.public_id);
  },
);

it('a retained old-route open-follow-up callback cannot retarget or launch another read', async () => {
  const view = await mount();
  const oldReload = view.current.reloadCases;
  await act(async () => {
    view.routeTo(b.public_id);
    await drain();
  });
  const reads = jest.mocked(publicRequest.get).mock.calls.length;
  await act(async () => oldReload(a.public_id));
  expect(view.current.selectedCaseId).toBe(b.public_id);
  expect(publicRequest.get).toHaveBeenCalledTimes(reads);
});

it('a route change during boundary capture prevents launching the obsolete read', async () => {
  const view = await mount();
  const pending = deferred<typeof mockBoundary>();
  jest
    .mocked(captureAccountSessionBoundary)
    .mockReturnValueOnce(pending.promise);
  const reads = jest.mocked(publicRequest.get).mock.calls.length;
  await act(async () => {
    void view.current.reloadCases();
    await drain();
  });
  await act(async () => {
    view.routeTo(b.public_id);
    await drain();
  });
  expect(publicRequest.get).toHaveBeenCalledTimes(reads + 1);
  await act(async () => {
    pending.resolve({...mockBoundary});
    await drain();
  });
  expect(publicRequest.get).toHaveBeenCalledTimes(reads + 1);
  expect(view.current.selectedCase?.publicId).toBe(b.public_id);
});

it('initial boundary capture cannot launch the former route after navigation changes', async () => {
  const pending = deferred<typeof mockBoundary>();
  // Initial draft restoration also captures a boundary. Hold initial captures
  // together rather than relying on their private effect/call ordering.
  jest
    .mocked(captureAccountSessionBoundary)
    .mockImplementation(() => pending.promise);
  const view = await mount();
  expect(publicRequest.get).not.toHaveBeenCalled();
  jest.mocked(captureAccountSessionBoundary).mockImplementation(async () => ({
    ...mockBoundary,
  }));
  await act(async () => {
    view.routeTo(b.public_id);
    await drain();
  });
  expect(view.current.selectedCase?.publicId).toBe(b.public_id);
  expect(publicRequest.get).toHaveBeenCalledTimes(1);
  await act(async () => {
    pending.resolve({...mockBoundary});
    await drain();
  });
  expect(publicRequest.get).toHaveBeenCalledTimes(1);
  expect(view.current.selectedCase?.publicId).toBe(b.public_id);
});
