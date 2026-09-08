import React, {useState} from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockFinalize = jest.fn();
const mockGetItem = jest.fn();
const mockUpdate = jest.fn();
const mockDeleteMedia = jest.fn();
const mockLaunchImageLibrary = jest.fn();
const mockUploadMedia = jest.fn();
const mockCancelLibraryLoad = jest.fn();
const mockSetMutationBlocked = jest.fn();

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  finalizePortfolioItem: (...args: unknown[]) => mockFinalize(...args),
  getPortfolioItem: (...args: unknown[]) => mockGetItem(...args),
  updatePortfolioItem: (...args: unknown[]) => mockUpdate(...args),
  deletePortfolioMedia: (...args: unknown[]) => mockDeleteMedia(...args),
  deletePortfolioItem: jest.fn(async () => undefined),
}));
jest.mock('react-native-image-picker', () => ({
  launchImageLibrary: (...args: unknown[]) => mockLaunchImageLibrary(...args),
}));
jest.mock('../src/services/portfolioMediaOutbox', () => ({
  discardPortfolioMediaUploads: async () => undefined,
  listPortfolioMediaUploads: async () => [],
}));
jest.mock('../src/services/portfolioMediaUpload', () => ({
  stagePortfolioMediaFiles: async () => [],
  uploadPortfolioMediaFiles: (...args: unknown[]) => mockUploadMedia(...args),
}));
jest.mock('../src/services/mediaPickerErrors', () => ({
  showMediaPickerFailure: jest.fn(),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => 'new-upload',
}));

import {usePortfolioProjectDetails} from '../src/screens/Profile/gallery/usePortfolioProjectDetails';
import {
  toPortfolioProject,
  type Project,
} from '../src/screens/Profile/gallery/portfolioModel';
import type {PortfolioItem, PortfolioMedia} from '../src/services/roknApi';

const boundary = {epoch: 1, scope: 'owner-a'};
const captureBoundary = async () => boundary;
const isCreateBusy = () => false;
const oldMedia: PortfolioMedia = {
  id: '71',
  type: 'video',
  status: 'processing',
};
const readyMedia: PortfolioMedia = {
  ...oldMedia,
  status: 'ready',
  uri: 'https://example.com/old.mp4',
};
const newMedia: PortfolioMedia = {
  id: '72',
  type: 'image',
  status: 'ready',
  uri: 'https://example.com/new.jpg',
};
const item = (overrides: Partial<PortfolioItem> = {}): PortfolioItem => ({
  id: '9',
  title: 'العنوان القديم',
  summary: '',
  skills: [],
  featured: false,
  media: [oldMedia],
  publicationState: 'uploading',
  uploadedMediaCount: 1,
  expectedMediaCount: 1,
  ...overrides,
});
const published = (overrides: Partial<PortfolioItem> = {}) =>
  item({
    media: [readyMedia],
    publicationState: 'published',
    ...overrides,
  });
const deferred = <T,>() => {
  let resolve!: (result: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const settle = async (run: () => void | Promise<unknown>) => {
  await act(async () => {
    await run();
    await Promise.resolve();
    await Promise.resolve();
  });
};

describe('portfolio publication versus newer mutations', () => {
  let owner: ReturnType<typeof usePortfolioProjectDetails>;
  let projects: Project[];
  let renderer: TestRenderer.ReactTestRenderer;
  const mountedRef = {current: true};
  const Harness = () => {
    const [library, setLibrary] = useState([toPortfolioProject(item())]);
    projects = library;
    owner = usePortfolioProjectDetails({
      cancelLibraryLoad: mockCancelLibraryLoad,
      captureBoundary,
      isCreateBusy,
      mountedRef,
      setLibraryProjects: setLibrary,
      setMutationBlocked: mockSetMutationBlocked,
    });
    return null;
  };
  const beginBackgroundResponse = async () => {
    const response = deferred<PortfolioItem>();
    mockFinalize
      .mockRejectedValueOnce({status: 409})
      .mockReturnValueOnce(response.promise);
    await settle(() => owner.finalizeAfterUpload('9', boundary));
    await act(async () => {
      await jest.advanceTimersByTimeAsync(3_000);
    });
    expect(mockFinalize).toHaveBeenCalledTimes(2);
    return response;
  };
  const saveTitle = async (title: string) => {
    await settle(() => owner.beginEdit());
    await settle(() => owner.setEditTitle(title));
    await settle(() => owner.saveProjectEdits());
  };

  beforeEach(async () => {
    jest.useFakeTimers();
    jest.resetAllMocks();
    mountedRef.current = true;
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    mockGetItem.mockResolvedValue(item());
    mockDeleteMedia.mockResolvedValue(undefined);
    await settle(() => {
      renderer = TestRenderer.create(<Harness />);
    });
    await settle(() => owner.openProject(projects[0]));
  });
  afterEach(async () => {
    await settle(() => renderer.unmount());
    jest.clearAllTimers();
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('does not restore an old title and lets the next background publication update the open item', async () => {
    const oldResponse = await beginBackgroundResponse();
    mockUpdate.mockResolvedValue(published({title: 'العنوان الجديد'}));
    await saveTitle('العنوان الجديد');
    await settle(() => oldResponse.resolve(published()));
    expect(projects[0].title).toBe('العنوان الجديد');
    expect(owner.selected?.title).toBe('العنوان الجديد');
    mockFinalize.mockResolvedValue(published({title: 'العنوان الجديد'}));
    await act(async () => {
      await jest.advanceTimersByTimeAsync(7_000);
    });
    expect(mockFinalize).toHaveBeenCalledTimes(3);
    expect(owner.selected?.shareReady).toBe(true);
    expect(projects[0].shareReady).toBe(true);
  });

  it('does not revive removed media or sharing, while a later real upload can still publish', async () => {
    const oldResponse = await beginBackgroundResponse();
    await settle(() => owner.removeSelectedMedia(oldMedia));
    const buttons = jest.mocked(Alert.alert).mock.calls.at(-1)?.[2];
    await settle(() =>
      buttons?.find(button => button.text === 'حذف')?.onPress?.(),
    );
    expect(mockDeleteMedia).toHaveBeenCalledWith('9', '71', boundary);
    expect(projects[0].media).toEqual([]);
    expect(owner.selected?.media).toEqual([]);
    await settle(() => oldResponse.resolve(published()));
    expect(projects[0].media).toEqual([]);
    expect(projects[0].shareReady).toBe(false);
    expect(owner.selected?.shareReady).toBe(false);

    mockFinalize.mockRejectedValueOnce({status: 409});
    mockGetItem.mockResolvedValue(
      item({media: [], uploadedMediaCount: 0, publicationState: 'draft'}),
    );
    await act(async () => {
      await jest.advanceTimersByTimeAsync(7_000);
    });
    mockLaunchImageLibrary.mockResolvedValue({
      assets: [{uri: 'file:///new.jpg', type: 'image/jpeg'}],
    });
    mockUploadMedia.mockImplementation(async ({onUploaded}) => {
      onUploaded('9', newMedia);
      return {interrupted: false, discardedFiles: 0};
    });
    mockFinalize.mockResolvedValue(published({media: [newMedia]}));
    await settle(() => owner.addSelectedMedia());
    expect(projects[0].media.map(media => media.id)).toEqual(['72']);
    expect(projects[0].shareReady).toBe(true);
    expect(owner.selected?.media.map(media => media.id)).toEqual(['72']);
    expect(owner.selected?.shareReady).toBe(true);
  });

  it('rejects a late 409 reconciliation snapshot as well as successful finalize responses', async () => {
    const oldRead = deferred<PortfolioItem>();
    mockFinalize.mockRejectedValueOnce({status: 409});
    mockGetItem.mockReturnValueOnce(oldRead.promise);
    let publication!: Promise<unknown>;
    await settle(() => {
      publication = owner.finalizeAfterUpload('9', boundary);
    });
    mockUpdate.mockResolvedValue(item({title: 'العنوان المحفوظ'}));
    await saveTitle('العنوان المحفوظ');
    await settle(async () => {
      oldRead.resolve(item());
      await publication;
    });
    expect(projects[0].title).toBe('العنوان المحفوظ');
    expect(owner.selected?.title).toBe('العنوان المحفوظ');
  });

  it('defers replay publication during an edit, then reads the saved version', async () => {
    const update = deferred<PortfolioItem>();
    mockUpdate.mockReturnValueOnce(update.promise);
    await settle(() => owner.beginEdit());
    await settle(() => owner.setEditTitle('عنوان بعد التعديل'));
    let saving!: Promise<void>;
    await settle(() => {
      saving = owner.saveProjectEdits();
    });
    await settle(() => owner.settleUploadedProjects(['9']));
    expect(mockFinalize).not.toHaveBeenCalled();
    await settle(async () => {
      update.resolve(item({title: 'عنوان بعد التعديل'}));
      await saving;
    });
    mockFinalize.mockResolvedValue(published({title: 'عنوان بعد التعديل'}));
    await act(async () => {
      await jest.advanceTimersByTimeAsync(3_000);
    });
    expect(mockFinalize).toHaveBeenCalledTimes(1);
    expect(projects[0].title).toBe('عنوان بعد التعديل');
    expect(owner.selected?.shareReady).toBe(true);
  });
});
