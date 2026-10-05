import React from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockBoundary = {scope: 'guest-device', epoch: 1};
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
import {
  loadProductFeedbackCases,
  persistProductFeedbackReceipt,
  ProductFeedbackHistoryIncompleteError,
} from '../src/services/productFeedback';
import {useFeedbackCases} from '../src/screens/feedback/useFeedbackCases';
import {FeedbackConversation} from '../src/screens/feedback/FeedbackConversation';

const report = (index: number, revision = 0) => ({
  public_id: `01ARZ3NDEKTSV4RRFF${String(index).padStart(8, '0')}`,
  case_number: String(index).padStart(8, '0'),
  category: 'bug',
  status: revision ? 'waiting_for_you' : 'in_progress',
  message: `بلاغ ${index} إصدار ${revision}`,
  created_at: '2026-09-09T12:00:00Z',
  updated_at: new Date(Date.UTC(2026, 8, 9, 12, revision)).toISOString(),
  attachments: [],
  messages: [
    {
      public_id: `message-${index}-${revision}`,
      author: 'learner',
      text: `بلاغ ${index} إصدار ${revision}`,
      created_at: '2026-09-09T12:00:00Z',
      has_attachment: false,
      attachments: [],
    },
  ],
});
const a = report(1);
const b = report(2);
const c = report(3);
const token = (index: number) => `guest-support-${index}`.padEnd(40, 'x');
const response = (payload: unknown) => ({data: {data: payload}});
const indexResponse = (items: ReturnType<typeof report>[]) =>
  response({
    items,
    pagination: {current_page: 1, last_page: 1, has_more: false},
  });
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const drain = async () => {
  for (let index = 0; index < 100; index += 1) await Promise.resolve();
};
let failing = new Set<string>();
let reports = new Map([a, b, c].map(item => [item.public_id, item]));
let indexItems = [c];
const normalGet = async (path: string) => {
  if (path === 'feedback') return indexResponse(indexItems);
  const caseId = path.split('/')[1];
  if (failing.has(caseId)) throw new Error('network unavailable');
  const payload = reports.get(caseId);
  if (!payload) throw new Error('missing test response');
  return response(payload);
};
const seed = async (...items: ReturnType<typeof report>[]) => {
  for (const item of items) {
    await persistProductFeedbackReceipt(
      {
        accessToken: token(Number(item.case_number)),
        publicId: item.public_id,
        caseNumber: item.case_number,
        createdAt: item.created_at,
        attachments: [],
        messages: [],
        status: 'received',
        replayed: false,
      },
      mockBoundary,
    );
  }
};
const renderers: TestRenderer.ReactTestRenderer[] = [];
const mount = async (initialCaseId = '') => {
  let cases!: ReturnType<typeof useFeedbackCases>;
  let requested = initialCaseId;
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
    routeTo: (id: string) => {
      requested = id;
      renderer.update(<Harness />);
    },
    refresh: () =>
      renderer.root
        .findByProps({
          accessibilityRole: 'button',
          accessibilityLabel: 'تحديث الحالات',
        })
        .props.onPress(),
  };
};

beforeEach(async () => {
  jest.useFakeTimers();
  jest.clearAllMocks();
  mockBoundary = {scope: 'guest-device', epoch: mockBoundary.epoch + 1};
  mockUuid = 0;
  failing = new Set();
  reports = new Map([a, b, c].map(item => [item.public_id, item]));
  indexItems = [c];
  await AsyncStorage.clear();
  jest
    .mocked(publicRequest.get)
    .mockImplementation(async path => (await normalGet(String(path))) as never);
});
afterEach(async () => {
  await act(async () => {
    renderers.splice(0).forEach(renderer => renderer.unmount());
    await drain();
  });
  jest.useRealTimers();
});

it('exposes usable authorized receipt results as incomplete, not a successful full array', async () => {
  await seed(a, b);
  failing.add(b.public_id);
  let error: unknown;
  try {
    await loadProductFeedbackCases();
  } catch (failure) {
    error = failure;
  }
  expect(error).toBeInstanceOf(ProductFeedbackHistoryIncompleteError);
  const incomplete = error as ProductFeedbackHistoryIncompleteError;
  expect(incomplete.cases.map(item => item.publicId)).toEqual([a.public_id]);
  expect(incomplete.cases[0].accessToken).toBe(token(1));
  expect(incomplete.reason).toEqual(new Error('network unavailable'));
  expect(publicRequest.get).not.toHaveBeenCalledWith('feedback');
  expect(publicRequest.get).toHaveBeenCalledWith(`feedback/${a.public_id}`, {
    headers: {'X-Support-Access': token(1)},
  });
});

it('first partial load shows available requests plus an accessible incomplete-refresh error', async () => {
  await seed(a, b);
  failing.add(b.public_id);
  const view = await mount();
  expect(view.current.supportCases.map(item => item.publicId)).toEqual([
    a.public_id,
  ]);
  expect(view.current.casesError).toBe('تعذّر تحديث بعض الطلبات');
  expect(view.current.casesBusy).toBe(false);
  const alert = view.renderer.root
    .findAllByType(Text)
    .find(node => node.props.accessibilityRole === 'alert')!;
  expect(alert.props.children).toBe('تعذّر تحديث بعض الطلبات');
  expect(
    view.renderer.root.findAllByType(Text).map(node => node.props.children),
  ).not.toContain('لا توجد متابعات سابقة');
  expect(publicRequest.post).not.toHaveBeenCalled();
  failing.clear();
  await act(async () => {
    view.refresh();
    await drain();
  });
  expect(view.current.supportCases).toHaveLength(2);
  expect(view.current.casesError).toBe('');
});

it('a partial refresh retains the failed selected case and its reply while updating successful cases', async () => {
  await seed(a, b);
  const view = await mount(a.public_id);
  await act(async () =>
    view.current.setReply('تفاصيل جديدة محفوظة في البلاغ الأول'),
  );
  const selected = view.current.selectedCase;
  reports.set(b.public_id, report(2, 1));
  failing.add(a.public_id);
  await act(async () => {
    view.refresh();
    await drain();
  });
  expect(view.current.supportCases).toHaveLength(2);
  expect(view.current.selectedCase).toBe(selected);
  expect(view.current.selectedCaseId).toBe(a.public_id);
  expect(view.current.replyMessage).toBe('تفاصيل جديدة محفوظة في البلاغ الأول');
  expect(
    view.current.supportCases.find(item => item.publicId === b.public_id)
      ?.status,
  ).toBe('waiting_for_you');
  expect(view.current.supportCases[0].publicId).toBe(b.public_id);
  expect(
    new Set(view.current.supportCases.map(item => item.publicId)).size,
  ).toBe(2);
  expect(view.current.casesError).toBe('تعذّر تحديث بعض الطلبات');
  failing.clear();
  await act(async () => {
    view.refresh();
    await drain();
  });
  expect(view.current.supportCases).toHaveLength(2);
  expect(view.current.selectedCaseId).toBe(a.public_id);
  expect(view.current.casesError).toBe('');
});

it('removes an absent cached request only after a complete receipt read', async () => {
  await seed(a, b);
  const view = await mount();
  failing.add(a.public_id);
  await act(async () => view.current.reloadCases());
  expect(view.current.supportCases).toHaveLength(2);
  await AsyncStorage.setItem(
    `@rokn/product-feedback-receipts/v1:${mockBoundary.scope}`,
    JSON.stringify([
      {publicId: b.public_id, accessToken: token(2), updatedAt: Date.now()},
    ]),
  );
  await act(async () => view.current.reloadCases());
  expect(view.current.supportCases.map(item => item.publicId)).toEqual([
    b.public_id,
  ]);
  expect(view.current.casesError).toBe('');
});

it('all failed receipt reads retain existing history without claiming an empty result', async () => {
  await seed(a, b);
  const view = await mount();
  const previous = view.current.supportCases;
  failing = new Set([a.public_id, b.public_id]);
  await act(async () => view.current.reloadCases());
  expect(view.current.supportCases).toBe(previous);
  expect(view.current.casesError).toBe('تعذّر تحديث الحالات الآن');
  expect(view.current.casesBusy).toBe(false);
});

it('zero successful reads on first entry shows an error rather than no previous requests', async () => {
  await seed(a, b);
  failing = new Set([a.public_id, b.public_id]);
  const view = await mount();
  expect(view.current.supportCases).toEqual([]);
  expect(view.current.casesError).toBe('تعذّر تحديث الحالات الآن');
  expect(
    view.renderer.root.findAllByType(Text).map(node => node.props.children),
  ).not.toContain('لا توجد متابعات سابقة');
});

it('a genuinely empty guest history stays empty without an error or network request', async () => {
  const view = await mount();
  expect(view.current.supportCases).toEqual([]);
  expect(view.current.casesError).toBe('');
  expect(publicRequest.get).not.toHaveBeenCalled();
  expect(
    view.renderer.root.findAllByType(Text).map(node => node.props.children),
  ).toContain('لا توجد متابعات سابقة');
});

it('complete account index plus a failed remembered guest case remains visibly incomplete', async () => {
  mockBoundary = {scope: 'user-7', epoch: mockBoundary.epoch + 1};
  await seed(a, b);
  const view = await mount(a.public_id);
  expect(view.current.supportCases).toHaveLength(3);
  failing.add(a.public_id);
  reports.set(b.public_id, report(2, 1));
  await act(async () => view.current.reloadCases());
  expect(view.current.supportCases).toHaveLength(3);
  expect(view.current.selectedCase?.publicId).toBe(a.public_id);
  expect(view.current.casesError).toBe('تعذّر تحديث بعض الطلبات');
  expect(
    view.current.supportCases.find(item => item.publicId === c.public_id),
  ).toBeDefined();
  expect(
    view.current.supportCases.find(item => item.publicId === b.public_id)
      ?.status,
  ).toBe('waiting_for_you');
});

it('an account index failure still rejects the complete snapshot despite usable receipt fallback', async () => {
  mockBoundary = {scope: 'user-7', epoch: mockBoundary.epoch + 1};
  await seed(a);
  const originalFailure = new Error('ACCOUNT_INDEX_UNAVAILABLE');
  jest.mocked(publicRequest.get).mockImplementation(async path => {
    if (path === 'feedback') throw originalFailure;
    return (await normalGet(String(path))) as never;
  });
  await expect(loadProductFeedbackCases()).rejects.toBe(originalFailure);
});

it('a malformed receipt response is incomplete rather than silently hidden', async () => {
  await seed(a, b);
  jest
    .mocked(publicRequest.get)
    .mockImplementation(
      async path =>
        (String(path).endsWith(b.public_id)
          ? response({public_id: b.public_id, message: 'missing dates'})
          : await normalGet(String(path))) as never,
    );
  const view = await mount();
  expect(view.current.supportCases.map(item => item.publicId)).toEqual([
    a.public_id,
  ]);
  expect(view.current.casesError).toBe('تعذّر تحديث بعض الطلبات');
});

it.each(['route', 'account'])(
  'late partial completion cannot merge into a newer %s owner',
  async departure => {
    await seed(a, b);
    const view = await mount();
    const pending = deferred<ReturnType<typeof response>>();
    let hold = true;
    jest.mocked(publicRequest.get).mockImplementation(async path => {
      if (hold && String(path).endsWith(a.public_id)) {
        hold = false;
        return (await pending.promise) as never;
      }
      return (await normalGet(String(path))) as never;
    });
    await act(async () => {
      void view.current.reloadCases();
      await drain();
    });
    await act(async () => {
      if (departure === 'account')
        mockBoundary = {scope: 'user-9', epoch: mockBoundary.epoch + 1};
      view.routeTo(departure === 'route' ? b.public_id : '');
      await drain();
    });
    const current = view.current.supportCases;
    expect(view.current.casesError).toBe('');
    await act(async () => {
      pending.reject(new Error('old partial failure'));
      await drain();
    });
    expect(view.current.supportCases).toBe(current);
    expect(view.current.casesError).toBe('');
    expect(view.current.casesBusy).toBe(false);
    if (departure === 'route')
      expect(view.current.selectedCase?.publicId).toBe(b.public_id);
    else
      expect(view.current.supportCases.map(item => item.publicId)).toEqual([
        c.public_id,
      ]);
  },
);
