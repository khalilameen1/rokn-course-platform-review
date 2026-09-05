import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetSavedFolderOptions = jest.fn();
const mockCreateSavedFolderOption = jest.fn();
const mockSaveLessonToFolder = jest.fn();
const mockToggleWatchLater = jest.fn();

jest.mock('react-redux', () => ({
  useSelector: () => ({api_token: 'token-a', user: {id: 7}}),
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'account-a',
  })),
  sessionIdentityKey: () => 'account-a',
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  createSavedFolderOption: (...args: unknown[]) =>
    mockCreateSavedFolderOption(...args),
  getSavedFolderOptions: (...args: unknown[]) =>
    mockGetSavedFolderOptions(...args),
  saveLessonToFolder: (...args: unknown[]) => mockSaveLessonToFolder(...args),
  toggleWatchLater: (...args: unknown[]) => mockToggleWatchLater(...args),
}));

import {useSavedFolderPicker} from '../src/components/VideoPlayer/feedSideBar/useSavedFolderPicker';
import {useReelsSavedLessons} from '../src/screens/reels/useReelsSavedLessons';

describe('saved folder default destination', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGetSavedFolderOptions.mockResolvedValue([]);
    mockSaveLessonToFolder.mockResolvedValue(true);
    mockToggleWatchLater.mockResolvedValue(true);
  });

  it('shows watch later once and reuses its server folder when it exists', async () => {
    const onToggleSave = jest.fn();
    const dismiss = jest.fn();
    let picker!: ReturnType<typeof useSavedFolderPicker>;
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'المشاهدة لاحقًا'},
      {id: '8', name: 'للمراجعة'},
    ]);
    const Harness = () => {
      picker = useSavedFolderPicker({
        dismiss,
        onBeforeOpen: () => true,
        onToggleSave,
        present: jest.fn(),
      });
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      picker.open();
      await Promise.resolve();
      await Promise.resolve();
    });

    expect(picker.folders).toEqual([{id: '8', name: 'للمراجعة'}]);
    act(() => picker.saveInWatchLater());
    expect(onToggleSave).toHaveBeenCalledWith({
      id: '7',
      name: 'المشاهدة لاحقًا',
    });
    expect(dismiss).toHaveBeenCalledTimes(1);
    expect(mockCreateSavedFolderOption).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });

  it('uses the existing idempotent watch-later contract when no folder exists', async () => {
    const onToggleSave = jest.fn();
    let picker!: ReturnType<typeof useSavedFolderPicker>;
    const Harness = () => {
      picker = useSavedFolderPicker({
        dismiss: jest.fn(),
        onBeforeOpen: () => true,
        onToggleSave,
        present: jest.fn(),
      });
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    act(() => picker.saveInWatchLater());

    expect(onToggleSave).toHaveBeenCalledWith(undefined);
    expect(mockCreateSavedFolderOption).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });

  it('does not delete another-list save when adding the default destination', async () => {
    let saved!: ReturnType<typeof useReelsSavedLessons>;
    const loadedCourse = {
      current: {id: '3'},
    } as React.MutableRefObject<any>;
    const mounted = {current: true};
    const ownerGeneration = {current: 1};
    const Harness = () => {
      saved = useReelsSavedLessons({
        loadedCourse,
        mounted,
        ownerGeneration,
        scopeKey: 'account-a:course-3',
        setConnectionNote: jest.fn(),
      });
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    act(() => saved.setSavedLessons(new Set(['44'])));
    await act(async () => {
      await saved.toggleSaved({lessonId: '44'} as any, undefined);
    });

    expect(mockToggleWatchLater).toHaveBeenCalledWith('44', false);
    expect(mockSaveLessonToFolder).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });
});
