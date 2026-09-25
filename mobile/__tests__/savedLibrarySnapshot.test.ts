jest.mock('../src/components/VideoPlayer/courseLearningApi', () => {
  throw new Error('View snapshots must not load transport');
});
jest.mock('../src/services/roknApi', () => {
  throw new Error('View snapshots must not load persistence');
});
import type {SavedLesson} from '../src/services/roknApi';
import {SavedLibrarySnapshot} from '../src/screens/Profile/saved/SavedLibrarySnapshot';

const lesson = (id: string, folderId = '7'): SavedLesson => ({
  id,
  folderId,
  folderName: 'قائمة',
  courseId: '1',
  courseTitle: 'كورس',
  title: id,
  duration: '01:00',
});
const folder = {id: '7', name: 'قائمة', lessonsCount: 2};
const create = () => {
  const snapshot = new SavedLibrarySnapshot();
  snapshot.replaceFolderIndex([folder]);
  snapshot.replaceRows([lesson('11'), lesson('22')]);
  return snapshot;
};

it('publishes a coherent row/count removal and a single rollback without changing old snapshots', () => {
  const snapshot = create();
  const before = snapshot.getSnapshot();
  const observer = jest.fn();
  const unsubscribe = snapshot.subscribe(observer);
  const receipt = snapshot.beginLessonRemoval(lesson('11'))!;
  expect(observer).toHaveBeenCalledTimes(1);
  expect(snapshot.getSnapshot()).toEqual({
    saved: [lesson('22')],
    folders: [{...folder, lessonsCount: 1}],
  });
  expect(before).toEqual({
    saved: [lesson('11'), lesson('22')],
    folders: [folder],
  });
  snapshot.rollbackLessonRemoval(receipt);
  snapshot.rollbackLessonRemoval(receipt);
  expect(observer).toHaveBeenCalledTimes(2);
  expect(snapshot.getSnapshot()).toEqual(before);
  unsubscribe();
  snapshot.clear();
  expect(observer).toHaveBeenCalledTimes(2);
});

it('does not apply a rollback increment to a newer authoritative total', () => {
  const snapshot = create();
  const receipt = snapshot.beginLessonRemoval(lesson('11'))!;
  snapshot.replaceFolderTotal('7', 8);
  snapshot.rollbackLessonRemoval(receipt);
  expect(snapshot.getSnapshot()).toEqual({
    saved: [lesson('11'), lesson('22')],
    folders: [{...folder, lessonsCount: 8}],
  });
});

it('removes a row restored by refresh and obtains a new count instead of decrementing twice', () => {
  const snapshot = create();
  const receipt = snapshot.beginLessonRemoval(lesson('11'))!;
  snapshot.replaceRows([lesson('11'), lesson('22')]);
  snapshot.replaceFolderIndex([folder]);
  const read = snapshot.confirmLessonRemoval(receipt)!;
  expect(read).not.toBeNull();
  expect(snapshot.getSnapshot()).toEqual({
    saved: [lesson('22')],
    folders: [{...folder, lessonsCount: undefined}],
  });
  snapshot.acceptFolderCount(read, 1);
  snapshot.acceptFolderCount(read, 99);
  snapshot.rollbackLessonRemoval(receipt);
  expect(snapshot.getSnapshot().folders[0].lessonsCount).toBe(1);
  expect(snapshot.getSnapshot().saved).toEqual([lesson('22')]);
});

it('keeps independent overlapping removals and their totals consistent', () => {
  const snapshot = create();
  const first = snapshot.beginLessonRemoval(lesson('11'))!;
  const second = snapshot.beginLessonRemoval(lesson('22'))!;
  snapshot.rollbackLessonRemoval(first);
  expect(snapshot.confirmLessonRemoval(second)).toBeNull();
  snapshot.rollbackLessonRemoval(first);
  expect(snapshot.getSnapshot()).toEqual({
    saved: [lesson('11')],
    folders: [{...folder, lessonsCount: 1}],
  });
});

it('restores a failed folder removal around current sibling rows without replacing newer rows', () => {
  const snapshot = create();
  const sibling = {id: '8', name: 'أخرى', lessonsCount: 1};
  snapshot.replaceFolderIndex([folder, sibling]);
  snapshot.replaceRows([lesson('11'), lesson('33', '8'), lesson('22')]);
  const receipt = snapshot.beginFolderRemoval(folder);
  snapshot.appendRows([{...lesson('11'), title: 'new title'}]);
  snapshot.rollbackFolderRemoval(receipt);
  snapshot.rollbackFolderRemoval(receipt);
  expect(snapshot.getSnapshot().folders).toEqual([folder, sibling]);
  expect(snapshot.getSnapshot().saved).toEqual([
    lesson('33', '8'),
    {...lesson('11'), title: 'new title'},
    lesson('22'),
  ]);
});

it('invalidates all rollback and count receipts when the view owner is cleared', () => {
  const snapshot = create();
  const removal = snapshot.beginLessonRemoval(lesson('11'))!;
  snapshot.replaceFolderTotal('7', 1);
  const countRead = snapshot.confirmLessonRemoval(removal)!;
  const folderRemoval = snapshot.beginFolderRemoval(folder);
  snapshot.clear();
  snapshot.replaceFolderIndex([
    {...folder, name: 'new owner', lessonsCount: 7},
  ]);
  snapshot.replaceRows([lesson('77')]);
  const current = snapshot.getSnapshot();
  snapshot.rollbackLessonRemoval(removal);
  snapshot.rollbackFolderRemoval(folderRemoval);
  snapshot.confirmFolderRemoval(folderRemoval);
  snapshot.acceptFolderCount(countRead, 99);
  expect(snapshot.isCurrentCountRead(countRead)).toBe(false);
  expect(snapshot.getSnapshot()).toBe(current);
});

it('retains a folder proven by a successful page read and appends pages without duplicate existing rows', () => {
  const snapshot = create();
  snapshot.replaceFolderIndex([], '7');
  snapshot.appendRows([lesson('22'), lesson('33')]);
  expect(snapshot.getSnapshot()).toEqual({
    saved: [lesson('11'), lesson('22'), lesson('33')],
    folders: [folder],
  });
  expect(snapshot.beginLessonRemoval(lesson('missing'))).toBeNull();
});

it('ignores a delayed total read after a newer index has already supplied the count', () => {
  const snapshot = create();
  const removal = snapshot.beginLessonRemoval(lesson('11'))!;
  snapshot.replaceFolderIndex([folder]);
  const read = snapshot.confirmLessonRemoval(removal)!;
  snapshot.replaceFolderIndex([{...folder, lessonsCount: 6}]);
  snapshot.acceptFolderCount(read, 1);
  expect(snapshot.getSnapshot().folders[0].lessonsCount).toBe(6);
});
