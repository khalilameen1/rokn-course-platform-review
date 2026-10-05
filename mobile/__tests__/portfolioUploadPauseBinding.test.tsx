import React, {useState} from 'react';
import {Alert} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';
import {launchImageLibrary} from 'react-native-image-picker';
import TestRenderer, {act} from 'react-test-renderer';

const mockGet = jest.fn();
const mockPost = jest.fn();
const mockAccess = jest.fn();
let mockBoundary = {epoch: 1, scope: 'portfolio-pause-1'};
let mockUuid = 0;
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) =>
      args[0] === 'portfolio/upload-access'
        ? mockAccess(...args)
        : mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  captureAccountSessionBoundary: async () => mockBoundary,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary !== mockBoundary)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
}));
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/portfolio'),
}));
jest.mock('react-native-image-picker', () => ({launchImageLibrary: jest.fn()}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () =>
    `11111111-1111-4111-8111-${String(++mockUuid).padStart(12, '0')}`,
}));

import {usePortfolioProjectDetails} from '../src/screens/Profile/gallery/usePortfolioProjectDetails';
import {
  toPortfolioProject,
  type Project,
} from '../src/screens/Profile/gallery/portfolioModel';
import {mapPortfolioItem} from '../src/services/api/portfolioContract';
import {
  listPortfolioMediaUploads,
  stagePortfolioMediaUpload,
} from '../src/services/portfolioMediaOutbox';
import {replayPendingPortfolioMediaUploads} from '../src/services/portfolioMediaReplay';

const media = {
  id: 71,
  file_type: 'image',
  status: 'ready',
  image_url: 'https://cdn.example.test/work.jpg',
};
const item = (ready = false) => ({
  id: 9,
  title: 'العمل الجديد',
  description: '',
  upload_state: ready ? 'ready' : 'uploading',
  uploaded_media_count: ready ? 1 : 0,
  expected_media_count: 1,
  media: ready ? [media] : [],
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const flush = async () => {
  for (let index = 0; index < 180; index += 1) await Promise.resolve();
};
const accessResponse = {data: {data: {can_upload: true}}};
const mountedRef = {current: true};
const captureBoundary = async () => mockBoundary;
const isCreateBusy = () => false;
const cancelLibraryLoad = jest.fn();
const setMutationBlocked = jest.fn();
const originalSet = (
  AsyncStorage.setItem as jest.Mock
).getMockImplementation()!;

describe('real portfolio selection, durable pause, transport abort and explicit resume', () => {
  let owner!: ReturnType<typeof usePortfolioProjectDetails>;
  let projects!: Project[];
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let files: Map<string, string>;
  let filePath: string;
  let key: string;
  const Harness = () => {
    const [library, setLibrary] = useState([
      toPortfolioProject(mapPortfolioItem(item())),
    ]);
    projects = library;
    owner = usePortfolioProjectDetails({
      cancelLibraryLoad,
      captureBoundary,
      isCreateBusy,
      mountedRef,
      setLibraryProjects: setLibrary,
      setMutationBlocked,
    });
    return null;
  };
  const stagePaused = async () => {
    await stagePortfolioMediaUpload(
      {
        projectId: '9',
        clientRequestId: '22222222-2222-4222-8222-222222222222',
        file: {uri: `file://${filePath}`, type: 'image/jpeg', size: 11},
        createdAt: Date.now(),
        paused: true,
      },
      mockBoundary,
    );
    await act(async () => {
      owner.retryPendingUploads();
      await flush();
    });
    expect(owner.selectedUploadPaused).toBe(true);
  };
  const holdImageRequest = () => {
    const started = deferred<AbortSignal>();
    mockPost.mockImplementation(
      (url: string, _body: unknown, options: {signal: AbortSignal}) => {
        if (!url.endsWith('/media'))
          return Promise.resolve({data: {data: item(true)}});
        return new Promise((_resolve, reject) => {
          started.resolve(options.signal);
          options.signal.addEventListener(
            'abort',
            () => reject(new Error('PORTFOLIO_UPLOAD_PAUSED')),
            {once: true},
          );
        });
      },
    );
    return started;
  };

  beforeEach(async () => {
    jest.clearAllMocks();
    mockBoundary = {
      epoch: mockBoundary.epoch + 1,
      scope: `portfolio-pause-${mockBoundary.epoch + 1}`,
    };
    mountedRef.current = true;
    (AsyncStorage.setItem as jest.Mock).mockImplementation(originalSet);
    await AsyncStorage.clear();
    files = new Map();
    filePath = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${mockBoundary.scope}/work.jpg`;
    files.set(filePath, 'image bytes');
    key = `@rokn/portfolio-media-outbox/v1:${mockBoundary.scope}`;
    (RNFS.exists as jest.Mock).mockImplementation(async path =>
      files.has(path),
    );
    (RNFS.stat as jest.Mock).mockImplementation(async path => ({
      isFile: () => true,
      size: (files.get(path) || '').length,
    }));
    (RNFS.readFile as jest.Mock).mockImplementation(
      async path => files.get(path) || '',
    );
    (RNFS.writeFile as jest.Mock).mockImplementation(async (path, value) => {
      files.set(path, value);
    });
    (RNFS.moveFile as jest.Mock).mockImplementation(async (from, to) => {
      files.set(to, files.get(from) || '');
      files.delete(from);
    });
    (RNFS.unlink as jest.Mock).mockImplementation(async path => {
      files.delete(path);
    });
    mockAccess.mockReset().mockResolvedValue(accessResponse);
    mockGet.mockReset().mockResolvedValue({data: {data: item()}});
    mockPost
      .mockReset()
      .mockImplementation(async (url: string) => ({
        data: {data: url.endsWith('/media') ? media : item(true)},
      }));
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    await act(async () => {
      owner.openProject(projects[0]);
      await flush();
    });
    expect(owner.pendingUploadsReady).toBe(true);
  });
  afterEach(async () => {
    mountedRef.current = false;
    await act(async () => renderer?.unmount());
    renderer = undefined;
    (AsyncStorage.setItem as jest.Mock).mockImplementation(originalSet);
    jest.restoreAllMocks();
  });

  it('Back stops the actual transfer, keeps its files and selection, and replays only after explicit resume', async () => {
    await stagePaused();
    await expect(replayPendingPortfolioMediaUploads()).resolves.toMatchObject({
      attempted: 0,
      completed: 0,
    });
    const started = holdImageRequest();
    let completion!: Promise<void>;
    await act(async () => {
      completion = owner.resumeSelectedUploads();
      await flush();
    });
    const signal = await started.promise;
    const uuid = mockPost.mock.calls[0][2].headers['Idempotency-Key'];
    expect(owner.canPauseSelectedUpload).toBe(true);
    expect((await listPortfolioMediaUploads('9', mockBoundary))[0].paused).toBe(
      false,
    );
    await act(async () => {
      owner.closeProject();
      await flush();
      await completion;
      await flush();
    });
    expect(signal.aborted).toBe(true);
    expect(owner.selected?.id).toBe('9');
    expect(owner.saving).toBe(false);
    expect(owner.selectedUploadPaused).toBe(true);
    expect(files.has(filePath)).toBe(true);
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(Alert.alert).not.toHaveBeenCalled();
    await replayPendingPortfolioMediaUploads();
    expect(mockPost).toHaveBeenCalledTimes(1);
    mockPost.mockImplementation(async (url: string) => ({
      data: {data: url.endsWith('/media') ? media : item(true)},
    }));
    await act(async () => {
      await owner.resumeSelectedUploads();
      await flush();
    });
    expect(mockPost.mock.calls[1][2].headers['Idempotency-Key']).toBe(uuid);
    expect(mockPost.mock.calls.map(([url]) => url)).toEqual([
      'portfolio/9/media',
      'portfolio/9/media',
      'portfolio/9/finalize',
    ]);
    expect(await listPortfolioMediaUploads('9', mockBoundary)).toEqual([]);
    expect(owner.hasPendingUploads).toBe(false);
    expect(owner.selected?.shareReady).toBe(true);
  });

  it('visibly guards Back while resume authorization is pending, then provides real pause when transport starts', async () => {
    await stagePaused();
    const access = deferred<typeof accessResponse>();
    mockAccess.mockReturnValueOnce(access.promise);
    const started = holdImageRequest();
    let completion!: Promise<void>;
    await act(async () => {
      completion = owner.resumeSelectedUploads();
      await flush();
    });
    expect(owner.preparingSelectedUpload).toBe(true);
    await act(async () => {
      owner.closeProject();
      await flush();
    });
    expect(owner.selected?.id).toBe('9');
    expect(JSON.parse((await AsyncStorage.getItem(key))!)[0].paused).toBe(true);
    expect(mockPost).not.toHaveBeenCalled();
    await act(async () => {
      access.resolve(accessResponse);
      await flush();
    });
    const signal = await started.promise;
    expect(owner.preparingSelectedUpload).toBe(false);
    await act(async () => {
      owner.closeProject();
      await completion;
      await flush();
    });
    expect(signal.aborted).toBe(true);
    expect(owner.selectedUploadPaused).toBe(true);
  });

  it('renders addition preparation even when library/detail loading already finished and picker has not opened', async () => {
    const access = deferred<typeof accessResponse>();
    mockAccess.mockReturnValueOnce(access.promise);
    jest.mocked(launchImageLibrary).mockResolvedValueOnce({didCancel: true});
    let completion!: Promise<void>;
    await act(async () => {
      completion = owner.addSelectedMedia();
      await flush();
    });
    expect(owner.detailLoading).toBe(false);
    expect(owner.saving).toBe(false);
    expect(owner.preparingSelectedUpload).toBe(true);
    await act(async () => {
      owner.closeProject();
      await flush();
    });
    expect(owner.selected?.id).toBe('9');
    expect(launchImageLibrary).not.toHaveBeenCalled();
    await act(async () => {
      access.resolve(accessResponse);
      await completion;
      await flush();
    });
    expect(owner.preparingSelectedUpload).toBe(false);
    expect(mockPost).not.toHaveBeenCalled();
  });

  it('does not unpause or start bytes after authorization completes for a torn-down selection', async () => {
    await stagePaused();
    const boundary = mockBoundary;
    const access = deferred<typeof accessResponse>();
    mockAccess.mockReturnValueOnce(access.promise);
    let completion!: Promise<void>;
    await act(async () => {
      completion = owner.resumeSelectedUploads();
      await flush();
    });
    await act(async () => {
      mountedRef.current = false;
      renderer!.unmount();
      renderer = undefined;
    });
    await act(async () => {
      access.resolve(accessResponse);
      await completion;
      await flush();
    });
    expect((await listPortfolioMediaUploads('9', boundary))[0].paused).toBe(
      true,
    );
    expect(mockPost).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it('keeps the actual transfer live if saving pause fails and permits a later Back retry', async () => {
    await stagePaused();
    const started = holdImageRequest();
    let completion!: Promise<void>;
    await act(async () => {
      completion = owner.resumeSelectedUploads();
      await flush();
    });
    const signal = await started.promise;
    let rejectPause = true;
    (AsyncStorage.setItem as jest.Mock).mockImplementation(
      async (storageKey, value) => {
        if (
          storageKey === key &&
          rejectPause &&
          JSON.parse(value).some((entry: {paused: boolean}) => entry.paused)
        )
          throw new Error('STORAGE_FULL');
        return originalSet(storageKey, value);
      },
    );
    await act(async () => {
      owner.closeProject();
      await flush();
    });
    expect(signal.aborted).toBe(false);
    expect(owner.canPauseSelectedUpload).toBe(true);
    expect(owner.selected?.id).toBe('9');
    expect(JSON.parse((await AsyncStorage.getItem(key))!)[0].paused).toBe(
      false,
    );
    expect(Alert.alert).toHaveBeenCalledWith(
      'تعذّر إيقاف الرفع',
      'الرفع مستمر\nحاول مرة أخرى',
    );
    rejectPause = false;
    await act(async () => {
      owner.closeProject();
      await completion;
      await flush();
    });
    expect(signal.aborted).toBe(true);
    expect(owner.selectedUploadPaused).toBe(true);
  });

  it('never starts transport after native staging completes for a torn-down selection', async () => {
    jest
      .mocked(launchImageLibrary)
      .mockResolvedValueOnce({
        assets: [{uri: `file://${filePath}`, type: 'image/jpeg', fileSize: 11}],
      });
    const staged = deferred<void>();
    const release = deferred<void>();
    let held = false;
    (AsyncStorage.setItem as jest.Mock).mockImplementation(
      async (storageKey, value) => {
        if (storageKey === key && !held) {
          held = true;
          staged.resolve();
          await release.promise;
        }
        return originalSet(storageKey, value);
      },
    );
    let completion!: Promise<void>;
    await act(async () => {
      completion = owner.addSelectedMedia();
      await flush();
    });
    await staged.promise;
    await act(async () => {
      mountedRef.current = false;
      renderer!.unmount();
      renderer = undefined;
    });
    await act(async () => {
      release.resolve();
      await completion;
      await flush();
    });
    expect(mockPost).not.toHaveBeenCalled();
    expect(await listPortfolioMediaUploads('9', mockBoundary)).toHaveLength(1);
    expect(files.has(filePath)).toBe(true);
  });
});
