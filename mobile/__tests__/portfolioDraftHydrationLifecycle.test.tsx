import React from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {TextInput} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockPicker = jest.fn();
const mockCreate = jest.fn();
const mockStage = jest.fn();
const mockUpload = jest.fn();
const mockFinalize = jest.fn();
let mockActiveBoundary = {epoch: 1, scope: 'portfolio-draft-owner'};

jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  assertAccountSessionBoundary: jest.fn(boundary => {
    if (
      boundary.scope !== mockActiveBoundary.scope ||
      boundary.epoch !== mockActiveBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  }),
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
  cacheLearnerDraftFile: jest.fn(),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => '22222222-2222-4222-8222-222222222222',
}));
jest.mock('react-native-image-picker', () => ({
  launchImageLibrary: (...args: unknown[]) => mockPicker(...args),
}));
jest.mock('react-native-video', () => 'Video');
jest.mock('react-native-linear-gradient', () => 'LinearGradient');
jest.mock('@react-navigation/native', () => ({useNavigation: () => ({})}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('../src/services/roknApi', () => ({
  getEligibleProjects: async () => [],
  createPortfolioItem: (...args: unknown[]) => mockCreate(...args),
}));
jest.mock('../src/services/portfolioMediaUpload', () => ({
  stagePortfolioMediaFiles: (...args: unknown[]) => mockStage(...args),
  uploadPortfolioMediaFiles: (...args: unknown[]) => mockUpload(...args),
}));

import {usePortfolioDraftEditor} from '../src/screens/Profile/gallery/usePortfolioDraftEditor';
import {usePortfolioCreateFlow} from '../src/screens/Profile/gallery/usePortfolioCreateFlow';
import {PortfolioGalleryView} from '../src/screens/Profile/gallery/PortfolioGalleryView';
import type {PortfolioGalleryController} from '../src/screens/Profile/gallery/usePortfolioGalleryController';
import Button from '../src/components/touchables/Button';
import {StatusView} from '../src/components/ui/PremiumUI';
import {writePortfolioEditorDraft} from '../src/services/portfolioDraft';

const boundary = {epoch: 1, scope: 'portfolio-draft-owner'};
const captureBoundary = async () => boundary;
const storedDraft = () => ({
  clientRequestId: '11111111-1111-4111-8111-111111111111',
  title: 'مشروع محفوظ',
  summary: 'وصف لم ينشر بعد',
  media: [{uri: 'file:///draft/portfolio.jpg', type: 'image/jpeg'}],
  updatedAt: Date.now(),
});
const flush = async () => {
  for (let index = 0; index < 20; index += 1) await Promise.resolve();
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('portfolio draft hydration lifecycle', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let editor: ReturnType<typeof usePortfolioDraftEditor>;
  const mountedRef = {current: true};
  const Harness = ({
    appActive = true,
    capture = captureBoundary,
  }: {
    appActive?: boolean;
    capture?: typeof captureBoundary;
  }) => {
    editor = usePortfolioDraftEditor({
      appActive,
      captureBoundary: capture,
      mountedRef,
    });
    return null;
  };

  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mountedRef.current = true;
    mockActiveBoundary = boundary;
    await AsyncStorage.clear();
  });

  afterEach(async () => {
    mountedRef.current = false;
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it('does not autosave an empty editor over a valid draft after a failed storage read', async () => {
    const draft = storedDraft();
    await writePortfolioEditorDraft(draft, boundary);
    const key = (await AsyncStorage.getAllKeys())[0];
    (AsyncStorage.getItem as jest.Mock).mockRejectedValueOnce(
      new Error('STORAGE_BUSY'),
    );
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    await act(async () => {
      jest.advanceTimersByTime(300);
      await flush();
    });
    expect(JSON.parse((await AsyncStorage.getItem(key)) || 'null')).toEqual(
      draft,
    );
    expect(editor!.draftTitle).toBe('');
    expect(editor!.draftReady).toBe(false);
    expect(editor!.draftLoadError).toBe(true);
    expect(editor!.draftSaveError).toBe(false);
    await act(async () => {
      editor!.changeDraft(() => editor!.setDraftTitle('بديل غير مقصود'));
      renderer!.update(<Harness appActive={false} />);
      await flush();
    });
    expect(editor!.draftTitle).toBe('');
    expect(JSON.parse((await AsyncStorage.getItem(key)) || 'null')).toEqual(
      draft,
    );
  });

  it('retries restoration explicitly and then saves edits with the restored media', async () => {
    const draft = storedDraft();
    await writePortfolioEditorDraft(draft, boundary);
    const key = (await AsyncStorage.getAllKeys())[0];
    (AsyncStorage.getItem as jest.Mock).mockRejectedValueOnce(
      new Error('STORAGE_BUSY'),
    );
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    const pending = deferred<string>();
    (AsyncStorage.getItem as jest.Mock).mockReturnValueOnce(pending.promise);
    const beforeRetry = (AsyncStorage.getItem as jest.Mock).mock.calls.length;
    await act(async () => {
      editor!.retryDraftLoad();
      editor!.retryDraftLoad();
      await flush();
    });
    expect(editor!.draftReady).toBe(false);
    expect(editor!.draftLoadError).toBe(false);
    expect(AsyncStorage.getItem).toHaveBeenCalledTimes(beforeRetry + 1);
    await act(async () => {
      pending.resolve(JSON.stringify(draft));
      await flush();
    });
    expect(editor!.draftReady).toBe(true);
    expect(editor!.clientRequestId).toBe(draft.clientRequestId);
    expect(editor!.draftMediaAssets).toEqual(draft.media);
    await act(async () =>
      editor!.changeDraft(() => editor!.setDraftTitle('آخر تعديل')),
    );
    await act(async () => {
      jest.advanceTimersByTime(300);
      await flush();
    });
    expect(
      JSON.parse((await AsyncStorage.getItem(key)) || 'null'),
    ).toMatchObject({
      ...draft,
      title: 'آخر تعديل',
      clientRequestId: '22222222-2222-4222-8222-222222222222',
      updatedAt: expect.any(Number),
    });
  });

  it('allows a new draft only after a successful absent-draft read', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    expect(editor!.draftReady).toBe(true);
    expect(editor!.draftLoadError).toBe(false);
    await act(async () =>
      editor!.changeDraft(() => editor!.setDraftTitle('مشروع جديد')),
    );
    expect(editor!.draftTitle).toBe('مشروع جديد');
  });

  it('does not apply a late restoration from a closed visit', async () => {
    const draft = storedDraft();
    await writePortfolioEditorDraft(draft, boundary);
    const key = (await AsyncStorage.getAllKeys())[0];
    const pending = deferred<string>();
    (AsyncStorage.getItem as jest.Mock).mockReturnValueOnce(pending.promise);
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    await act(async () => renderer!.unmount());
    await act(async () => {
      pending.resolve(JSON.stringify(draft));
      await flush();
      jest.advanceTimersByTime(300);
      await flush();
    });
    expect(editor!.draftReady).toBe(false);
    expect(editor!.draftTitle).toBe('');
    expect(JSON.parse((await AsyncStorage.getItem(key)) || 'null')).toEqual(
      draft,
    );
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    expect(editor!.draftTitle).toBe(draft.title);
    expect(editor!.clientRequestId).toBe(draft.clientRequestId);
  });

  it('never renders or autosaves an old owner response after the session changes', async () => {
    const draft = storedDraft();
    await writePortfolioEditorDraft(draft, boundary);
    const key = (await AsyncStorage.getAllKeys())[0];
    const pending = deferred<string>();
    (AsyncStorage.getItem as jest.Mock).mockReturnValueOnce(pending.promise);
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    mockActiveBoundary = {epoch: 2, scope: 'another-owner'};
    await act(async () => {
      pending.resolve(JSON.stringify(draft));
      await flush();
      jest.advanceTimersByTime(300);
      await flush();
    });
    expect(editor!.draftReady).toBe(false);
    expect(editor!.draftTitle).toBe('');
    expect(JSON.parse((await AsyncStorage.getItem(key)) || 'null')).toEqual(
      draft,
    );
    await act(async () => renderer!.unmount());
    const captureOther = async () => mockActiveBoundary;
    await act(async () => {
      renderer = TestRenderer.create(<Harness capture={captureOther} />);
      await flush();
    });
    expect(editor!.draftReady).toBe(true);
    expect(editor!.draftTitle).toBe('');
  });

  it('locks the real add form and picker until Retry restores the original create/upload input', async () => {
    const draft = storedDraft();
    await writePortfolioEditorDraft(draft, boundary);
    const key = (await AsyncStorage.getAllKeys())[0];
    (AsyncStorage.getItem as jest.Mock).mockRejectedValueOnce(
      new Error('STORAGE_BUSY'),
    );
    mockPicker.mockResolvedValue({didCancel: true});
    mockCreate.mockResolvedValue({
      id: '71',
      title: draft.title,
      summary: draft.summary,
      skills: [],
      media: [],
      publicationState: 'uploading',
    });
    mockStage.mockResolvedValue([]);
    mockUpload.mockResolvedValue({discardedFiles: 0, interrupted: false});
    mockFinalize.mockResolvedValue('published');
    let flow!: ReturnType<typeof usePortfolioCreateFlow>;
    const busyRef = {current: false};
    const CreateHarness = () => {
      flow = usePortfolioCreateFlow({
        appActive: true,
        busyRef,
        mountedRef,
        captureBoundary,
        cancelLibraryLoad: jest.fn(),
        finalizeAfterUpload: mockFinalize,
        isDetailBusy: () => false,
        onMediaUploaded: jest.fn(),
        reconcileProject: jest.fn(),
        serverSession: true,
        setLibraryProjects: jest.fn(),
      });
      return (
        <PortfolioGalleryView
          controller={
            {
              ...flow,
              serverSession: true,
              projects: [],
              loading: false,
              selected: null,
            } as unknown as PortfolioGalleryController
          }
        />
      );
    };
    await act(async () => {
      renderer = TestRenderer.create(<CreateHarness />);
      await flush();
    });
    await act(async () => {
      flow.openAddProject();
      await flush();
    });
    expect(
      renderer!.root
        .findAllByType(TextInput)
        .every(node => node.props.editable === false),
    ).toBe(true);
    const picker = renderer!.root.findByProps({
      accessibilityLabel: 'اختيار صور وفيديوهات المشروع',
    });
    expect(picker.props.disabled).toBe(true);
    expect(
      renderer!.root
        .findAllByType(Button)
        .find(node => node.props.title === 'إضافة للبورتفوليو')!.props.disable,
    ).toBe(true);
    expect(
      renderer!.root
        .findAllByType(Button)
        .find(node => node.props.title === 'إلغاء')!.props.disable,
    ).toBe(false);
    await act(async () => {
      flow.updateDraftTitle('بديل');
      flow.updateDraftSummary('بديل');
      await flow.pickCover();
      await flow.addProject();
    });
    expect(flow.draftTitle).toBe('');
    expect(mockPicker).not.toHaveBeenCalled();
    expect(mockCreate).not.toHaveBeenCalled();
    const retry = renderer!.root
      .findAllByType(StatusView)
      .find(node => node.props.state === 'error')!;
    expect(retry.props.actionLabel).toBe('إعادة المحاولة');
    await act(async () => {
      retry.props.onAction();
      await flush();
    });
    expect(flow.draftReady).toBe(true);
    expect(flow.draftTitle).toBe(draft.title);
    expect(
      renderer!.root
        .findAllByType(TextInput)
        .every(node => node.props.editable),
    ).toBe(true);
    await act(async () => {
      await flow.addProject();
      await flush();
    });
    expect(mockCreate).toHaveBeenCalledTimes(1);
    expect(mockCreate).toHaveBeenCalledWith(
      expect.objectContaining({
        clientRequestId: draft.clientRequestId,
        title: draft.title,
        summary: draft.summary,
        expectedMediaCount: 1,
      }),
      boundary,
    );
    expect(mockStage).toHaveBeenCalledWith(
      expect.objectContaining({
        sources: draft.media,
        boundary,
        projectId: '71',
      }),
    );
    expect(mockFinalize).toHaveBeenCalledWith('71', boundary);
    expect(flow.adding).toBe(false);
    expect(await AsyncStorage.getItem(key)).toBeNull();
  });
});
