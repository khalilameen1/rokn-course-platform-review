import AsyncStorage from '@react-native-async-storage/async-storage';

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn()},
}));

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string, boundary?: {scope: string}) =>
      `${key}:${boundary?.scope ?? 'user-a'}`,
  ),
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user-a',
  })),
}));

jest.mock('../src/services/api/courses', () => ({
  getLearningCourses: jest.fn(),
}));

import {publicRequest} from '../src/constants/api';
import {assertAccountSessionBoundary} from '../src/constants/helpers';
import {
  getSavedFolderLessonsPage,
  getSavedLessonsPage,
} from '../src/services/api/savedLessons';

const apiGet = publicRequest.get as jest.MockedFunction<
  typeof publicRequest.get
>;
const folderResponse = (folderId = 7, currentPage = 1) => ({
  data: {
    data: {
      folder: {id: folderId, name: 'للمراجعة'},
      lessons: [
        {
          id: 44,
          title: 'المقطع',
          duration_seconds: 125,
          course: {id: 9, title: 'الكورس'},
        },
      ],
      pagination: {current_page: currentPage, last_page: 2, total: 21},
    },
  },
});

describe('saved lesson canonical mapper', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
  });

  it('keeps one missing thumbnail local instead of failing the whole library', async () => {
    apiGet.mockResolvedValue({
      data: {
        data: {
          lessons: [
            {
              id: 44,
              title: '  المقطع الثاني  ',
              duration_seconds: 125,
              course: {id: 9, title: '  أساسيات التصميم  '},
              folder_memberships: [{id: 7, name: '  للمراجعة  '}],
            },
          ],
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    } as never);

    await expect(getSavedLessonsPage()).resolves.toMatchObject({
      lessons: [
        {
          id: '44',
          folderId: '7',
          folderName: 'للمراجعة',
          courseId: '9',
          title: 'المقطع الثاني',
          courseTitle: 'أساسيات التصميم',
          duration: '02:05',
          imageUrl: undefined,
        },
      ],
      page: 1,
      hasMore: false,
    });
  });

  it('maps the authenticated outer folder without requiring or inventing other memberships', async () => {
    apiGet.mockResolvedValue(folderResponse() as never);
    await expect(getSavedFolderLessonsPage('7')).resolves.toMatchObject({
      lessons: [
        {id: '44', folderId: '7', folderName: 'للمراجعة', duration: '02:05'},
      ],
      page: 1,
      hasMore: true,
      total: 21,
      fromCache: false,
    });
    expect(apiGet).toHaveBeenCalledWith('saved-folders/7/lessons', {
      params: {page: 1, per_page: 20},
    });
    expect(AsyncStorage.setItem).not.toHaveBeenCalled();
    apiGet.mockResolvedValue(folderResponse(7, 2) as never);
    await expect(getSavedFolderLessonsPage('7', 2)).resolves.toMatchObject({
      page: 2,
      hasMore: false,
    });
  });

  it('does not weaken the global membership contract for the folder endpoint shape', async () => {
    apiGet.mockResolvedValue(folderResponse() as never);
    await expect(getSavedLessonsPage()).rejects.toThrow(
      'SAVED_LESSONS_CONTRACT_INVALID',
    );
  });

  it('retains every global folder membership while keeping the server distinct-lesson total', async () => {
    const data = folderResponse().data.data;
    apiGet.mockResolvedValue({
      data: {
        data: {
          ...data,
          lessons: data.lessons.map(lesson => ({
            ...lesson,
            folder_memberships: [data.folder, {id: 8, name: 'الثانية'}],
          })),
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    } as never);
    const result = await getSavedLessonsPage();
    expect(
      result.lessons.map(lesson => `${lesson.folderId}:${lesson.id}`),
    ).toEqual(['7:44', '8:44']);
    expect(result.total).toBe(1);
  });

  it('rejects a wrong folder, invalid route or wrong page', async () => {
    apiGet.mockResolvedValue(folderResponse(8) as never);
    await expect(getSavedFolderLessonsPage('7')).rejects.toThrow(
      'SAVED_FOLDER_LESSONS_CONTRACT_INVALID',
    );
    apiGet.mockResolvedValue(folderResponse(7, 1) as never);
    await expect(getSavedFolderLessonsPage('7', 2)).rejects.toThrow(
      'SAVED_LESSONS_CONTRACT_INVALID',
    );
    apiGet.mockClear();
    await expect(getSavedFolderLessonsPage('../8')).rejects.toThrow(
      'INVALID_SAVED_FOLDER_ROUTE',
    );
    expect(apiGet).not.toHaveBeenCalled();
  });

  it.each(['all', 'folder'])(
    'accepts an empty exhausted %s page after a deletion reduces the last page',
    async scope => {
      const response = folderResponse(7, 2);
      response.data.data.lessons = [];
      response.data.data.pagination = {
        current_page: 2,
        last_page: 1,
        total: 20,
      };
      apiGet.mockResolvedValue(response as never);
      const result =
        scope === 'all'
          ? getSavedLessonsPage(2)
          : getSavedFolderLessonsPage('7', 2);
      await expect(result).resolves.toMatchObject({
        lessons: [],
        page: 2,
        hasMore: false,
        total: 20,
      });
      response.data.data.pagination.last_page = 0;
      await expect(getSavedFolderLessonsPage('7', 2)).rejects.toThrow(
        'SAVED_LESSONS_CONTRACT_INVALID',
      );
    },
  );

  it('never presents the cached global first page as complete folder contents', async () => {
    const data = folderResponse().data.data;
    apiGet.mockResolvedValue({
      data: {
        data: {
          ...data,
          lessons: data.lessons.map(lesson => ({
            ...lesson,
            folder_memberships: [data.folder],
          })),
        },
      },
    } as never);
    await getSavedLessonsPage();
    apiGet.mockRejectedValue(new Error('offline'));
    await expect(getSavedFolderLessonsPage('7')).rejects.toThrow('offline');
    await expect(getSavedLessonsPage()).resolves.toMatchObject({
      fromCache: true,
    });
    expect(AsyncStorage.setItem).toHaveBeenCalled();
  });

  it('rejects a folder response after its account boundary expires', async () => {
    apiGet.mockResolvedValue(folderResponse() as never);
    jest.mocked(assertAccountSessionBoundary).mockImplementationOnce(() => {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    });
    await expect(getSavedFolderLessonsPage('7')).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(AsyncStorage.setItem).not.toHaveBeenCalled();
  });
});
