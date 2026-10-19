import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {useInfiniteQuery} from '@tanstack/react-query';
import {createTestQueryClient, withQueryClient} from './helpers/queryClient';

const mockGetSavedLessonsPage = jest.fn();
const mockGetSavedFolderLessonsPage = jest.fn();

jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  captureAccountSessionBoundary: async () => ({scope: 'user-a', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  getSavedLessonsPage: (...args: unknown[]) => mockGetSavedLessonsPage(...args),
  getSavedFolderLessonsPage: (...args: unknown[]) =>
    mockGetSavedFolderLessonsPage(...args),
}));

import {applySavedMembershipChange} from '../src/services/query/savedLibraryInvalidation';
import {
  savedLessonRows,
  savedLessonsQueryOptions,
  type SavedLessonsData,
} from '../src/services/query/savedLibraryQueries';

const lesson = (id: string, folderId: string) => ({
  id,
  folderId,
  folderName: `قائمة ${folderId}`,
  courseId: '9',
  title: `مقطع ${id}`,
  courseTitle: 'الكورس',
  duration: '02:00',
});
const pageOf = (
  lessons: ReturnType<typeof lesson>[],
  page = 1,
  hasMore = false,
) => ({lessons, page, hasMore, total: lessons.length});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => {
    resolve = yes;
  });
  return {promise, resolve};
};
const settle = (ms = 10) =>
  act(async () => {
    await new Promise(resolve => setTimeout(resolve, ms));
  });

describe('confirmed saved-lesson changes and the owner’s lists', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
  });

  it('cancels a pre-mutation read, drops the row from every loaded list and reads again', async () => {
    const client = createTestQueryClient();
    client.setQueryData<SavedLessonsData>(['user-a', 'saved-lessons', '2'], {
      pages: [pageOf([lesson('22', '2'), lesson('33', '2')])],
      pageParams: [1],
    });
    const oldRead = deferred<ReturnType<typeof pageOf>>();
    mockGetSavedLessonsPage.mockReturnValueOnce(oldRead.promise);
    let rows: string[] = [];
    const Screen = () => {
      const query = useInfiniteQuery(savedLessonsQueryOptions('user-a', 'all'));
      rows = savedLessonRows(query.data).map(row => `${row.folderId}:${row.id}`);
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(withQueryClient(<Screen />, client));
    });
    await settle();
    expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(1);
    expect(client.isFetching({queryKey: ['user-a', 'saved-lessons']})).toBe(1);

    mockGetSavedLessonsPage.mockResolvedValue(
      pageOf([lesson('11', '1'), lesson('33', '2')]),
    );
    await act(async () => {
      await applySavedMembershipChange(client, 'user-a', {
        kind: 'remove',
        folderId: '2',
        lessonId: '22',
      });
    });
    await settle();
    // The pre-mutation answer was cancelled; the mounted list read again.
    expect(mockGetSavedLessonsPage).toHaveBeenCalledTimes(2);
    expect(rows).toEqual(['1:11', '2:33']);
    await act(async () => {
      oldRead.resolve(pageOf([lesson('11', '1'), lesson('22', '2')]));
    });
    await settle();
    expect(rows).toEqual(['1:11', '2:33']);
    // The unmounted folder list lost the row at once and is marked stale.
    const folderList = client.getQueryState<SavedLessonsData>([
      'user-a',
      'saved-lessons',
      '2',
    ]);
    expect(savedLessonRows(folderList?.data).map(row => row.id)).toEqual([
      '33',
    ]);
    expect(folderList?.data?.pages[0].total).toBe(1);
    expect(folderList?.isInvalidated).toBe(true);
  });

  it('removes a lesson everywhere and a deleted folder with its own list', async () => {
    const client = createTestQueryClient();
    client.setQueryData<SavedLessonsData>(['user-a', 'saved-lessons', 'all'], {
      pages: [
        pageOf([lesson('11', '1'), lesson('11', '2')], 1, true),
        pageOf([lesson('22', '2'), lesson('33', '3')], 2),
      ],
      pageParams: [1, 2],
    });
    client.setQueryData<SavedLessonsData>(['user-a', 'saved-lessons', '2'], {
      pages: [pageOf([lesson('11', '2'), lesson('22', '2')])],
      pageParams: [1],
    });
    const all = () =>
      savedLessonRows(
        client.getQueryData<SavedLessonsData>(['user-a', 'saved-lessons', 'all']),
      ).map(row => `${row.folderId}:${row.id}`);

    await applySavedMembershipChange(client, 'user-a', {
      kind: 'remove-everywhere',
      lessonId: '11',
    });
    expect(all()).toEqual(['2:22', '3:33']);
    expect(
      savedLessonRows(
        client.getQueryData<SavedLessonsData>(['user-a', 'saved-lessons', '2']),
      ).map(row => row.id),
    ).toEqual(['22']);

    await applySavedMembershipChange(client, 'user-a', {
      kind: 'delete-folder',
      folderId: '2',
    });
    expect(all()).toEqual(['3:33']);
    expect(
      client.getQueryData(['user-a', 'saved-lessons', '2']),
    ).toBeUndefined();

    // A save only marks the lists stale: the next read shows the new row.
    await applySavedMembershipChange(client, 'user-a', {
      kind: 'save',
      folderId: '3',
      lessonId: '44',
    });
    expect(all()).toEqual(['3:33']);
    expect(
      client.getQueryState(['user-a', 'saved-lessons', 'all'])?.isInvalidated,
    ).toBe(true);
    // Another account's lists are untouched.
    client.setQueryData<SavedLessonsData>(['user-b', 'saved-lessons', 'all'], {
      pages: [pageOf([lesson('11', '1')])],
      pageParams: [1],
    });
    await applySavedMembershipChange(client, 'user-a', {
      kind: 'remove-everywhere',
      lessonId: '11',
    });
    expect(
      savedLessonRows(
        client.getQueryData<SavedLessonsData>(['user-b', 'saved-lessons', 'all']),
      ).map(row => row.id),
    ).toEqual(['11']);
  });
});
