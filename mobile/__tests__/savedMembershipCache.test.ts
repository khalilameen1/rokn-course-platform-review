jest.mock('../src/constants/api', () => {
  throw new Error('Membership cache must not load the HTTP client');
});
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  removeSavedFolderFromCache: jest.fn(async () => undefined),
  removeSavedLessonEverywhereFromCache: jest.fn(async () => undefined),
  removeSavedLessonFromCache: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/persistence', () => ({
  updatePlayerStateForScope: jest.fn(),
}));

import {assertAccountSessionBoundary} from '../src/constants/helpers';
import {
  removeSavedFolderFromCache,
  removeSavedLessonEverywhereFromCache,
  removeSavedLessonFromCache,
} from '../src/services/roknApi';
import {updatePlayerStateForScope} from '../src/components/VideoPlayer/courseLearning/persistence';
import {
  repairSavedMembershipCache,
  reconcileSavedMembershipCache,
} from '../src/components/VideoPlayer/courseLearning/savedMembershipCache';

type State = Parameters<Parameters<typeof updatePlayerStateForScope>[1]>[0];
const boundary = {scope: 'user-1', epoch: 1};
let state: State;
const update = jest.mocked(updatePlayerStateForScope);
const initialState = (): State => ({
  savedLessons: ['11', '22', '33'],
  savedFolderLessons: {'7': ['11', '22'], '8': ['11', '33']},
  positions: {'11': 42},
  lastWatchedAt: {'11': '2026-09-25T00:00:00Z'},
  completedSections: ['9'],
  activityDays: ['2026-09-25'],
});

beforeEach(() => {
  jest.clearAllMocks();
  state = initialState();
  update.mockImplementation(async (_, change) => {
    state = change(state);
    return state;
  });
});

it('adds once and preserves sibling memberships and unrelated learning state', async () => {
  const before = initialState();
  await repairSavedMembershipCache(boundary, {
    kind: 'save',
    folderId: '7',
    lessonId: '33',
  });
  await repairSavedMembershipCache(boundary, {
    kind: 'save',
    folderId: '7',
    lessonId: '33',
  });
  expect(state).toEqual({
    ...before,
    savedFolderLessons: {'7': ['11', '22', '33'], '8': ['11', '33']},
  });
  expect(removeSavedFolderFromCache).not.toHaveBeenCalled();
  expect(removeSavedLessonFromCache).not.toHaveBeenCalled();
  expect(removeSavedLessonEverywhereFromCache).not.toHaveBeenCalled();
});

it('retains the bookmark until its final folder membership is removed', async () => {
  await repairSavedMembershipCache(boundary, {
    kind: 'remove',
    folderId: '7',
    lessonId: '11',
  });
  expect(state.savedLessons).toEqual(['11', '22', '33']);
  await repairSavedMembershipCache(boundary, {
    kind: 'remove',
    folderId: '8',
    lessonId: '11',
  });
  expect(state.savedLessons).toEqual(['22', '33']);
  expect(state.savedFolderLessons).toEqual({'7': ['22'], '8': ['33']});
  expect(removeSavedLessonFromCache).toHaveBeenNthCalledWith(
    1,
    '7',
    '11',
    boundary,
  );
  expect(removeSavedLessonFromCache).toHaveBeenNthCalledWith(
    2,
    '8',
    '11',
    boundary,
  );
});

it('removes a lesson everywhere without removing sibling lessons or playback progress', async () => {
  await repairSavedMembershipCache(boundary, {
    kind: 'remove-everywhere',
    lessonId: '11',
  });
  expect(state).toEqual({
    ...initialState(),
    savedLessons: ['22', '33'],
    savedFolderLessons: {'7': ['22'], '8': ['33']},
  });
  expect(removeSavedLessonEverywhereFromCache).toHaveBeenCalledWith(
    '11',
    boundary,
  );
});

it('starts the player repair while a folder page-cache removal is still pending', async () => {
  let release!: () => void;
  jest.mocked(removeSavedFolderFromCache).mockReturnValueOnce(
    new Promise<void>(resolve => {
      release = resolve;
    }),
  );
  const pending = repairSavedMembershipCache(boundary, {
    kind: 'delete-folder',
    folderId: '7',
  });
  try {
    expect(update).toHaveBeenCalledTimes(1);
    expect(state.savedLessons).toEqual(['11', '33']);
    expect(state.savedFolderLessons).toEqual({'8': ['11', '33']});
  } finally {
    release();
    await pending;
  }
});

it('rejects an obsolete account before either cache queue starts', async () => {
  jest.mocked(assertAccountSessionBoundary).mockImplementationOnce(() => {
    throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  });
  await expect(
    repairSavedMembershipCache(boundary, {
      kind: 'delete-folder',
      folderId: '7',
    }),
  ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
  expect(update).not.toHaveBeenCalled();
  expect(removeSavedFolderFromCache).not.toHaveBeenCalled();
});

it('checks a reconciliation revision inside the deferred player update', async () => {
  let apply!: Parameters<typeof updatePlayerStateForScope>[1];
  update.mockImplementationOnce(async (_, change) => {
    apply = change;
    return state;
  });
  let current = true;
  const guard = () => {
    if (!current) throw new Error('overtaken');
  };
  await reconcileSavedMembershipCache(
    boundary,
    new Set(['11']),
    new Set(),
    guard,
  );
  current = false;
  expect(() => apply(state)).toThrow('overtaken');
  expect(state).toEqual(initialState());
});

it('reconciles only queried lessons and does not invent a folder for a remote bookmark', async () => {
  const guard = jest.fn();
  await reconcileSavedMembershipCache(
    boundary,
    new Set(['11', '44']),
    new Set(['44']),
    guard,
  );
  expect(guard).toHaveBeenCalledTimes(1);
  expect(state).toEqual({
    ...initialState(),
    savedLessons: ['22', '33', '44'],
    savedFolderLessons: {'7': ['22'], '8': ['33']},
  });
});
