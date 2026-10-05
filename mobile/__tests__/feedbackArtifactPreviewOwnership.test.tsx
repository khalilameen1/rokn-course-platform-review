import React from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {ActivityIndicator, Image, Modal} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockBoundary = {scope: 'user-1', epoch: 1};
let mockForeground = true;
let mockFocused = true;
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
  useAppForegroundState: () => mockForeground,
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
import {useFeedbackCases} from '../src/screens/feedback/useFeedbackCases';
import {FeedbackConversation} from '../src/screens/feedback/FeedbackConversation';

const firstId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const secondId = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
const time = '2026-09-08T12:00:00.000Z';
const artifact = (id = '1', fresh = false) => ({
  id,
  mime: 'image/jpeg',
  name: `support-${id}.jpg`,
  size: 2048,
  expires_at: new Date(Date.now() + (fresh ? 900_000 : -60_000)).toISOString(),
  url: `https://rokn.test/support/${id}?signature=${fresh ? 'new' : 'old'}`,
});
const casePayload = (publicId = firstId, fresh = false) => ({
  public_id: publicId,
  case_number: 'RKN12345',
  category: 'bug',
  message: 'الصورة توضح المشكلة في تشغيل المقطع',
  created_at: time,
  updated_at: time,
  status: 'received',
  attachments: [artifact(publicId === firstId ? '1' : '2', fresh)],
  messages: [
    {
      public_id: `message-${publicId}`,
      author: 'learner',
      text: 'الصورة توضح المشكلة في تشغيل المقطع',
      created_at: time,
      has_attachment: true,
      attachments: [artifact(publicId === firstId ? '1' : '2', fresh)],
    },
  ],
});
const response = (payload: unknown) => ({data: {data: payload}});
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
const renderers: TestRenderer.ReactTestRenderer[] = [];
const mount = async (fresh = false) => {
  jest.mocked(publicRequest.get).mockImplementation(
    async path =>
      response(
        path === 'feedback'
          ? {
              items: [
                casePayload(firstId, fresh),
                casePayload(secondId, fresh),
              ],
              pagination: {current_page: 1, last_page: 1, has_more: false},
            }
          : casePayload(
              String(path).endsWith(secondId) ? secondId : firstId,
              true,
            ),
      ) as never,
  );
  let current!: ReturnType<typeof useFeedbackCases>;
  const Harness = () => {
    current = useFeedbackCases(mockBoundary.scope, firstId, mockFocused);
    return null;
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
    await drain();
  });
  renderers.push(renderer);
  return {
    get current() {
      return current;
    },
    renderer,
    rerender: () => renderer.update(<Harness />),
    get image() {
      return current.selectedCase!.messages[0].attachments[0];
    },
  };
};
const startRenewal = async (view: Awaited<ReturnType<typeof mount>>) => {
  const pending = deferred<ReturnType<typeof response>>();
  jest.mocked(publicRequest.get).mockReturnValueOnce(pending.promise as never);
  await act(async () => {
    void view.current.openArtifact(view.image);
    await drain();
  });
  const config = jest.mocked(publicRequest.get).mock.calls.at(-1)![1];
  return {...pending, signal: config!.signal as AbortSignal};
};

beforeEach(async () => {
  jest.useFakeTimers();
  jest.clearAllMocks();
  mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
  mockForeground = true;
  mockFocused = true;
  mockUuid = 0;
  jest.mocked(captureAccountSessionBoundary).mockImplementation(async () => ({
    ...mockBoundary,
  }));
  await AsyncStorage.clear();
});
afterEach(async () => {
  await act(async () => {
    renderers.splice(0).forEach(renderer => renderer.unmount());
    await drain();
  });
  jest.useRealTimers();
});

it('opens the current canonical fresh descriptor without another GET or POST', async () => {
  const view = await mount(true);
  const reads = jest.mocked(publicRequest.get).mock.calls.length;
  await act(async () => {
    await view.current.openArtifact({
      ...view.image,
      url: 'https://other.test/old',
    });
  });
  expect(view.current.previewArtifact?.url).toBe(view.image.url);
  expect(view.current.previewBusy).toBe(false);
  expect(publicRequest.get).toHaveBeenCalledTimes(reads);
  expect(publicRequest.post).not.toHaveBeenCalled();
  await act(async () => view.current.closeArtifact());
  expect(view.current.previewArtifact).toBeUndefined();
});

it('renews only the descriptor using the same guest access and cancellable read', async () => {
  const view = await mount();
  const token = 'guest-support-access-token-'.padEnd(40, 'x');
  await act(async () => {
    await view.current.reloadCases(firstId, {
      accessToken: token,
      publicId: firstId,
      caseNumber: 'RKN12345',
      createdAt: time,
      attachments: [],
      messages: [],
      status: 'received',
      replayed: false,
    });
  });
  // The reload returned a fresh image; an explicit native failure uses retry.
  await act(async () => view.current.openArtifact(view.image));
  await act(async () => view.current.markArtifactLoadFailed(view.image.id));
  const history = view.current.selectedCase;
  const pending = deferred<ReturnType<typeof response>>();
  jest.mocked(publicRequest.get).mockReturnValueOnce(pending.promise as never);
  await act(async () => {
    void view.current.openArtifact(view.current.previewArtifact!, true);
    await drain();
  });
  expect(publicRequest.get).toHaveBeenLastCalledWith(`feedback/${firstId}`, {
    headers: {'X-Support-Access': token},
    signal: expect.any(AbortSignal),
  });
  expect(view.current.previewBusy).toBe(true);
  const renewed = casePayload(firstId, true);
  renewed.attachments[0].url += '&retry=1';
  await act(async () => {
    pending.resolve(response(renewed));
    await drain();
  });
  expect(view.current.previewArtifact?.url).toBe(renewed.attachments[0].url);
  expect(view.current.previewBusy).toBe(false);
  expect(view.current.previewLoadFailed).toBe(false);
  expect(view.current.selectedCase).toBe(history);
});

it.each(['case', 'list', 'focus', 'background', 'account', 'unmount'])(
  'dismisses and aborts on %s departure and ignores late completion',
  async departure => {
    const view = await mount();
    const retainedOpen = view.current.openArtifact;
    const oldImage = view.image;
    const pending = await startRenewal(view);
    expect(view.current.previewBusy).toBe(true);
    await act(async () => {
      if (departure === 'case') view.current.selectCase(secondId);
      if (departure === 'list') view.current.selectCase('');
      if (departure === 'focus') mockFocused = false;
      if (departure === 'background') mockForeground = false;
      if (departure === 'account')
        mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
      if (departure === 'unmount') view.renderer.unmount();
      else view.rerender();
      await drain();
    });
    expect(pending.signal.aborted).toBe(true);
    const reads = jest.mocked(publicRequest.get).mock.calls.length;
    await act(async () => {
      pending.resolve(response(casePayload(firstId, true)));
      await retainedOpen(oldImage);
      await drain();
    });
    expect(publicRequest.get).toHaveBeenCalledTimes(reads);
    if (departure !== 'unmount') {
      expect(view.current.previewArtifact).toBeUndefined();
      expect(view.current.previewLoadFailed).toBe(false);
      await act(async () => {
        mockForeground = true;
        mockFocused = true;
        view.current.selectCase(firstId);
        view.rerender();
        await drain();
      });
      expect(view.current.previewArtifact).toBeUndefined();
    }
  },
);

it('does not launch a GET after departure during boundary capture', async () => {
  const view = await mount();
  const boundary = deferred<typeof mockBoundary>();
  jest
    .mocked(captureAccountSessionBoundary)
    .mockReturnValueOnce(boundary.promise);
  const reads = jest.mocked(publicRequest.get).mock.calls.length;
  await act(async () => {
    void view.current.openArtifact(view.image);
    await drain();
  });
  await act(async () => view.current.selectCase(secondId));
  await act(async () => {
    boundary.resolve({...mockBoundary});
    await drain();
  });
  expect(publicRequest.get).toHaveBeenCalledTimes(reads);
  expect(view.current.previewArtifact).toBeUndefined();
});

it('ignores a failed renewal after switching cases without failing the new viewer', async () => {
  const view = await mount();
  const first = await startRenewal(view);
  await act(async () => view.current.selectCase(secondId));
  await act(async () => view.current.openArtifact(view.image));
  expect(view.current.previewArtifact?.id).toBe('2');
  await act(async () => {
    first.reject(new Error('late failure from the previous case'));
    await drain();
  });
  expect(view.current.previewArtifact?.id).toBe('2');
  expect(view.current.previewLoadFailed).toBe(false);
});

it('opening another current-case attachment cancels the prior renewal', async () => {
  const view = await mount();
  const withAnother = casePayload(firstId);
  withAnother.messages[0].attachments.push(artifact('3', true));
  jest.mocked(publicRequest.get).mockResolvedValueOnce(
    response({
      items: [withAnother],
      pagination: {current_page: 1, last_page: 1, has_more: false},
    }) as never,
  );
  await act(async () => view.current.reloadCases());
  const pending = await startRenewal(view);
  const next = view.current.selectedCase!.messages[0].attachments[1];
  const reads = jest.mocked(publicRequest.get).mock.calls.length;
  await act(async () => view.current.openArtifact(next));
  expect(pending.signal.aborted).toBe(true);
  expect(view.current.previewArtifact?.id).toBe('3');
  await act(async () => {
    pending.resolve(response(casePayload(firstId, true)));
    await drain();
  });
  expect(view.current.previewArtifact?.id).toBe('3');
  expect(publicRequest.get).toHaveBeenCalledTimes(reads);
});

it('close and reopen isolates late renewal failure and old native Image errors', async () => {
  const view = await mount();
  const pending = await startRenewal(view);
  const oldError = view.current.markArtifactLoadFailed;
  const oldKey = view.current.previewRequestKey;
  await act(async () => view.current.closeArtifact());
  expect(pending.signal.aborted).toBe(true);
  await act(async () => view.current.openArtifact(view.image));
  const newKey = view.current.previewRequestKey;
  expect(newKey).not.toBe(oldKey);
  await act(async () => {
    pending.reject(new Error('late old failure'));
    oldError(view.image.id);
    await drain();
  });
  expect(view.current.previewLoadFailed).toBe(false);
  await act(async () => view.current.markArtifactLoadFailed(view.image.id));
  expect(view.current.previewLoadFailed).toBe(true);
});

it('same attachment ID on a renewed URL has a distinct native image attempt', async () => {
  const view = await mount();
  const pending = await startRenewal(view);
  const staleNativeError = view.current.markArtifactLoadFailed;
  const previousKey = view.current.previewRequestKey;
  await act(async () => {
    pending.resolve(response(casePayload(firstId, true)));
    await drain();
  });
  expect(view.current.previewRequestKey).not.toBe(previousKey);
  await act(async () => staleNativeError('1'));
  expect(view.current.previewLoadFailed).toBe(false);
  await act(async () => view.current.markArtifactLoadFailed('1'));
  expect(view.current.previewLoadFailed).toBe(true);
});

it('deduplicates repeated open/retry while renewal is pending', async () => {
  const view = await mount();
  const pending = await startRenewal(view);
  const reads = jest.mocked(publicRequest.get).mock.calls.length;
  await act(async () => {
    void view.current.openArtifact(view.image);
    void view.current.openArtifact(view.image, true);
    await drain();
  });
  expect(publicRequest.get).toHaveBeenCalledTimes(reads);
  await act(async () => {
    pending.reject(new Error('temporary outage'));
    await drain();
  });
  expect(view.current.previewBusy).toBe(false);
  expect(view.current.previewLoadFailed).toBe(true);
  const retry = deferred<ReturnType<typeof response>>();
  jest.mocked(publicRequest.get).mockReturnValueOnce(retry.promise as never);
  const retryAction = view.current.openArtifact;
  await act(async () => {
    void retryAction(view.image, true);
    void retryAction(view.image, true);
    await drain();
  });
  expect(publicRequest.get).toHaveBeenCalledTimes(reads + 1);
  await act(async () => {
    retry.resolve(response(casePayload(firstId, true)));
    await drain();
  });
  expect(view.current.previewLoadFailed).toBe(false);
});

it('does not overwrite a delivered reply with the older renewal GET snapshot', async () => {
  const view = await mount();
  const pending = await startRenewal(view);
  const updated = casePayload(firstId, true);
  updated.messages.push({
    public_id: 'new-reply',
    author: 'learner',
    text: 'تفاصيل جديدة للمشكلة',
    created_at: time,
    has_attachment: false,
    attachments: [],
  });
  jest
    .mocked(publicRequest.post)
    .mockResolvedValueOnce(response(updated) as never);
  await act(async () => view.current.setReply('تفاصيل جديدة للمشكلة'));
  await act(async () => {
    await view.current.sendReply();
    await drain();
  });
  expect(view.current.selectedCase?.messages).toHaveLength(2);
  const accepted = view.current.selectedCase;
  await act(async () => {
    pending.resolve(response(casePayload(firstId, true)));
    await drain();
  });
  expect(view.current.selectedCase).toBe(accepted);
  expect(view.current.selectedCase?.messages[1].text).toBe(
    'تفاصيل جديدة للمشكلة',
  );
  expect(view.current.previewArtifact?.url).toContain('signature=new');
});

it.each(['wrong-case', 'missing-artifact', 'expired-link'])(
  'keeps an actionable failure without retry loops for %s renewal',
  async invalid => {
    const view = await mount();
    const pending = await startRenewal(view);
    const payload = casePayload(
      invalid === 'wrong-case' ? secondId : firstId,
      true,
    );
    if (invalid === 'missing-artifact') {
      payload.attachments = [];
      payload.messages = [];
    }
    if (invalid === 'expired-link') {
      payload.attachments = [artifact()];
      payload.messages = [];
    }
    const reads = jest.mocked(publicRequest.get).mock.calls.length;
    await act(async () => {
      pending.resolve(response(payload));
      await drain();
    });
    expect(view.current.previewArtifact?.id).toBe('1');
    expect(view.current.previewBusy).toBe(false);
    expect(view.current.previewLoadFailed).toBe(true);
    expect(publicRequest.get).toHaveBeenCalledTimes(reads);
  },
);

it('rejects arbitrary attachments that are absent from the selected case', async () => {
  const view = await mount(true);
  const reads = jest.mocked(publicRequest.get).mock.calls.length;
  await act(async () => view.current.openArtifact({...view.image, id: '999'}));
  expect(view.current.previewArtifact).toBeUndefined();
  expect(publicRequest.get).toHaveBeenCalledTimes(reads);
});

it('binds native viewer remounts, busy presentation and retry accessibility', async () => {
  const view = await mount();
  const pending = await startRenewal(view);
  const props = (): React.ComponentProps<typeof FeedbackConversation> => ({
    cases: view.current.supportCases,
    casesBusy: false,
    casesError: '',
    selectedCase: view.current.selectedCase,
    selectedCaseId: firstId,
    previewArtifact: view.current.previewArtifact,
    previewLoadFailed: view.current.previewLoadFailed,
    previewBusy: view.current.previewBusy,
    previewRequestKey: view.current.previewRequestKey,
    onArtifactLoadError: view.current.markArtifactLoadFailed,
    onCloseArtifact: view.current.closeArtifact,
    onOpenArtifact: (image, retry) =>
      void view.current.openArtifact(image, retry),
    onChooseReplyAttachment: jest.fn(),
    onRemoveReplyAttachment: jest.fn(),
    onRefresh: jest.fn(),
    onReplyChange: jest.fn(),
    onSelectCase: jest.fn(),
    onSendReply: jest.fn(),
    replyBusy: false,
    replyError: '',
    replyMessage: '',
  });
  let ui!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    ui = TestRenderer.create(<FeedbackConversation {...props()} />);
  });
  renderers.push(ui);
  expect(ui.root.findByType(Modal).props.visible).toBe(true);
  expect(ui.root.findByType(ActivityIndicator).props.accessibilityLabel).toBe(
    'جارٍ تحميل الصورة',
  );
  const oldNativeImage = ui.root
    .findAllByType(Image)
    .find(node => node.props.accessibilityLabel === 'support-1.jpg')!;
  const oldNativeError = oldNativeImage.props.onError;
  await act(async () => {
    pending.resolve(response(casePayload(firstId, true)));
    await drain();
  });
  await act(async () => ui.update(<FeedbackConversation {...props()} />));
  const newNativeImage = ui.root
    .findAllByType(Image)
    .find(node => node.props.accessibilityLabel === 'support-1.jpg')!;
  expect(newNativeImage).not.toBe(oldNativeImage);
  expect(newNativeImage.props.resizeMethod).toBe('resize');
  await act(async () => oldNativeError());
  expect(view.current.previewLoadFailed).toBe(false);
  await act(async () => {
    newNativeImage.props.onError();
    await drain();
  });
  expect(view.current.previewLoadFailed).toBe(true);
  expect(view.current.previewArtifact?.id).toBe('1');
  await act(async () => ui.update(<FeedbackConversation {...props()} />));
  const retry = ui.root.findByProps({
    accessibilityLabel: 'إعادة تحميل الصورة',
  });
  expect(retry.props.accessibilityState).toEqual({
    busy: false,
    disabled: false,
  });
  await act(async () =>
    ui.update(<FeedbackConversation {...props()} previewBusy />),
  );
  expect(retry.props.disabled).toBe(true);
  await act(async () => {
    view.current.closeArtifact();
    await drain();
  });
  await act(async () => ui.update(<FeedbackConversation {...props()} />));
  expect(ui.root.findByType(Modal).props.visible).toBe(false);
});
