import React from 'react';
import {Alert} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import TestRenderer, {act} from 'react-test-renderer';

const mockPicker = jest.fn();
const mockCacheFile = jest.fn();
const mockCreate = jest.fn();
const mockStage = jest.fn();
const mockUpload = jest.fn();
const mockFinalize = jest.fn();
const mockRemoveFile = jest.fn(
  async (..._args: unknown[]): Promise<void> => undefined,
);
let mockBoundary = {epoch: 1, scope: 'portfolio-picker-owner'};
const mockCaptureBoundary = jest.fn(async () => mockBoundary);
let mockUuid = 0;

jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary !== mockBoundary)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: async () => true,
  retainLearnerDraftFiles: async () => undefined,
  removeLearnerDraftFile: (...args: unknown[]) => mockRemoveFile(...args),
  cacheLearnerDraftFile: (...args: unknown[]) => mockCacheFile(...args),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () =>
    `22222222-2222-4222-8222-${String(++mockUuid).padStart(12, '0')}`,
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

import {usePortfolioCreateFlow} from '../src/screens/Profile/gallery/usePortfolioCreateFlow';
import {PortfolioGalleryView} from '../src/screens/Profile/gallery/PortfolioGalleryView';
import type {PortfolioGalleryController} from '../src/screens/Profile/gallery/usePortfolioGalleryController';
import Button from '../src/components/touchables/Button';
import {writePortfolioEditorDraft} from '../src/services/portfolioDraft';

const previousMedia = {uri: 'file:///draft/old.jpg', type: 'image/jpeg'};
const selectedMedia = {uri: 'file:///draft/new.jpg', type: 'image/jpeg'};
const sourceProject = {
  projectId: '32',
  courseId: '3',
  title: 'مشروع الكورس',
  summary: 'متطلبات المشروع',
  courseName: 'الكورس',
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const flush = async () => {
  for (let index = 0; index < 40; index += 1) await Promise.resolve();
};
const captureBoundary = () => mockCaptureBoundary();

describe('portfolio picker versus create submission', () => {
  let flow!: ReturnType<typeof usePortfolioCreateFlow>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const mountedRef = {current: true};
  const busyRef = {current: false};
  const Harness = () => {
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
  const addButton = () =>
    renderer!.root
      .findAllByType(Button)
      .find(node => node.props.title === 'إضافة للبورتفوليو')!;

  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
    mockCaptureBoundary
      .mockReset()
      .mockImplementation(async () => mockBoundary);
    mountedRef.current = true;
    busyRef.current = false;
    await AsyncStorage.clear();
    await writePortfolioEditorDraft(
      {
        clientRequestId: '11111111-1111-4111-8111-111111111111',
        title: 'مشروعي',
        summary: 'وصف المشروع',
        media: [previousMedia],
        updatedAt: Date.now(),
      },
      mockBoundary,
    );
    mockPicker.mockResolvedValue({
      assets: [{uri: 'file:///picker/new.jpg', type: 'image/jpeg'}],
    });
    mockCacheFile.mockResolvedValue(selectedMedia);
    mockRemoveFile.mockReset().mockResolvedValue(undefined);
    mockCreate.mockResolvedValue({
      id: '71',
      title: 'مشروعي',
      summary: '',
      skills: [],
      media: [],
      publicationState: 'uploading',
    });
    mockStage.mockResolvedValue([]);
    mockUpload.mockResolvedValue({discardedFiles: 0, interrupted: false});
    mockFinalize.mockResolvedValue('published');
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    await act(async () => {
      flow.openAddProject();
      await flush();
    });
    expect(flow.draftMediaAssets).toEqual([previousMedia]);
  });

  afterEach(async () => {
    mountedRef.current = false;
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('does not send the old files while a replacement selection is being copied', async () => {
    const copy = deferred<typeof selectedMedia>();
    mockCacheFile.mockReturnValueOnce(copy.promise);
    let picking!: Promise<void>;
    try {
      await act(async () => {
        picking = flow.pickCover();
        await flush();
      });
      expect(mockCacheFile).toHaveBeenCalledTimes(1);
      await act(async () => {
        await flow.addProject();
        await flush();
      });
      expect(mockCreate).not.toHaveBeenCalled();
      expect(addButton().props.disable).toBe(true);
      const picker = renderer!.root.findByProps({
        accessibilityLabel: 'اختيار صور وفيديوهات المشروع',
      });
      expect(picker.props.disabled).toBe(true);
      expect(picker.props.accessibilityState).toEqual({busy: true});
      await act(async () => {
        await flow.pickCover();
      });
      expect(mockPicker).toHaveBeenCalledTimes(1);
      await act(async () => {
        copy.resolve(selectedMedia);
        await picking;
      });
      expect(flow.draftMediaAssets).toEqual([selectedMedia]);
      expect(addButton().props.disable).toBe(false);
      await act(async () => {
        await flow.addProject();
      });
      expect(mockCreate).toHaveBeenCalledTimes(1);
      expect(mockStage).toHaveBeenCalledWith(
        expect.objectContaining({sources: [selectedMedia]}),
      );
    } finally {
      await act(async () => {
        copy.resolve(selectedMedia);
        await picking;
      });
    }
  });

  it('does not combine an outstanding image choice with a different source project', async () => {
    const copy = deferred<typeof selectedMedia>();
    mockCacheFile.mockReturnValueOnce(copy.promise);
    let picking!: Promise<void>;
    try {
      await act(async () => {
        picking = flow.pickCover();
        await flush();
        flow.chooseSourceProject(sourceProject);
      });
      expect(flow.selectedSourceProject).toBeNull();
      expect(flow.draftTitle).toBe('مشروعي');
      await act(async () => {
        copy.resolve(selectedMedia);
        await picking;
      });
      await act(async () => flow.chooseSourceProject(sourceProject));
      expect(flow.selectedSourceProject).toEqual(sourceProject);
      expect(flow.draftMediaAssets).toEqual([]);
      await act(async () => flow.clearSelectedSourceProject());
      expect(flow.selectedSourceProject).toBeNull();
    } finally {
      await act(async () => {
        copy.resolve(selectedMedia);
        await picking;
      });
    }
  });

  it('locks submit synchronously even before the native picker resolves or the next render', async () => {
    const picker = deferred<{didCancel: boolean}>();
    mockPicker.mockReturnValueOnce(picker.promise);
    let picking!: Promise<void>;
    await act(async () => {
      picking = flow.pickCover();
      await flow.addProject();
      await flush();
    });
    expect(mockCreate).not.toHaveBeenCalled();
    expect(flow.pickingMedia).toBe(true);
    await act(async () => {
      picker.resolve({didCancel: true});
      await picking;
    });
    expect(flow.pickingMedia).toBe(false);
    expect(flow.draftMediaAssets).toEqual([previousMedia]);
    expect(addButton().props.disable).toBe(false);
  });

  it.each(['close', 'unmount', 'owner'])(
    'never opens the native picker when %s retires a pending boundary capture',
    async retirement => {
      const capturedOwner = mockBoundary;
      const capture = deferred<typeof mockBoundary>();
      mockCaptureBoundary.mockReturnValueOnce(capture.promise);
      let picking!: Promise<void>;
      await act(async () => {
        picking = flow.pickCover();
        await flush();
      });
      expect(mockPicker).not.toHaveBeenCalled();
      await act(async () => {
        if (retirement === 'close') flow.closeAddProject();
        else if (retirement === 'owner') {
          // Capture may have completed before the awaiting hook continues.
          // The current session still owns whether native UI may open now.
          capture.resolve(capturedOwner);
          mockBoundary = {
            epoch: mockBoundary.epoch + 1,
            scope: 'other-portfolio-owner',
          };
        } else {
          mountedRef.current = false;
          renderer!.unmount();
          renderer = undefined;
        }
        capture.resolve(capturedOwner);
        await picking;
      });
      expect(mockPicker).not.toHaveBeenCalled();
      expect(mockCacheFile).not.toHaveBeenCalled();
      expect(mockCreate).not.toHaveBeenCalled();
      expect(flow.draftMediaAssets).toEqual([previousMedia]);
      expect(Alert.alert).not.toHaveBeenCalled();
      if (retirement === 'close') {
        expect(flow.pickingMedia).toBe(false);
        await act(async () => {
          flow.openAddProject();
          await flush();
        });
        await act(async () => {
          await flow.pickCover();
        });
        expect(mockPicker).toHaveBeenCalledTimes(1);
        expect(flow.draftMediaAssets).toEqual([selectedMedia]);
      }
    },
  );

  it.each(['native', 'copy'])(
    'keeps the original selection and allows retry after %s failure',
    async failure => {
      if (failure === 'native')
        mockPicker.mockResolvedValueOnce({errorCode: 'permission'});
      else mockCacheFile.mockRejectedValueOnce(new Error('STORAGE_FULL'));
      await act(async () => {
        await flow.pickCover();
      });
      expect(flow.pickingMedia).toBe(false);
      expect(flow.draftMediaAssets).toEqual([previousMedia]);
      expect(mockCreate).not.toHaveBeenCalled();
      expect(addButton().props.disable).toBe(false);
      await act(async () => {
        await flow.pickCover();
      });
      expect(flow.draftMediaAssets).toEqual([selectedMedia]);
      expect(mockPicker).toHaveBeenCalledTimes(2);
    },
  );

  it.each(['close', 'owner', 'unmount'])(
    'does not apply delayed files after %s retires their destination',
    async retirement => {
      const copy = deferred<typeof selectedMedia>();
      mockCacheFile.mockReturnValueOnce(copy.promise);
      let picking!: Promise<void>;
      await act(async () => {
        picking = flow.pickCover();
        await flush();
      });
      await act(async () => {
        if (retirement === 'close') {
          flow.closeAddProject();
          flow.openAddProject();
        } else if (retirement === 'owner') {
          mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
        } else {
          mountedRef.current = false;
          renderer!.unmount();
          renderer = undefined;
        }
        await flush();
      });
      await act(async () => {
        copy.resolve(selectedMedia);
        await picking;
      });
      expect(flow.draftMediaAssets).toEqual([previousMedia]);
      expect(mockRemoveFile.mock.calls.map(([file]) => file)).toContainEqual(
        selectedMedia,
      );
      expect(mockCreate).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalled();
      if (retirement === 'close') {
        expect(flow.pickingMedia).toBe(false);
        await act(async () => {
          await flow.pickCover();
        });
        expect(flow.draftMediaAssets).toEqual([selectedMedia]);
      }
    },
  );

  it('does not hold an accepted selection behind old-file cleanup or let its completion unlock a newer picker', async () => {
    const cleanup = deferred<void>();
    mockRemoveFile.mockReturnValueOnce(cleanup.promise);
    let first!: Promise<void>;
    const secondCopy = deferred<typeof selectedMedia>();
    let second!: Promise<void>;
    try {
      await act(async () => {
        first = flow.pickCover();
        await flush();
      });
      expect(flow.draftMediaAssets).toEqual([selectedMedia]);
      expect(flow.pickingMedia).toBe(false);
      expect(addButton().props.disable).toBe(false);
      mockCacheFile.mockReturnValueOnce(secondCopy.promise);
      await act(async () => {
        second = flow.pickCover();
        await flush();
      });
      expect(flow.pickingMedia).toBe(true);
      await act(async () => {
        cleanup.resolve();
        await first;
      });
      expect(flow.pickingMedia).toBe(true);
      expect(addButton().props.disable).toBe(true);
    } finally {
      await act(async () => {
        cleanup.resolve();
        secondCopy.resolve(selectedMedia);
        await first;
        await second;
      });
    }
    expect(flow.pickingMedia).toBe(false);
  });

  it.each(['failure', 'closed visit'])(
    'releases the picker after %s without awaiting cleanup of unaccepted copies',
    async retirement => {
      const cleanup = deferred<void>();
      const copy = deferred<typeof selectedMedia>();
      mockRemoveFile.mockReturnValueOnce(cleanup.promise);
      mockPicker.mockResolvedValueOnce({
        assets: [
          {uri: 'file:///picker/one.jpg', type: 'image/jpeg'},
          {uri: 'file:///picker/two.jpg', type: 'image/jpeg'},
        ],
      });
      mockCacheFile
        .mockResolvedValueOnce(selectedMedia)
        .mockReturnValueOnce(copy.promise);
      let picking!: Promise<void>;
      let settled = false;
      try {
        await act(async () => {
          picking = flow.pickCover().then(() => {
            settled = true;
          });
          await flush();
        });
        expect(mockCacheFile).toHaveBeenCalledTimes(2);
        await act(async () => {
          if (retirement === 'failure') copy.reject(new Error('STORAGE_FULL'));
          else {
            flow.closeAddProject();
            flow.openAddProject();
            copy.resolve({...selectedMedia, uri: 'file:///draft/two.jpg'});
          }
          await flush();
        });
        expect(flow.draftMediaAssets).toEqual([previousMedia]);
        expect(settled).toBe(true);
        expect(flow.pickingMedia).toBe(false);
        expect(addButton().props.disable).toBe(false);
        expect(mockCreate).not.toHaveBeenCalled();
      } finally {
        await act(async () => {
          cleanup.resolve();
          copy.resolve(selectedMedia);
          await picking;
        });
      }
    },
  );
});
