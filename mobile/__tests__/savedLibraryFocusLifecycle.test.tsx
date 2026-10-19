import React from 'react';
import {Alert} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import TestRenderer, {act} from 'react-test-renderer';
import type {QueryClient} from '@tanstack/react-query';
import {
  createTestQueryClient,
  withAppQueryProvider,
  withQueryClient,
} from './helpers/queryClient';

let mockFocusEffect: (() => void | (() => void)) | null = null;
let mockFocusCleanup: (() => void) | undefined;
let mockUser = {id: 1, api_token: 'token'};
const scopeOf = () => `user-${mockUser.id}`;

const mockCreateSavedFolderOption = jest.fn();
const mockDeleteSavedFolderOption = jest.fn();
const mockGetSavedFolderOptions = jest.fn();
const mockRemoveLessonFromSavedFolder = jest.fn();
const mockGetSavedLessonsPage = jest.fn();
const mockGetSavedFolderLessonsPage = jest.fn();
const mockHasSession = jest.fn();
const mockAlert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});

jest.mock('@react-navigation/native', () => {
  const ReactModule = require('react') as typeof React;
  return {
    useFocusEffect: (effect: () => void | (() => void)) => {
      ReactModule.useEffect(() => {
        mockFocusEffect = effect;
        mockFocusCleanup = effect() || undefined;
        return () => {
          mockFocusCleanup?.();
          mockFocusCleanup = undefined;
        };
      }, [effect]);
    },
  };
});

jest.mock('react-redux', () => ({
  useSelector: (selector: (state: unknown) => unknown) =>
    selector({auth: {userData: mockUser}}),
}));

jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  assertAccountSessionBoundary: (boundary: {scope: string}) => {
    if (boundary.scope !== scopeOf()) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: scopeOf(),
  })),
  sessionIdentityKey: (user: {id: number}) => `user-${user.id}`,
}));

jest.mock('../src/components/VideoPlayer/courseLearning/savedFolderIndex', () => ({
  getSavedFolderOptions: (...args: unknown[]) =>
    mockGetSavedFolderOptions(...args),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  createSavedFolderOption: (...args: unknown[]) =>
    mockCreateSavedFolderOption(...args),
  deleteSavedFolderOption: (...args: unknown[]) =>
    mockDeleteSavedFolderOption(...args),
  removeLessonFromSavedFolder: (...args: unknown[]) =>
    mockRemoveLessonFromSavedFolder(...args),
}));

jest.mock('../src/services/networkExperience', () => ({
  friendlyNetworkMessage: () => 'تعذّر تحميل المحفوظات',
}));

jest.mock('../src/services/roknApi', () => ({
  getSavedLessonsPage: (...args: unknown[]) => mockGetSavedLessonsPage(...args),
  getSavedFolderLessonsPage: (...args: unknown[]) =>
    mockGetSavedFolderLessonsPage(...args),
  hasSession: (...args: unknown[]) => mockHasSession(...args),
}));

import {useSavedLibrary} from '../src/screens/savedLibrary/useSavedLibrary';
import {attachQueryPersistence} from '../src/services/query/queryPersistence';

const page = (pageNumber: number, hasMore = false) => ({
  hasMore,
  lessons: [
    {
      courseId: 'course-1',
      courseTitle: 'الكورس',
      duration: '01:00',
      folderId: 'watch-later',
      folderName: 'المشاهدة لاحقًا',
      id: `lesson-${pageNumber}`,
      title: `المقطع ${pageNumber}`,
    },
  ],
  page: pageNumber,
  total: hasMore ? 2 : 1,
});

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, reject, resolve};
};

/** Lets the account scope, the queries and their notifications settle. */
const settle = (ms = 10) =>
  act(async () => {
    await new Promise(resolve => setTimeout(resolve, ms));
  });

const blur = () => {
  const cleanup = mockFocusCleanup;
  mockFocusCleanup = undefined;
  cleanup?.();
};

const refocus = () => {
  mockFocusCleanup = mockFocusEffect?.() || undefined;
};

let client: QueryClient;

const mountLibrary = async (initialFolderId?: string) => {
  let library!: ReturnType<typeof useSavedLibrary>;
  const Harness = () => {
    library = useSavedLibrary(initialFolderId);
    return null;
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(withQueryClient(<Harness />, client));
  });
  await settle();
  return {
    get current() {
      return library;
    },
    renderer,
    Harness,
    rerender: () =>
      act(async () => {
        renderer.update(withQueryClient(<Harness />, client));
      }),
    unmount: () => act(async () => renderer.unmount()),
  };
};

const folderPage = (folderId: string, pageNumber = 1, hasMore = false) => ({
  ...page(pageNumber, hasMore),
  lessons: [{...page(pageNumber).lessons[0], folderId, folderName: 'قائمتي'}],
});

const confirmDelete = async (
  view: Awaited<ReturnType<typeof mountLibrary>>,
) => {
  act(() => view.current.deleteActiveFolder());
  const confirm = mockAlert.mock.calls
    .at(-1)?.[2]
    ?.find(button => button.style === 'destructive');
  await act(async () => {
    confirm?.onPress?.();
  });
  await settle();
};

describe('saved library focus lifecycle', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    client = createTestQueryClient();
    mockUser = {id: 1, api_token: 'token'};
    mockFocusEffect = null;
    mockFocusCleanup = undefined;
    mockHasSession.mockResolvedValue(true);
    mockGetSavedFolderOptions.mockResolvedValue([]);
    mockDeleteSavedFolderOption.mockResolvedValue(undefined);
    mockRemoveLessonFromSavedFolder.mockResolvedValue(undefined);
    mockGetSavedLessonsPage.mockResolvedValue(page(1));
    mockGetSavedFolderLessonsPage.mockImplementation(
      async (folderId: string) => folderPage(folderId),
    );
  });

  it('clears the folder editor when the account changes without remounting the screen', async () => {
    const view = await mountLibrary();
    try {
      act(() => {
        view.current.toggleCreateFolder();
        view.current.setNewFolderName('مسودة للحساب السابق');
      });
      expect(view.current.showCreateFolder).toBe(true);
      mockUser = {id: 2, api_token: 'other-token'};
      await view.rerender();
      await settle();
      expect(view.current.newFolderName).toBe('');
      expect(view.current.showCreateFolder).toBe(false);
      expect(mockCreateSavedFolderOption).not.toHaveBeenCalled();
    } finally {
      await view.unmount();
    }
  });

  it('opens on the list a ركني card asked for and reads only that list', async () => {
    const view = await mountLibrary('7');
    try {
      expect(view.current.activeFolderId).toBe('7');
      expect(mockGetSavedFolderLessonsPage).toHaveBeenCalledWith('7', 1);
      expect(mockGetSavedLessonsPage).not.toHaveBeenCalled();
      expect(view.current.visibleSaved.map(item => item.folderId)).toEqual([
        '7',
      ]);
      // A later account does not inherit the previous learner's list.
      mockUser = {id: 2, api_token: 'other-token'};
      await view.rerender();
      await settle();
      expect(view.current.activeFolderId).toBe('all');
    } finally {
      await view.unmount();
    }
  });

  it('opens on all saved lessons without a requested list', async () => {
    const view = await mountLibrary();
    try {
      expect(view.current.activeFolderId).toBe('all');
      expect(mockGetSavedLessonsPage).toHaveBeenCalledWith(1);
      expect(mockGetSavedFolderLessonsPage).not.toHaveBeenCalled();
      expect(view.current.saved.map(item => item.id)).toEqual(['lesson-1']);
      expect(view.current.loading).toBe(false);
    } finally {
      await view.unmount();
    }
  });

  it('never shows the previous account’s saved lessons after a switch', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمة الأول', lessonsCount: 1},
    ]);
    const frames: Array<{saved: string[]; folders: string[]}> = [];
    let library!: ReturnType<typeof useSavedLibrary>;
    const Harness = () => {
      library = useSavedLibrary();
      frames.push({
        saved: library.saved.map(item => item.id),
        folders: library.folderOptions.map(folder => folder.id),
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(withQueryClient(<Harness />, client));
    });
    await settle();
    // The index list plus the list inferred from the saved rows.
    expect(frames.at(-1)).toEqual({
      saved: ['lesson-1'],
      folders: ['7', 'watch-later'],
    });

    // The next account's reads are still in flight.
    const nextPage = deferred<ReturnType<typeof page>>();
    const nextFolders = deferred<Array<{id: string; name: string}>>();
    mockGetSavedLessonsPage.mockReturnValue(nextPage.promise);
    mockGetSavedFolderOptions.mockReturnValue(nextFolders.promise);
    mockUser = {id: 2, api_token: 'other-token'};
    const switchedAt = frames.length;
    await act(async () => {
      renderer.update(withQueryClient(<Harness />, client));
    });
    await settle();
    const afterSwitch = frames.slice(switchedAt);
    expect(afterSwitch.length).toBeGreaterThan(0);
    afterSwitch.forEach(frame => {
      expect(frame.saved).not.toContain('lesson-1');
      expect(frame.folders).not.toContain('7');
    });
    expect(client.getQueryCache().findAll({queryKey: ['user-1']})).toHaveLength(
      0,
    );
    await act(async () => {
      nextPage.resolve({
        ...page(1),
        lessons: [{...page(1).lessons[0], id: 'lesson-2', folderId: '9'}],
      });
      nextFolders.resolve([{id: '9', name: 'قائمة الثاني'}]);
    });
    await settle();
    expect(frames.at(-1)).toEqual({saved: ['lesson-2'], folders: ['9']});
    expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(2);
    await act(async () => renderer.unmount());
  });

  it('retries folder reads remotely, keeps lessons on repeated failure, and restores offline fallback on refocus', async () => {
    mockGetSavedFolderOptions.mockRejectedValueOnce(new Error('offline'));
    const view = await mountLibrary();
    try {
      expect(view.current.error).toBe('');
      expect(view.current.folderLoadError).not.toBe('');
      expect(view.current.saved).toHaveLength(1);
      mockGetSavedFolderOptions.mockRejectedValueOnce(new Error('offline'));
      act(() => view.current.retry());
      await settle();
      expect(mockGetSavedFolderOptions).toHaveBeenLastCalledWith({
        requireFresh: true,
      });
      expect(view.current.error).toBe('');
      expect(view.current.folderLoadError).not.toBe('');
      expect(view.current.saved).toHaveLength(1);
      act(() => view.current.retry());
      await settle();
      expect(view.current.folderLoadError).toBe('');
      expect(mockGetSavedFolderOptions).toHaveBeenLastCalledWith({
        requireFresh: true,
      });
      await act(async () => {
        blur();
        refocus();
      });
      await settle();
      expect(mockGetSavedFolderOptions).toHaveBeenLastCalledWith(undefined);
      expect(mockRemoveLessonFromSavedFolder).not.toHaveBeenCalled();
    } finally {
      await view.unmount();
    }
  });

  it('opens an older folder independently of the latest twenty global saves', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'القديمة', lessonsCount: 1},
      {id: '8', name: 'الجديدة', lessonsCount: 20},
    ]);
    mockGetSavedLessonsPage.mockResolvedValue({
      ...page(1, true),
      lessons: Array.from({length: 20}, (_, index) => ({
        ...page(1).lessons[0],
        id: `new-${index}`,
        folderId: '8',
        folderName: 'الجديدة',
      })),
      total: 21,
    });
    mockGetSavedFolderLessonsPage.mockResolvedValue({
      ...page(1),
      lessons: [{...page(1).lessons[0], id: 'old', folderId: '7'}],
    });
    const view = await mountLibrary();
    try {
      expect(view.current.folderCounts.get('7')).toBe(1);
      expect(view.current.folderCounts.get('8')).toBe(20);
      act(() => view.current.selectFolder('7'));
      await settle();
      expect(mockGetSavedFolderLessonsPage).toHaveBeenCalledWith('7', 1);
      expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(1);
      expect(view.current.visibleSaved.map(item => item.id)).toEqual(['old']);
      expect(view.current.nextPage).toBeNull();
    } finally {
      await view.unmount();
    }
  });

  it.each(['success', 'failure'])(
    'applies a removal before a pending refresh can restore the row and settles it (%s)',
    async outcome => {
      const folders = [{id: '7', name: 'قائمتي', lessonsCount: 1}];
      mockGetSavedFolderOptions.mockResolvedValue(folders);
      mockGetSavedLessonsPage.mockResolvedValue(folderPage('7'));
      const view = await mountLibrary();
      const removal = deferred<void>();
      mockRemoveLessonFromSavedFolder.mockReturnValueOnce(removal.promise);
      try {
        act(() => view.current.selectFolder('7'));
        await settle();
        const oldPage = deferred<ReturnType<typeof page>>();
        const oldIndex = deferred<typeof folders>();
        mockGetSavedFolderLessonsPage.mockReturnValueOnce(oldPage.promise);
        mockGetSavedFolderOptions.mockReturnValueOnce(oldIndex.promise);
        act(() => view.current.retry());
        await settle();
        expect(view.current.loading).toBe(true);
        const item = view.current.saved[0];
        await act(async () => {
          void view.current.removeSaved(item);
        });
        await settle();
        expect(view.current.saved).toEqual([]);
        expect(view.current.folderCounts.get('7')).toBe(0);
        expect(view.current.removingSaved.has('7:lesson-1')).toBe(true);
        // The pre-removal reads were cancelled: their answers cannot land.
        await act(async () => {
          oldPage.resolve(folderPage('7'));
          oldIndex.resolve(folders);
        });
        await settle();
        expect(view.current.saved).toEqual([]);
        expect(view.current.folderCounts.get('7')).toBe(0);
        const readsBefore = mockGetSavedFolderLessonsPage.mock.calls.length;
        await act(async () => {
          if (outcome === 'success') removal.resolve();
          else removal.reject(new Error('offline'));
        });
        await settle();
        expect(mockRemoveLessonFromSavedFolder).toHaveBeenCalledTimes(1);
        expect(view.current.removingSaved.size).toBe(0);
        if (outcome === 'success') {
          expect(view.current.saved).toEqual([]);
          expect(view.current.folderCounts.get('7')).toBe(0);
          expect(view.current.actionError).toBe('');
          expect(mockGetSavedFolderLessonsPage.mock.calls.length).toBe(
            readsBefore,
          );
        } else {
          // The row and count return, and the interrupted reads resume.
          expect(view.current.saved).toHaveLength(1);
          expect(view.current.actionError).toBe(
            'تعذّرت إزالة المقطع\nجرب تاني',
          );
          expect(mockGetSavedFolderLessonsPage.mock.calls.length).toBe(
            readsBefore + 1,
          );
          expect(view.current.folderCounts.get('7')).toBe(1);
        }
      } finally {
        await view.unmount();
      }
    },
  );

  it.each(['success', 'failure'])(
    'removes a page-two row optimistically and puts it back in place on failure (%s)',
    async outcome => {
      const folders = [
        {id: '7', name: 'القديمة', lessonsCount: 1},
        {id: '8', name: 'الجديدة', lessonsCount: 20},
      ];
      const firstPage = {
        ...page(1, true),
        lessons: Array.from({length: 20}, (_, index) => ({
          ...page(1).lessons[0],
          id: `new-${index}`,
          folderId: '8',
          folderName: 'الجديدة',
        })),
        total: 21,
      };
      const oldItem = {
        ...page(2).lessons[0],
        id: 'old',
        folderId: '7',
        folderName: 'القديمة',
      };
      mockGetSavedFolderOptions.mockResolvedValue(folders);
      mockGetSavedLessonsPage.mockImplementation(async (requestedPage = 1) =>
        requestedPage === 1
          ? firstPage
          : {...page(2), lessons: [oldItem], total: 21},
      );
      const view = await mountLibrary();
      const removal = deferred<void>();
      mockRemoveLessonFromSavedFolder.mockReturnValueOnce(removal.promise);
      try {
        await act(async () => {
          await view.current.loadMore();
        });
        expect(mockGetSavedLessonsPage).toHaveBeenLastCalledWith(2);
        expect(view.current.saved).toHaveLength(21);
        await act(async () => {
          void view.current.removeSaved(oldItem);
        });
        await settle();
        expect(view.current.saved).toHaveLength(20);
        expect(view.current.folderCounts.get('7')).toBe(0);
        expect(view.current.folderCounts.get('8')).toBe(20);
        await act(async () => {
          if (outcome === 'success') removal.resolve();
          else removal.reject(new Error('offline'));
        });
        await settle();
        expect(mockRemoveLessonFromSavedFolder).toHaveBeenCalledTimes(1);
        expect(view.current.removingSaved.size).toBe(0);
        expect(view.current.saved.some(item => item.id === 'old')).toBe(
          outcome === 'failure',
        );
        if (outcome === 'failure') {
          expect(view.current.saved.at(-1)?.id).toBe('old');
        }
        expect(view.current.folderCounts.get('7')).toBe(
          outcome === 'success' ? 0 : 1,
        );
        expect(view.current.folderCounts.get('8')).toBe(20);
        expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(2);
      } finally {
        await view.unmount();
      }
    },
  );

  it.each(['success', 'failure'])(
    'deletes a list optimistically while a refresh is pending and settles it (%s)',
    async outcome => {
      const folders = [{id: '7', name: 'قائمتي', lessonsCount: 1}];
      mockGetSavedFolderOptions.mockResolvedValue(folders);
      mockGetSavedLessonsPage.mockResolvedValue(folderPage('7'));
      const view = await mountLibrary();
      const deletion = deferred<void>();
      mockDeleteSavedFolderOption.mockReturnValueOnce(deletion.promise);
      try {
        act(() => view.current.selectFolder('7'));
        await settle();
        const oldPage = deferred<ReturnType<typeof page>>();
        const oldIndex = deferred<typeof folders>();
        mockGetSavedFolderLessonsPage.mockReturnValueOnce(oldPage.promise);
        mockGetSavedFolderOptions.mockReturnValueOnce(oldIndex.promise);
        act(() => view.current.retry());
        await settle();
        await confirmDelete(view);
        expect(view.current.folderOptions).toEqual([]);
        expect(view.current.deletingFolder).toBe(true);
        await act(async () => {
          oldPage.resolve(folderPage('7'));
          oldIndex.resolve(folders);
        });
        await settle();
        expect(view.current.folderOptions).toEqual([]);
        // Keep the following all-view refresh unresolved: the confirmed local
        // deletion must stand on its own, not depend on another successful GET.
        const allRead = deferred<ReturnType<typeof page>>();
        mockGetSavedLessonsPage.mockReturnValueOnce(allRead.promise);
        await act(async () => {
          if (outcome === 'success') deletion.resolve();
          else deletion.reject(new Error('offline'));
        });
        await settle();
        expect(mockDeleteSavedFolderOption).toHaveBeenCalledTimes(1);
        expect(view.current.activeFolderId).toBe(
          outcome === 'success' ? 'all' : '7',
        );
        expect(view.current.folderOptions).toHaveLength(
          outcome === 'success' ? 0 : 1,
        );
        expect(view.current.saved).toHaveLength(outcome === 'success' ? 0 : 1);
        expect(view.current.deletingFolder).toBe(false);
        if (outcome === 'failure') {
          expect(view.current.folderError).toBe(
            'تعذّر حذف القائمة\nاتأكد من النت وجرب تاني',
          );
        }
      } finally {
        await view.unmount();
      }
    },
  );

  it('paginates only the selected folder and retries its failed next page without losing loaded items', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمتي', lessonsCount: 21},
    ]);
    mockGetSavedLessonsPage.mockResolvedValue(page(1, true));
    mockGetSavedFolderLessonsPage.mockResolvedValue(folderPage('7', 1, true));
    const view = await mountLibrary();
    try {
      act(() => view.current.selectFolder('7'));
      await settle();
      mockGetSavedFolderLessonsPage.mockRejectedValueOnce(new Error('offline'));
      await act(async () => {
        await view.current.loadMore();
      });
      await settle();
      expect(view.current.saved.map(item => item.id)).toEqual(['lesson-1']);
      expect(view.current.nextPage).toBe(2);
      expect(view.current.loadMoreError).not.toBe('');
      expect(view.current.error).toBe('');
      mockGetSavedFolderLessonsPage.mockResolvedValueOnce(folderPage('7', 2));
      await act(async () => {
        await view.current.loadMore();
      });
      await settle();
      expect(mockGetSavedFolderLessonsPage).toHaveBeenLastCalledWith('7', 2);
      expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(1);
      expect(view.current.saved.map(item => item.id)).toEqual([
        'lesson-1',
        'lesson-2',
      ]);
      expect(view.current.loadMoreError).toBe('');
      expect(view.current.nextPage).toBeNull();
    } finally {
      await view.unmount();
    }
  });

  it('keeps a late page of another list out of the selected list', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'الأولى', lessonsCount: 2},
      {id: '8', name: 'الثانية', lessonsCount: 1},
    ]);
    const oldGlobal = deferred<ReturnType<typeof page>>();
    const oldFolder = deferred<ReturnType<typeof page>>();
    mockGetSavedLessonsPage.mockImplementation((number = 1) =>
      number === 2 ? oldGlobal.promise : Promise.resolve(page(1, true)),
    );
    mockGetSavedFolderLessonsPage.mockImplementation(
      (id: string, number: number) =>
        id === '7' && number === 2
          ? oldFolder.promise
          : Promise.resolve(folderPage(id, 1, id === '7')),
    );
    const view = await mountLibrary();
    try {
      await act(async () => {
        void view.current.loadMore();
      });
      act(() => view.current.selectFolder('7'));
      await settle();
      await act(async () => {
        oldGlobal.resolve(page(2));
      });
      await settle();
      expect(view.current.saved.every(item => item.folderId === '7')).toBe(
        true,
      );
      await act(async () => {
        void view.current.loadMore();
      });
      act(() => view.current.selectFolder('8'));
      await settle();
      await act(async () => {
        oldFolder.resolve(folderPage('7', 2));
      });
      await settle();
      expect(view.current.saved.map(item => item.folderId)).toEqual(['8']);
      expect(view.current.loadingMore).toBe(false);
      expect(view.current.nextPage).toBeNull();
    } finally {
      await view.unmount();
    }
  });

  it('keeps folder failures retryable and ignores a late prior-account folder read', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمتي', lessonsCount: 1},
    ]);
    mockGetSavedFolderLessonsPage.mockRejectedValueOnce(new Error('offline'));
    const view = await mountLibrary();
    try {
      act(() => view.current.selectFolder('7'));
      await settle();
      expect(view.current.saved).toEqual([]);
      expect(view.current.folderCounts.get('7')).toBe(1);
      expect(view.current.error).not.toBe('');
      expect(view.current.loading).toBe(false);
      const oldRead = deferred<ReturnType<typeof page>>();
      mockGetSavedFolderLessonsPage.mockReturnValueOnce(oldRead.promise);
      act(() => view.current.retry());
      await settle();
      mockUser = {id: 2, api_token: 'other-token'};
      mockGetSavedFolderOptions.mockResolvedValue([]);
      mockGetSavedLessonsPage.mockResolvedValue({...page(1), lessons: []});
      await view.rerender();
      await settle();
      await act(async () => {
        oldRead.resolve(folderPage('7'));
      });
      await settle();
      expect(view.current.activeFolderId).toBe('all');
      expect(view.current.saved).toEqual([]);
      expect(view.current.folderOptions).toEqual([]);
      expect(client.getQueryData(['user-1', 'saved-lessons', '7'])).toBeUndefined();
    } finally {
      await view.unmount();
    }
  });

  it('returns to all when the selected folder disappears, even with a stale index', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمتي', lessonsCount: 1},
    ]);
    const view = await mountLibrary();
    try {
      mockGetSavedFolderLessonsPage.mockRejectedValueOnce({
        response: {status: 404},
      });
      act(() => view.current.selectFolder('7'));
      await settle();
      expect(view.current.activeFolderId).toBe('all');
      expect(view.current.error).toBe('');
      expect(view.current.folderOptions.map(folder => folder.id)).toEqual([
        'watch-later',
      ]);
      expect(view.current.saved.map(item => item.id)).toEqual(['lesson-1']);
    } finally {
      await view.unmount();
    }
  });

  it('keeps a successfully loaded folder when an older cached index omits it', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمتي', lessonsCount: 1},
    ]);
    const view = await mountLibrary();
    try {
      act(() => view.current.selectFolder('7'));
      await settle();
      mockGetSavedFolderOptions.mockResolvedValue([]);
      act(() => view.current.retry());
      await settle();
      expect(view.current.activeFolderId).toBe('7');
      expect(view.current.folderOptions).toEqual([
        {id: '7', name: 'قائمتي', lessonsCount: 1},
      ]);
      expect(view.current.visibleSaved).toHaveLength(1);
      expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(1);
    } finally {
      await view.unmount();
    }
  });

  it('rolls back a failed folder deletion in its scope and loads all after successful deletion', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمتي', lessonsCount: 1},
    ]);
    mockGetSavedLessonsPage.mockResolvedValue({...page(1), lessons: []});
    const view = await mountLibrary();
    try {
      act(() => view.current.selectFolder('7'));
      await settle();
      const deletion = deferred<void>();
      mockDeleteSavedFolderOption.mockReturnValueOnce(deletion.promise);
      await confirmDelete(view);
      expect(view.current.activeFolderId).toBe('7');
      expect(view.current.saved).toEqual([]);
      await act(async () => {
        deletion.reject(new Error('offline'));
      });
      await settle();
      expect(view.current.visibleSaved).toHaveLength(1);
      expect(view.current.folderCounts.get('7')).toBe(1);
      expect(view.current.folderError).not.toBe('');
      mockGetSavedFolderOptions.mockResolvedValue([]);
      await confirmDelete(view);
      expect(view.current.activeFolderId).toBe('all');
      expect(view.current.folderOptions).toEqual([]);
      expect(view.current.saved).toEqual([]);
      expect(view.current.deletingFolder).toBe(false);
    } finally {
      await view.unmount();
    }
  });

  it.each(['success', 'failure'])(
    'owns an unchanged optimistic folder total without an extra metadata read (%s)',
    async outcome => {
      mockGetSavedFolderOptions.mockResolvedValue([
        {id: '7', name: 'قائمتي', lessonsCount: 21},
      ]);
      mockGetSavedFolderLessonsPage.mockResolvedValue({
        ...folderPage('7', 1, true),
        total: 21,
      });
      const removal = deferred<void>();
      mockRemoveLessonFromSavedFolder.mockReturnValueOnce(removal.promise);
      const view = await mountLibrary();
      try {
        act(() => view.current.selectFolder('7'));
        await settle();
        await act(async () => {
          void view.current.removeSaved(view.current.saved[0]);
        });
        await settle();
        expect(view.current.folderCounts.get('7')).toBe(20);
        await act(async () => {
          if (outcome === 'success') removal.resolve();
          else removal.reject(new Error('offline'));
        });
        await settle();
        expect(view.current.folderCounts.get('7')).toBe(
          outcome === 'success' ? 20 : 21,
        );
        expect(view.current.saved).toHaveLength(outcome === 'success' ? 0 : 1);
        expect(mockGetSavedFolderOptions).toHaveBeenCalledTimes(1);
        expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(1);
        expect(mockGetSavedFolderLessonsPage).toHaveBeenCalledTimes(1);
        expect(mockRemoveLessonFromSavedFolder).toHaveBeenCalledTimes(1);
      } finally {
        await view.unmount();
      }
    },
  );

  it('releases an interrupted pagination state when the screen returns', async () => {
    const secondPage = deferred<ReturnType<typeof page>>();
    mockGetSavedLessonsPage.mockImplementation((requestedPage = 1) =>
      requestedPage === 2 ? secondPage.promise : Promise.resolve(page(1, true)),
    );
    const view = await mountLibrary();
    try {
      await act(async () => {
        void view.current.loadMore();
      });
      await settle();
      expect(view.current.loadingMore).toBe(true);
      await act(async () => {
        blur();
        refocus();
      });
      await settle();
      expect(view.current.loadingMore).toBe(false);
      await act(async () => {
        secondPage.resolve(page(2));
      });
      await settle();
      expect(view.current.loadingMore).toBe(false);
      expect(view.current.saved.map(item => item.id)).toEqual(['lesson-1']);
      expect(view.current.nextPage).toBe(2);
    } finally {
      await view.unmount();
    }
  });

  it('reconciles a folder created while the screen was away without unlocking a duplicate write', async () => {
    const creation = deferred<{id: string; name: string}>();
    let serverFolders: Array<{id: string; name: string}> = [];
    mockGetSavedFolderOptions.mockImplementation(async () => serverFolders);
    mockCreateSavedFolderOption.mockImplementation(() =>
      creation.promise.then(folder => {
        serverFolders = [folder];
        return folder;
      }),
    );
    const view = await mountLibrary();
    try {
      act(() => view.current.setNewFolderName('قائمتي'));
      await act(async () => {
        void view.current.createFolder();
      });
      await settle();
      expect(view.current.creatingFolder).toBe(true);
      await act(async () => {
        blur();
        refocus();
        void view.current.createFolder();
      });
      await settle();
      expect(mockCreateSavedFolderOption).toHaveBeenCalledTimes(1);
      await act(async () => {
        creation.resolve({id: 'folder-1', name: 'قائمتي'});
      });
      await settle();
      expect(view.current.creatingFolder).toBe(false);
      expect(view.current.activeFolderId).toBe('folder-1');
      expect(view.current.folderOptions).toEqual(
        expect.arrayContaining([
          expect.objectContaining({id: 'folder-1', name: 'قائمتي'}),
        ]),
      );
    } finally {
      await view.unmount();
    }
  });

  it('derives idle mutation state when a folder write settles while blurred', async () => {
    const creation = deferred<{id: string; name: string}>();
    let serverFolders: Array<{id: string; name: string}> = [];
    mockGetSavedFolderOptions.mockImplementation(async () => serverFolders);
    mockCreateSavedFolderOption.mockImplementation(() =>
      creation.promise.then(folder => {
        serverFolders = [folder];
        return folder;
      }),
    );
    const view = await mountLibrary();
    try {
      act(() => view.current.setNewFolderName('قائمتي'));
      await act(async () => {
        void view.current.createFolder();
      });
      await settle();
      await act(async () => {
        blur();
        creation.resolve({id: 'folder-1', name: 'قائمتي'});
      });
      await settle();
      await act(async () => {
        refocus();
      });
      await settle();
      expect(view.current.creatingFolder).toBe(false);
      expect(view.current.folderOptions).toEqual(
        expect.arrayContaining([
          expect.objectContaining({id: 'folder-1', name: 'قائمتي'}),
        ]),
      );
    } finally {
      await view.unmount();
    }
  });

  it('keeps a failed removal single-flight and restores the item after returning', async () => {
    const removal = deferred<void>();
    mockRemoveLessonFromSavedFolder.mockReturnValue(removal.promise);
    const view = await mountLibrary();
    try {
      const savedLesson = view.current.saved[0];
      await act(async () => {
        void view.current.removeSaved(savedLesson);
      });
      await settle();
      expect(view.current.saved).toEqual([]);
      await act(async () => {
        blur();
        refocus();
      });
      await settle();
      expect(view.current.removingSaved.has('watch-later:lesson-1')).toBe(
        true,
      );
      await act(async () => {
        void view.current.removeSaved(savedLesson);
      });
      await settle();
      expect(mockRemoveLessonFromSavedFolder).toHaveBeenCalledTimes(1);
      await act(async () => {
        removal.reject(new Error('offline'));
      });
      await settle();
      expect(view.current.removingSaved.size).toBe(0);
      expect(view.current.saved.map(item => item.id)).toEqual(['lesson-1']);
      expect(view.current.actionError).toBe('تعذّرت إزالة المقطع\nجرب تاني');
    } finally {
      await view.unmount();
    }
  });

  it('recovers a created folder when the server committed but the response was lost', async () => {
    let serverFolders: Array<{id: string; name: string}> = [];
    mockGetSavedFolderOptions.mockImplementation(async () => serverFolders);
    mockCreateSavedFolderOption.mockImplementation(async () => {
      serverFolders = [{id: 'folder-1', name: 'قائمتي'}];
      throw new Error('response lost');
    });
    const view = await mountLibrary();
    try {
      act(() => view.current.setNewFolderName('قائمتي'));
      await act(async () => {
        await view.current.createFolder();
      });
      await settle();
      expect(view.current.creatingFolder).toBe(false);
      expect(view.current.folderError).not.toBe('');
      // The next focus reads the index again and finds the committed list.
      await act(async () => {
        blur();
        refocus();
      });
      await settle();
      expect(view.current.folderOptions).toEqual(
        expect.arrayContaining([
          expect.objectContaining({id: 'folder-1', name: 'قائمتي'}),
        ]),
      );
    } finally {
      await view.unmount();
    }
  });

  it('recovers a deleted folder when the server committed but the response was lost', async () => {
    let folderExists = true;
    mockGetSavedFolderLessonsPage.mockImplementation(
      async (folderId: string) => {
        if (!folderExists) throw {response: {status: 404}};
        return folderPage(folderId);
      },
    );
    mockGetSavedFolderOptions.mockImplementation(async () =>
      folderExists ? [{id: 'folder-1', name: 'قائمتي'}] : [],
    );
    mockGetSavedLessonsPage.mockImplementation(async () => ({
      ...page(1),
      lessons: folderExists
        ? [{...page(1).lessons[0], folderId: 'folder-1', folderName: 'قائمتي'}]
        : [],
      total: folderExists ? 1 : 0,
    }));
    mockDeleteSavedFolderOption.mockImplementation(async () => {
      folderExists = false;
      throw new Error('response lost');
    });
    const view = await mountLibrary();
    try {
      act(() => view.current.selectFolder('folder-1'));
      await settle();
      await confirmDelete(view);
      expect(mockDeleteSavedFolderOption).toHaveBeenCalledTimes(1);
      expect(view.current.deletingFolder).toBe(false);
      // Rolled back locally, then the next focus learns the list is gone.
      expect(view.current.activeFolderId).toBe('folder-1');
      await act(async () => {
        blur();
        refocus();
      });
      await settle();
      expect(view.current.activeFolderId).toBe('all');
      expect(view.current.folderOptions).toEqual([]);
      expect(view.current.saved).toEqual([]);
    } finally {
      await view.unmount();
    }
  });

  it('shows the signed-out state without reading any list', async () => {
    mockHasSession.mockResolvedValue(false);
    const view = await mountLibrary();
    try {
      expect(view.current.serverSession).toBe(false);
      expect(view.current.loading).toBe(false);
      expect(mockGetSavedLessonsPage).not.toHaveBeenCalled();
      expect(mockGetSavedFolderOptions).not.toHaveBeenCalled();
    } finally {
      await view.unmount();
    }
  });
});

describe('saved library offline cold start', () => {
  const offline = () =>
    Object.assign(new Error('Network Error'), {code: 'ERR_NETWORK'});

  /** What a previous process left on the device for `scope`. */
  const seedDeviceSnapshot = async (
    scope: string,
    lessons: ReturnType<typeof page>['lessons'],
  ) => {
    const previous = createTestQueryClient();
    const persistence = attachQueryPersistence(previous, {throttleTime: 0});
    await persistence.restorePublic();
    await persistence.activateAccountScope(scope);
    previous.setQueryData([scope, 'saved-lessons', 'all'], {
      pages: [{lessons, page: 1, hasMore: true, total: 40}],
      pageParams: [1],
    });
    await new Promise(resolve => setTimeout(resolve, 60));
    persistence.dispose();
    previous.clear();
  };

  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    client = createTestQueryClient();
    mockUser = {id: 1, api_token: 'token'};
    mockHasSession.mockResolvedValue(true);
  });

  it('shows the saved copy of all saved lessons with its offline note', async () => {
    await seedDeviceSnapshot('user-1', page(1).lessons);
    mockGetSavedLessonsPage.mockImplementation(
      () =>
        new Promise((_resolve, reject) =>
          setTimeout(() => reject(offline()), 30),
        ),
    );
    mockGetSavedFolderOptions.mockRejectedValue(offline());
    let library!: ReturnType<typeof useSavedLibrary>;
    const errors: string[] = [];
    const Screen = () => {
      library = useSavedLibrary();
      errors.push(library.error);
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Screen />, client, 'user-1'),
      );
    });
    await settle(15);
    // Restored before the network answered: shown at once, no "عرض المزيد".
    expect(library.saved.map(item => item.id)).toEqual(['lesson-1']);
    expect(library.nextPage).toBeNull();
    await settle(80);
    expect(library.saved.map(item => item.id)).toEqual(['lesson-1']);
    expect(library.error).toBe(
      'نعرض آخر محفوظات متاحة\nأعد المحاولة عند عودة الاتصال',
    );
    expect(library.folderLoadError).toBe(
      'تعذّر تحديث القوائم\nالمحفوظات ما زالت موجودة',
    );
    // No frame ever showed the bare network error instead of the copy.
    errors.forEach(error => {
      expect(
        error === '' ||
          error === 'نعرض آخر محفوظات متاحة\nأعد المحاولة عند عودة الاتصال',
      ).toBe(true);
    });
    await act(async () => renderer.unmount());
  });

  it('never restores another account’s saved lessons', async () => {
    await seedDeviceSnapshot('user-1', page(1).lessons);
    mockUser = {id: 2, api_token: 'other-token'};
    const pending = deferred<ReturnType<typeof page>>();
    mockGetSavedLessonsPage.mockReturnValue(pending.promise);
    mockGetSavedFolderOptions.mockResolvedValue([]);
    let library!: ReturnType<typeof useSavedLibrary>;
    const Screen = () => {
      library = useSavedLibrary();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Screen />, client, 'user-2'),
      );
    });
    await settle(40);
    expect(library.saved).toEqual([]);
    expect(
      client.getQueryData(['user-1', 'saved-lessons', 'all']),
    ).toBeUndefined();
    await act(async () => pending.resolve({...page(1), lessons: []}));
    await act(async () => renderer.unmount());
  });
});
