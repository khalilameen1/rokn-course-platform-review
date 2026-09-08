import React from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockFocusEffect: (() => void | (() => void)) | null = null;
let mockFocusCleanup: (() => void) | undefined;
let mockUser = {id: 1, api_token: 'token'};

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
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user-1',
  })),
  sessionIdentityKey: (user: {id: number}) => `user-${user.id}`,
}));

jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  createSavedFolderOption: (...args: unknown[]) =>
    mockCreateSavedFolderOption(...args),
  deleteSavedFolderOption: (...args: unknown[]) =>
    mockDeleteSavedFolderOption(...args),
  getSavedFolderOptions: (...args: unknown[]) =>
    mockGetSavedFolderOptions(...args),
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

import {useSavedLibrary} from '../src/screens/Profile/saved/useSavedLibrary';

const page = (pageNumber: number, hasMore = false) => ({
  fromCache: false,
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

const flush = async () => {
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
};

const blur = () => {
  const cleanup = mockFocusCleanup;
  mockFocusCleanup = undefined;
  cleanup?.();
};

const refocus = () => {
  mockFocusCleanup = mockFocusEffect?.() || undefined;
};

const mountLibrary = async () => {
  let library!: ReturnType<typeof useSavedLibrary>;
  const Harness = () => {
    library = useSavedLibrary();
    return null;
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
    await flush();
  });
  return {
    get current() {
      return library;
    },
    renderer,
    Harness,
  };
};

const folderPage = (folderId: string, pageNumber = 1, hasMore = false) => ({
  ...page(pageNumber, hasMore),
  lessons: [{...page(pageNumber).lessons[0], folderId, folderName: 'قائمتي'}],
});

describe('saved library focus lifecycle', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUser = {id: 1, api_token: 'token'};
    mockFocusEffect = null;
    mockFocusCleanup = undefined;
    mockHasSession.mockResolvedValue(true);
    mockGetSavedFolderOptions.mockResolvedValue([]);
    mockDeleteSavedFolderOption.mockResolvedValue(undefined);
    mockRemoveLessonFromSavedFolder.mockResolvedValue(undefined);
    mockGetSavedFolderLessonsPage.mockImplementation(
      async (folderId: string) => ({
        ...page(1),
        lessons: [{...page(1).lessons[0], folderId, folderName: 'قائمتي'}],
      }),
    );
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
    let library!: ReturnType<typeof useSavedLibrary>;
    const Harness = () => {
      library = useSavedLibrary();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    try {
      expect(library.folderCounts.get('7')).toBe(1);
      expect(library.folderCounts.get('8')).toBe(20);
      await act(async () => {
        library.selectFolder('7');
        await flush();
      });
      expect(mockGetSavedFolderLessonsPage).toHaveBeenCalledWith('7', 1);
      expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(1);
      expect(library.visibleSaved.map(item => item.id)).toEqual(['old']);
      expect(library.nextPage).toBeNull();
    } finally {
      await act(async () => renderer.unmount());
    }
  });

  it.each(['success', 'failure', 'success-already-reflected'])(
    'settles a removal after a foreground refresh restores the old rows (%s)',
    async outcome => {
      const succeeded = outcome !== 'failure';
      const folders = [{id: '7', name: 'قائمتي', lessonsCount: 1}];
      mockGetSavedFolderOptions.mockResolvedValue(folders);
      mockGetSavedLessonsPage.mockResolvedValue(folderPage('7'));
      const view = await mountLibrary();
      const removal = deferred<void>();
      mockRemoveLessonFromSavedFolder.mockReturnValueOnce(removal.promise);
      try {
        await act(async () => {
          view.current.selectFolder('7');
          await flush();
        });
        const oldPage = deferred<ReturnType<typeof page>>();
        const oldIndex = deferred<typeof folders>();
        mockGetSavedFolderLessonsPage.mockReturnValueOnce(oldPage.promise);
        mockGetSavedFolderOptions.mockReturnValueOnce(oldIndex.promise);
        await act(async () => {
          view.current.retry();
          await flush();
        });
        expect(view.current.loading).toBe(true);
        const item = view.current.saved[0];
        await act(async () => {
          void view.current.removeSaved(item);
          await flush();
        });
        expect(view.current.saved).toEqual([]);
        expect(view.current.folderCounts.get('7')).toBe(0);
        await act(async () => {
          oldPage.resolve(
            outcome === 'success-already-reflected'
              ? {...folderPage('7'), lessons: [], total: 0}
              : folderPage('7'),
          );
          oldIndex.resolve(
            outcome === 'success-already-reflected'
              ? [{...folders[0], lessonsCount: 0}]
              : folders,
          );
          await flush();
        });
        await act(async () => {
          void view.current.removeSaved(item);
          if (succeeded) removal.resolve();
          else removal.reject(new Error('offline'));
          await flush();
        });
        expect(mockRemoveLessonFromSavedFolder).toHaveBeenCalledTimes(1);
        expect(view.current.saved).toHaveLength(succeeded ? 0 : 1);
        expect(view.current.folderCounts.get('7')).toBe(succeeded ? 0 : 1);
        expect(view.current.removingSaved.size).toBe(0);
      } finally {
        await act(async () => view.renderer.unmount());
      }
    },
  );

  it.each(['success', 'failure'])(
    'settles a folder deletion after a foreground refresh restores its old snapshot (%s)',
    async outcome => {
      const folders = [{id: '7', name: 'قائمتي', lessonsCount: 1}];
      mockGetSavedFolderOptions.mockResolvedValue(folders);
      mockGetSavedLessonsPage.mockResolvedValue(folderPage('7'));
      const view = await mountLibrary();
      const deletion = deferred<void>();
      mockDeleteSavedFolderOption.mockReturnValueOnce(deletion.promise);
      try {
        await act(async () => {
          view.current.selectFolder('7');
          await flush();
        });
        const oldPage = deferred<ReturnType<typeof page>>();
        const oldIndex = deferred<typeof folders>();
        mockGetSavedFolderLessonsPage.mockReturnValueOnce(oldPage.promise);
        mockGetSavedFolderOptions.mockReturnValueOnce(oldIndex.promise);
        await act(async () => {
          view.current.retry();
          await flush();
        });
        act(() => view.current.deleteActiveFolder());
        const confirm = mockAlert.mock.calls
          .at(-1)?.[2]
          ?.find(button => button.style === 'destructive');
        await act(async () => {
          confirm?.onPress?.();
          await flush();
        });
        expect(view.current.folderOptions).toEqual([]);
        await act(async () => {
          oldPage.resolve(folderPage('7'));
          oldIndex.resolve(folders);
          await flush();
        });
        // Keep the following all-view refresh unresolved: the confirmed local
        // deletion must stand on its own, not depend on another successful GET.
        const allRead = deferred<ReturnType<typeof page>>();
        mockGetSavedLessonsPage.mockReturnValueOnce(allRead.promise);
        await act(async () => {
          if (outcome === 'success') deletion.resolve();
          else deletion.reject(new Error('offline'));
          await flush();
        });
        expect(mockDeleteSavedFolderOption).toHaveBeenCalledTimes(1);
        expect(view.current.activeFolderId).toBe(
          outcome === 'success' ? 'all' : '7',
        );
        expect(view.current.folderOptions).toHaveLength(
          outcome === 'success' ? 0 : 1,
        );
        expect(view.current.saved).toHaveLength(outcome === 'success' ? 0 : 1);
        expect(view.current.deletingFolder).toBe(false);
      } finally {
        await act(async () => view.renderer.unmount());
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
      await act(async () => {
        view.current.selectFolder('7');
        await flush();
      });
      mockGetSavedFolderLessonsPage.mockRejectedValueOnce(new Error('offline'));
      await act(async () => {
        await view.current.loadMore();
      });
      expect(view.current.saved.map(item => item.id)).toEqual(['lesson-1']);
      expect(view.current.nextPage).toBe(2);
      expect(view.current.loadMoreError).not.toBe('');
      mockGetSavedFolderLessonsPage.mockResolvedValueOnce(folderPage('7', 2));
      await act(async () => {
        await view.current.loadMore();
      });
      expect(mockGetSavedFolderLessonsPage).toHaveBeenLastCalledWith('7', 2);
      expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(1);
      expect(view.current.saved.map(item => item.id)).toEqual([
        'lesson-1',
        'lesson-2',
      ]);
      expect(view.current.loadMoreError).toBe('');
      expect(view.current.nextPage).toBeNull();
    } finally {
      await act(async () => view.renderer.unmount());
    }
  });

  it('discards global and folder pages that finish after another folder is selected', async () => {
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
        await flush();
      });
      await act(async () => {
        view.current.selectFolder('7');
        await flush();
      });
      await act(async () => {
        oldGlobal.resolve(page(2));
        await flush();
      });
      expect(view.current.saved.every(item => item.folderId === '7')).toBe(
        true,
      );
      await act(async () => {
        void view.current.loadMore();
        await flush();
      });
      await act(async () => {
        view.current.selectFolder('8');
        await flush();
      });
      await act(async () => {
        oldFolder.resolve(folderPage('7', 2));
        await flush();
      });
      expect(view.current.saved.map(item => item.folderId)).toEqual(['8']);
      expect(view.current.loadingMore).toBe(false);
      expect(view.current.nextPage).toBeNull();
      await act(async () => {
        view.current.selectFolder('all');
        await flush();
      });
      expect(mockGetSavedLessonsPage).toHaveBeenLastCalledWith(1);
      expect(view.current.nextPage).toBe(2);
    } finally {
      await act(async () => view.renderer.unmount());
    }
  });

  it('keeps folder failures retryable and ignores a late prior-account folder read', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمتي', lessonsCount: 1},
    ]);
    mockGetSavedLessonsPage.mockResolvedValue(page(1));
    mockGetSavedFolderLessonsPage.mockRejectedValueOnce(new Error('offline'));
    const view = await mountLibrary();
    try {
      await act(async () => {
        view.current.selectFolder('7');
        await flush();
      });
      expect(view.current.saved).toEqual([]);
      expect(view.current.folderCounts.get('7')).toBe(1);
      expect(view.current.error).not.toBe('');
      expect(view.current.loading).toBe(false);
      const oldRead = deferred<ReturnType<typeof page>>();
      mockGetSavedFolderLessonsPage.mockReturnValueOnce(oldRead.promise);
      await act(async () => {
        view.current.retry();
        await flush();
      });
      mockUser = {id: 2, api_token: 'other-token'};
      mockGetSavedFolderOptions.mockResolvedValue([]);
      mockGetSavedLessonsPage.mockResolvedValue({...page(1), lessons: []});
      await act(async () => {
        view.renderer.update(<view.Harness />);
        await flush();
      });
      await act(async () => {
        oldRead.resolve(folderPage('7'));
        await flush();
      });
      expect(view.current.activeFolderId).toBe('all');
      expect(view.current.saved).toEqual([]);
      expect(view.current.folderOptions).toEqual([]);
    } finally {
      await act(async () => view.renderer.unmount());
    }
  });

  it('returns to all when the selected folder disappears, even with a stale index', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمتي', lessonsCount: 1},
    ]);
    mockGetSavedLessonsPage.mockResolvedValue(page(1));
    const view = await mountLibrary();
    try {
      mockGetSavedFolderLessonsPage.mockRejectedValueOnce({
        response: {status: 404},
      });
      await act(async () => {
        view.current.selectFolder('7');
        await flush();
      });
      expect(view.current.activeFolderId).toBe('all');
      expect(view.current.error).toBe('');
      expect(mockGetSavedLessonsPage).toHaveBeenLastCalledWith(1);
    } finally {
      await act(async () => view.renderer.unmount());
    }
  });

  it('keeps a successfully loaded folder when an older cached index omits it', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمتي', lessonsCount: 1},
    ]);
    mockGetSavedLessonsPage.mockResolvedValue(page(1));
    const view = await mountLibrary();
    try {
      await act(async () => {
        view.current.selectFolder('7');
        await flush();
      });
      mockGetSavedFolderOptions.mockResolvedValue([]);
      await act(async () => {
        view.current.retry();
        await flush();
      });
      expect(view.current.activeFolderId).toBe('7');
      expect(view.current.folderOptions).toEqual([
        {id: '7', name: 'قائمتي', lessonsCount: 1},
      ]);
      expect(view.current.visibleSaved).toHaveLength(1);
      expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(1);
    } finally {
      await act(async () => view.renderer.unmount());
    }
  });

  it('rolls back a failed folder deletion in its scope and loads all after successful deletion', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمتي', lessonsCount: 1},
    ]);
    mockGetSavedLessonsPage.mockResolvedValue({...page(1), lessons: []});
    const view = await mountLibrary();
    const confirmDelete = async () => {
      act(() => view.current.deleteActiveFolder());
      const confirm = mockAlert.mock.calls
        .at(-1)?.[2]
        ?.find(button => button.style === 'destructive');
      await act(async () => {
        confirm?.onPress?.();
        await flush();
      });
    };
    try {
      await act(async () => {
        view.current.selectFolder('7');
        await flush();
      });
      const deletion = deferred<void>();
      mockDeleteSavedFolderOption.mockReturnValueOnce(deletion.promise);
      await confirmDelete();
      expect(view.current.activeFolderId).toBe('7');
      expect(view.current.saved).toEqual([]);
      await act(async () => {
        deletion.reject(new Error('offline'));
        await flush();
      });
      expect(view.current.visibleSaved).toHaveLength(1);
      expect(view.current.folderCounts.get('7')).toBe(1);
      expect(view.current.folderError).not.toBe('');
      mockGetSavedFolderOptions.mockResolvedValue([]);
      await confirmDelete();
      expect(view.current.activeFolderId).toBe('all');
      expect(view.current.folderOptions).toEqual([]);
      expect(view.current.saved).toEqual([]);
      expect(view.current.deletingFolder).toBe(false);
    } finally {
      await act(async () => view.renderer.unmount());
    }
  });

  it('decrements and restores the server folder total for an optimistic failed membership removal', async () => {
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'قائمتي', lessonsCount: 21},
    ]);
    mockGetSavedLessonsPage.mockResolvedValue(page(1));
    mockGetSavedFolderLessonsPage.mockResolvedValue({
      ...folderPage('7', 1, true),
      total: 21,
    });
    const removal = deferred<void>();
    mockRemoveLessonFromSavedFolder.mockReturnValueOnce(removal.promise);
    const view = await mountLibrary();
    try {
      await act(async () => {
        view.current.selectFolder('7');
        await flush();
      });
      await act(async () => {
        void view.current.removeSaved(view.current.saved[0]);
        await flush();
      });
      expect(view.current.folderCounts.get('7')).toBe(20);
      await act(async () => {
        removal.reject(new Error('offline'));
        await flush();
      });
      expect(view.current.folderCounts.get('7')).toBe(21);
      expect(view.current.saved).toHaveLength(1);
    } finally {
      await act(async () => view.renderer.unmount());
    }
  });

  it('releases an interrupted pagination state when the screen returns', async () => {
    const secondPage = deferred<ReturnType<typeof page>>();
    mockGetSavedLessonsPage.mockImplementation((requestedPage = 1) =>
      requestedPage === 2 ? secondPage.promise : Promise.resolve(page(1, true)),
    );
    let library!: ReturnType<typeof useSavedLibrary>;
    const Harness = () => {
      library = useSavedLibrary();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });

    await act(async () => {
      library.loadMore();
      await flush();
    });
    expect(library.loadingMore).toBe(true);

    await act(async () => {
      blur();
      refocus();
      await flush();
    });
    expect(library.loadingMore).toBe(false);

    await act(async () => {
      secondPage.resolve(page(2));
      await flush();
    });
    expect(library.loadingMore).toBe(false);
    expect(library.saved.map(item => item.id)).toEqual(['lesson-1']);

    await act(async () => renderer.unmount());
  });

  it('reconciles a folder created while the screen was away without unlocking a duplicate write', async () => {
    const creation = deferred<{id: string; name: string}>();
    let serverFolders: Array<{id: string; name: string}> = [];
    mockGetSavedLessonsPage.mockResolvedValue(page(1));
    mockGetSavedFolderOptions.mockImplementation(async () => serverFolders);
    mockCreateSavedFolderOption.mockImplementation(() =>
      creation.promise.then(folder => {
        serverFolders = [folder];
        return folder;
      }),
    );
    let library!: ReturnType<typeof useSavedLibrary>;
    const Harness = () => {
      library = useSavedLibrary();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    await act(async () => {
      library.setNewFolderName('قائمتي');
      await flush();
    });
    await act(async () => {
      library.createFolder();
      await flush();
    });
    expect(library.creatingFolder).toBe(true);

    await act(async () => {
      blur();
      refocus();
      library.createFolder();
      await flush();
    });
    expect(mockCreateSavedFolderOption).toHaveBeenCalledTimes(1);

    await act(async () => {
      creation.resolve({id: 'folder-1', name: 'قائمتي'});
      await flush();
    });
    expect(library.creatingFolder).toBe(false);
    expect(library.folderOptions).toEqual(
      expect.arrayContaining([
        expect.objectContaining({id: 'folder-1', name: 'قائمتي'}),
      ]),
    );

    await act(async () => renderer.unmount());
  });

  it('derives idle mutation state when a folder write settles while blurred', async () => {
    const creation = deferred<{id: string; name: string}>();
    let serverFolders: Array<{id: string; name: string}> = [];
    mockGetSavedLessonsPage.mockResolvedValue(page(1));
    mockGetSavedFolderOptions.mockImplementation(async () => serverFolders);
    mockCreateSavedFolderOption.mockImplementation(() =>
      creation.promise.then(folder => {
        serverFolders = [folder];
        return folder;
      }),
    );
    let library!: ReturnType<typeof useSavedLibrary>;
    const Harness = () => {
      library = useSavedLibrary();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    await act(async () => {
      library.setNewFolderName('قائمتي');
      await flush();
    });
    await act(async () => {
      library.createFolder();
      await flush();
      blur();
      creation.resolve({id: 'folder-1', name: 'قائمتي'});
      await flush();
    });

    await act(async () => {
      refocus();
      await flush();
    });
    expect(library.creatingFolder).toBe(false);
    expect(library.folderOptions).toEqual(
      expect.arrayContaining([
        expect.objectContaining({id: 'folder-1', name: 'قائمتي'}),
      ]),
    );

    await act(async () => renderer.unmount());
  });

  it('keeps a failed removal single-flight and resynchronizes after returning', async () => {
    const removal = deferred<void>();
    mockGetSavedLessonsPage.mockResolvedValue(page(1));
    mockRemoveLessonFromSavedFolder.mockReturnValue(removal.promise);
    let library!: ReturnType<typeof useSavedLibrary>;
    const Harness = () => {
      library = useSavedLibrary();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    const savedLesson = library.saved[0];
    await act(async () => {
      library.removeSaved(savedLesson);
      await flush();
      blur();
      refocus();
      await flush();
    });
    expect(library.removingSaved.has('watch-later:lesson-1')).toBe(true);

    await act(async () => {
      library.removeSaved(savedLesson);
      await flush();
    });
    expect(mockRemoveLessonFromSavedFolder).toHaveBeenCalledTimes(1);

    await act(async () => {
      removal.reject(new Error('offline'));
      await flush();
    });
    expect(library.removingSaved.size).toBe(0);
    expect(library.saved.map(item => item.id)).toEqual(['lesson-1']);

    await act(async () => renderer.unmount());
  });

  it('recovers a created folder when the server committed but the response was lost', async () => {
    const creation = deferred<{id: string; name: string}>();
    let serverFolders: Array<{id: string; name: string}> = [];
    mockGetSavedLessonsPage.mockResolvedValue(page(1));
    mockGetSavedFolderOptions.mockImplementation(async () => serverFolders);
    mockCreateSavedFolderOption.mockReturnValue(creation.promise);
    let library!: ReturnType<typeof useSavedLibrary>;
    const Harness = () => {
      library = useSavedLibrary();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    await act(async () => {
      library.setNewFolderName('قائمتي');
      await flush();
    });
    await act(async () => {
      library.createFolder();
      await flush();
      blur();
      refocus();
      await flush();
    });

    serverFolders = [{id: 'folder-1', name: 'قائمتي'}];
    await act(async () => {
      creation.reject(new Error('response lost'));
      await flush();
    });
    expect(library.creatingFolder).toBe(false);
    expect(library.folderOptions).toEqual(
      expect.arrayContaining([
        expect.objectContaining({id: 'folder-1', name: 'قائمتي'}),
      ]),
    );

    await act(async () => renderer.unmount());
  });

  it('recovers a deleted folder when the server committed but the response was lost', async () => {
    const deletion = deferred<void>();
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
        ? [
            {
              ...page(1).lessons[0],
              folderId: 'folder-1',
              folderName: 'قائمتي',
            },
          ]
        : [],
      total: folderExists ? 1 : 0,
    }));
    mockDeleteSavedFolderOption.mockReturnValue(deletion.promise);
    let library!: ReturnType<typeof useSavedLibrary>;
    const Harness = () => {
      library = useSavedLibrary();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    await act(async () => {
      library.selectFolder('folder-1');
      await flush();
    });
    await act(async () => {
      library.deleteActiveFolder();
      await flush();
    });
    const buttons = mockAlert.mock.calls.at(-1)?.[2];
    const confirm = buttons?.find(button => button.style === 'destructive');
    await act(async () => {
      confirm?.onPress?.();
      await flush();
      blur();
      refocus();
      await flush();
    });
    expect(mockDeleteSavedFolderOption).toHaveBeenCalledTimes(1);

    folderExists = false;
    await act(async () => {
      deletion.reject(new Error('response lost'));
      await flush();
    });
    expect(library.deletingFolder).toBe(false);
    expect(library.folderOptions).toEqual([]);
    expect(library.saved).toEqual([]);

    await act(async () => renderer.unmount());
  });
});
