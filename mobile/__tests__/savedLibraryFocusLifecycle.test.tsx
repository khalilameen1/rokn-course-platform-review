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
