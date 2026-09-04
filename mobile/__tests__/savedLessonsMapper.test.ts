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
import {getSavedLessonsPage} from '../src/services/api/savedLessons';

const apiGet = publicRequest.get as jest.MockedFunction<
  typeof publicRequest.get
>;

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
});
