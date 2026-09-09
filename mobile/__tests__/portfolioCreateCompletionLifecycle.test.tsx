import React, {useCallback, useState} from 'react';
import {Alert} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';
import TestRenderer, {act} from 'react-test-renderer';

const mockGet = jest.fn();
const mockPost = jest.fn();
let mockBoundary = {epoch: 1, scope: 'portfolio-create-1'};
let mockUuid = 0;
const mockCaptureBoundary = jest.fn(async () => mockBoundary);
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
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
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () =>
    `11111111-1111-4111-8111-${String(++mockUuid).padStart(12, '0')}`,
}));
jest.mock('react-native-image-picker', () => ({launchImageLibrary: jest.fn()}));

import {usePortfolioCreateFlow} from '../src/screens/Profile/gallery/usePortfolioCreateFlow';
import {usePortfolioPublication} from '../src/screens/Profile/gallery/usePortfolioPublication';
import {
  toPortfolioProject,
  type Project,
} from '../src/screens/Profile/gallery/portfolioModel';
import {
  readPortfolioEditorDraft,
  writePortfolioEditorDraft,
} from '../src/services/portfolioDraft';
import type {PortfolioItem} from '../src/services/api/portfolio';

const media = {
  id: 71,
  file_type: 'image',
  status: 'ready',
  image_url: 'https://cdn.example.test/work.jpg',
};
const item = (ready = false) => ({
  id: 9,
  title: 'العمل الجديد',
  description: 'الوصف المحفوظ',
  upload_state: ready ? 'ready' : 'uploading',
  expected_media_count: 1,
  uploaded_media_count: ready ? 1 : 0,
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
  for (let index = 0; index < 150; index += 1) await Promise.resolve();
};
const captureBoundary = () => mockCaptureBoundary();
const mountedRef = {current: true};
const busyRef = {current: false};
const isDetailBusy = () => false;
const cancelLibraryLoad = jest.fn();
const originalSet = (
  AsyncStorage.setItem as jest.Mock
).getMockImplementation()!;
const originalRemove = (
  AsyncStorage.removeItem as jest.Mock
).getMockImplementation()!;

describe('accepted portfolio creation versus editor draft cleanup', () => {
  let owner!: ReturnType<typeof usePortfolioCreateFlow>;
  let projects!: Project[];
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let files: Map<string, string>;
  let draftKey: string;
  let filePath: string;
  let draft: ReturnType<typeof savedDraft>;
  const savedDraft = () => ({
    clientRequestId: '22222222-2222-4222-8222-222222222222',
    title: 'العمل الجديد',
    summary: 'الوصف المحفوظ',
    media: [
      {uri: `file://${filePath}`, type: 'image/jpeg', fileName: 'work.jpg'},
    ],
    updatedAt: Date.now(),
  });
  const Harness = () => {
    const [library, setLibrary] = useState<Project[]>([]);
    projects = library;
    const commit = useCallback((value: PortfolioItem) => {
      setLibrary(current => [
        toPortfolioProject(value),
        ...current.filter(project => project.id !== value.id),
      ]);
    }, []);
    const publication = usePortfolioPublication({
      commit,
      isMutationActive: () => busyRef.current,
      mountedRef,
    });
    owner = usePortfolioCreateFlow({
      appActive: true,
      busyRef,
      cancelLibraryLoad,
      captureBoundary,
      finalizeAfterUpload: (id, boundary) =>
        publication.finalizeAfterUpload(id, boundary, {ownsMutation: true}),
      isDetailBusy,
      mountedRef,
      onMediaUploaded: jest.fn(),
      reconcileProject: jest.fn(async () => undefined),
      serverSession: true,
      setLibraryProjects: setLibrary,
    });
    return null;
  };

  beforeEach(async () => {
    jest.clearAllMocks();
    jest.useFakeTimers();
    mockBoundary = {
      epoch: mockBoundary.epoch + 1,
      scope: `portfolio-create-${mockBoundary.epoch + 1}`,
    };
    mountedRef.current = true;
    busyRef.current = false;
    mockCaptureBoundary
      .mockReset()
      .mockImplementation(async () => mockBoundary);
    (AsyncStorage.setItem as jest.Mock).mockImplementation(originalSet);
    (AsyncStorage.removeItem as jest.Mock).mockImplementation(originalRemove);
    await AsyncStorage.clear();
    files = new Map();
    (RNFS.exists as jest.Mock).mockImplementation(async path =>
      files.has(path),
    );
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
    (RNFS.stat as jest.Mock).mockImplementation(async path => ({
      isFile: () => true,
      size: (files.get(path) || '').length,
    }));
    draftKey = `@rokn/portfolio-editor-draft/v1:${mockBoundary.scope}`;
    filePath = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${mockBoundary.scope}/work.jpg`;
    files.set(filePath, 'image bytes');
    draft = savedDraft();
    await writePortfolioEditorDraft(draft, mockBoundary);
    mockGet.mockReset().mockResolvedValue({data: {data: []}});
    mockPost.mockReset().mockImplementation(async (url: string) => ({
      data: {
        data: url.endsWith('/media') ? media : item(url.endsWith('/finalize')),
      },
    }));
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    await act(async () => {
      owner.openAddProject();
      await flush();
    });
    expect(owner.draftReady).toBe(true);
    expect(owner.draftTitle).toBe(draft.title);
  });

  afterEach(async () => {
    mountedRef.current = false;
    await act(async () => renderer?.unmount());
    renderer = undefined;
    (AsyncStorage.setItem as jest.Mock).mockImplementation(originalSet);
    (AsyncStorage.removeItem as jest.Mock).mockImplementation(originalRemove);
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('finishes accepted create, upload and finalize while terminal editor-file unlink is stalled', async () => {
    const gate = deferred<void>();
    const started = deferred<void>();
    (RNFS.unlink as jest.Mock).mockImplementation(async path => {
      if (path === filePath) {
        started.resolve();
        await gate.promise;
      }
      files.delete(path);
    });
    let completion!: Promise<void>;
    try {
      await act(async () => {
        completion = owner.addProject();
        await flush();
      });
      await started.promise;
      expect(mockPost.mock.calls.map(([url]) => url)).toEqual([
        'portfolio',
        'portfolio/9/media',
        'portfolio/9/finalize',
      ]);
      expect(projects[0].shareReady).toBe(true);
      expect(await AsyncStorage.getItem(draftKey)).toBeNull();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1500);
      });
      expect(owner.saving).toBe(false);
      expect(owner.adding).toBe(false);
      expect(busyRef.current).toBe(false);
      expect(Alert.alert).not.toHaveBeenCalled();
    } finally {
      await act(async () => {
        gate.resolve();
        await completion;
        await flush();
      });
    }
  });

  it('keeps the original durable UUID, text and media when create has no successful acknowledgement', async () => {
    mockPost.mockRejectedValueOnce(new Error('NETWORK_ERROR'));
    await act(async () => {
      await owner.addProject();
      await flush();
    });
    expect(owner.saving).toBe(false);
    expect(owner.adding).toBe(true);
    expect(owner.draftTitle).toBe(draft.title);
    expect(owner.draftMediaAssets).toEqual(draft.media);
    expect(await readPortfolioEditorDraft(mockBoundary)).toEqual(draft);
    expect(files.has(filePath)).toBe(true);
    expect(mockPost).toHaveBeenCalledTimes(1);
  });

  it('orders retirement after an already-started draft write and before the next draft', async () => {
    const gate = deferred<void>();
    const started = deferred<void>();
    let blocked = false;
    (AsyncStorage.setItem as jest.Mock).mockImplementation(
      async (key, value) => {
        if (key === draftKey && !blocked) {
          blocked = true;
          started.resolve();
          await gate.promise;
        }
        return originalSet(key, value);
      },
    );
    await act(async () => {
      owner.updateDraftTitle('آخر تعديل للعمل المقبول');
    });
    await act(async () => {
      jest.advanceTimersByTime(250);
      await flush();
    });
    await started.promise;
    let completion!: Promise<void>;
    try {
      await act(async () => {
        completion = owner.addProject();
        await flush();
        await jest.advanceTimersByTimeAsync(1500);
      });
      expect(mockPost.mock.calls.map(([url]) => url)).toEqual([
        'portfolio',
        'portfolio/9/media',
        'portfolio/9/finalize',
      ]);
      expect(owner.saving).toBe(false);
      await act(async () => {
        owner.openAddProject();
        owner.updateDraftTitle('المسودة التالية');
      });
      await act(async () => {
        jest.advanceTimersByTime(300);
        await flush();
      });
      // The new draft may be edited, but it must not overtake raw native I/O.
      expect(
        JSON.parse((await AsyncStorage.getItem(draftKey)) || 'null').title,
      ).toBe(draft.title);
      await act(async () => {
        gate.resolve();
        await completion;
        await flush();
      });
      const restored = await readPortfolioEditorDraft(mockBoundary);
      expect(restored?.title).toBe('المسودة التالية');
      expect(restored?.clientRequestId).not.toBe(draft.clientRequestId);
      expect(owner.draftTitle).toBe('المسودة التالية');
      expect(owner.adding).toBe(true);
      expect(mockPost).toHaveBeenCalledTimes(3);
    } finally {
      await act(async () => {
        gate.resolve();
        await completion;
        await flush();
      });
    }
  });

  it('does not rehydrate the accepted draft on a new visit ahead of delayed retirement', async () => {
    const gate = deferred<void>();
    let blocked = false;
    (AsyncStorage.setItem as jest.Mock).mockImplementation(
      async (key, value) => {
        if (key === draftKey && !blocked) {
          blocked = true;
          await gate.promise;
        }
        return originalSet(key, value);
      },
    );
    await act(async () => {
      jest.advanceTimersByTime(250);
      await flush();
    });
    expect(blocked).toBe(true);
    let completion!: Promise<void>;
    try {
      await act(async () => {
        completion = owner.addProject();
        await flush();
        await jest.advanceTimersByTimeAsync(1500);
      });
      expect(owner.saving).toBe(false);
      await act(async () => {
        renderer!.unmount();
        renderer = TestRenderer.create(<Harness />);
        await flush();
      });
      expect(owner.draftReady).toBe(false);
      await act(async () => {
        gate.resolve();
        await completion;
        await flush();
      });
      expect(owner.draftReady).toBe(true);
      expect(owner.draftTitle).toBe('');
      expect(owner.draftMediaAssets).toEqual([]);
      expect(await readPortfolioEditorDraft(mockBoundary)).toBeNull();
    } finally {
      await act(async () => {
        gate.resolve();
        await completion;
        await flush();
      });
    }
  });

  it('does not report an accepted publication as failed when native draft removal rejects', async () => {
    (AsyncStorage.removeItem as jest.Mock).mockImplementation(async key => {
      if (key === draftKey) throw new Error('STORAGE_BUSY');
      return originalRemove(key);
    });
    await act(async () => {
      await owner.addProject();
      await flush();
    });
    expect(projects[0].shareReady).toBe(true);
    expect(owner.saving).toBe(false);
    expect(owner.adding).toBe(false);
    expect(Alert.alert).not.toHaveBeenCalled();
    // Failed local retirement preserves the original idempotent identity and
    // its file rather than claiming that the storage entry disappeared.
    expect(await readPortfolioEditorDraft(mockBoundary)).toEqual(draft);
    expect(files.has(filePath)).toBe(true);
    expect(mockPost).toHaveBeenCalledTimes(3);
  });

  it('does not clear editor work or show a finalization warning after account replacement', async () => {
    const finalize = deferred<{data: {data: ReturnType<typeof item>}}>();
    mockPost.mockImplementation(async (url: string) => {
      if (url.endsWith('/finalize')) return finalize.promise;
      return {data: {data: url.endsWith('/media') ? media : item()}};
    });
    let completion!: Promise<void>;
    await act(async () => {
      completion = owner.addProject();
      await flush();
    });
    expect(mockPost).toHaveBeenCalledTimes(3);
    mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
    await act(async () => {
      finalize.resolve({data: {data: item(true)}});
      await completion;
      await flush();
    });
    expect(owner.draftTitle).toBe(draft.title);
    expect(owner.draftMediaAssets).toEqual(draft.media);
    expect(
      JSON.parse((await AsyncStorage.getItem(draftKey)) || 'null'),
    ).toEqual(draft);
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it('retains the original editor when uploaded files could not enter the durable outbox', async () => {
    (AsyncStorage.setItem as jest.Mock).mockImplementation(
      async (key, value) => {
        if (key.includes('portfolio-media-outbox'))
          throw new Error('STORAGE_FULL');
        return originalSet(key, value);
      },
    );
    await act(async () => {
      await owner.addProject();
      await flush();
    });
    expect(owner.saving).toBe(false);
    expect(owner.adding).toBe(true);
    expect(owner.draftTitle).toBe(draft.title);
    expect(owner.draftMediaAssets).toEqual(draft.media);
    expect(await readPortfolioEditorDraft(mockBoundary)).toEqual(draft);
    expect(files.has(filePath)).toBe(true);
    expect(mockPost.mock.calls.map(([url]) => url)).toEqual(['portfolio']);
  });

  it('invalidates an old autosave still waiting for its boundary before it can reinsert the accepted draft', async () => {
    const captured = deferred<typeof mockBoundary>();
    mockCaptureBoundary.mockReturnValueOnce(captured.promise);
    await act(async () => {
      jest.advanceTimersByTime(250);
      await flush();
    });
    await act(async () => {
      await owner.addProject();
      await flush();
    });
    expect(owner.saving).toBe(false);
    expect(owner.adding).toBe(false);
    await act(async () => {
      captured.resolve(mockBoundary);
      await flush();
    });
    expect(await readPortfolioEditorDraft(mockBoundary)).toBeNull();
    expect(owner.draftTitle).toBe('');
    expect(mockPost).toHaveBeenCalledTimes(3);
  });
});
