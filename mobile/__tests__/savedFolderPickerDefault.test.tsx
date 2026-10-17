import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetSavedFolderOptions = jest.fn();
const mockCreateSavedFolderOption = jest.fn();
const mockSaveLessonToFolder = jest.fn();
const mockToggleWatchLater = jest.fn();
const mockRemoveLessonFromSavedFolder = jest.fn();
const mockGetSavedLessonFolderMembership = jest.fn();
const mockCaptureBoundary = jest.fn();
let mockScope = 'account-a';
let mockEpoch = 1;

jest.mock('react-redux', () => ({
  useSelector: () => ({api_token: 'token-a', user: {id: 7}}),
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: (boundary: {epoch: number; scope: string}) => {
    if (boundary.epoch !== mockEpoch || boundary.scope !== mockScope) {
      throw new Error('Account changed');
    }
  },
  captureAccountSessionBoundary: () => mockCaptureBoundary(),
  sessionIdentityKey: () => mockScope,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  createSavedFolderOption: (...args: unknown[]) =>
    mockCreateSavedFolderOption(...args),
  getSavedFolderOptions: (...args: unknown[]) =>
    mockGetSavedFolderOptions(...args),
  saveLessonToFolder: (...args: unknown[]) => mockSaveLessonToFolder(...args),
  toggleWatchLater: (...args: unknown[]) => mockToggleWatchLater(...args),
  removeLessonFromSavedFolder: (...args: unknown[]) =>
    mockRemoveLessonFromSavedFolder(...args),
  getSavedLessonFolderMembership: (...args: unknown[]) =>
    mockGetSavedLessonFolderMembership(...args),
}));

import {useSavedFolderPicker} from '../src/components/VideoPlayer/feedSideBar/useSavedFolderPicker';
import {useReelsSavedLessons} from '../src/screens/reels/useReelsSavedLessons';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: Error) => void;
  const promise = new Promise<T>((resolveValue, rejectValue) => {
    resolve = resolveValue;
    reject = rejectValue;
  });
  return {promise, resolve, reject};
};

const mountPicker = async () => {
  let picker!: ReturnType<typeof useSavedFolderPicker>;
  const onToggleSave = jest.fn();
  const dismiss = jest.fn();
  const present = jest.fn();
  const Harness = ({scopeKey}: {scopeKey: string}) => {
    picker = useSavedFolderPicker({
      dismiss,
      onBeforeOpen: () => true,
      onToggleSave,
      present,
      scopeKey,
    });
    return null;
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness scopeKey="3:44" />);
  });
  return {
    get picker() {
      return picker;
    },
    onToggleSave,
    dismiss,
    present,
    renderer,
    updateScope: (scopeKey: string) =>
      renderer.update(<Harness scopeKey={scopeKey} />),
  };
};

describe('saved folder default destination', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockScope = 'account-a';
    mockEpoch = 1;
    mockCaptureBoundary
      .mockReset()
      .mockImplementation(async () => ({epoch: mockEpoch, scope: mockScope}));
    mockCreateSavedFolderOption.mockReset();
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
    await act(async () => {
      picker.open();
    });
    act(() => picker.saveInWatchLater());

    expect(onToggleSave).toHaveBeenCalledWith(undefined);
    expect(mockCreateSavedFolderOption).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });

  it('does not save into a late-created folder after the student chooses watch later instead', async () => {
    let resolveCreate!: (folder: {id: string; name: string}) => void;
    mockCreateSavedFolderOption.mockReturnValue(
      new Promise(resolve => {
        resolveCreate = resolve;
      }),
    );
    const onToggleSave = jest.fn();
    const dismiss = jest.fn();
    let picker!: ReturnType<typeof useSavedFolderPicker>;
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
    });
    act(() => picker.setName('قائمة قديمة'));
    let creating!: Promise<void>;
    await act(async () => {
      creating = picker.createAndSave();
    });
    expect(mockCreateSavedFolderOption).toHaveBeenCalledWith('قائمة قديمة');
    act(() => picker.saveInWatchLater());
    expect(onToggleSave).toHaveBeenCalledTimes(1);
    expect(onToggleSave).toHaveBeenCalledWith(undefined);
    await act(async () => {
      resolveCreate({id: 'old-folder', name: 'قائمة قديمة'});
      await creating;
    });
    expect(onToggleSave).toHaveBeenCalledTimes(1);
    expect(dismiss).toHaveBeenCalledTimes(1);
    await act(async () => renderer.unmount());
  });

  it.each([
    ['existing folder', {id: '8', name: 'للمراجعة'}],
    ['remove from saved', null],
  ] as const)(
    'retires creation immediately when selecting %s',
    async (_, selected) => {
      const creation = deferred<{id: string; name: string}>();
      mockCreateSavedFolderOption.mockReturnValue(creation.promise);
      const view = await mountPicker();
      await act(async () => {
        view.picker.open();
      });
      act(() => view.picker.setName('قائمة قيد الإنشاء'));
      let creating!: Promise<void>;
      await act(async () => {
        creating = view.picker.createAndSave();
      });
      act(() => view.picker.saveInFolder(selected));
      await act(async () => {
        creation.resolve({id: 'new', name: 'قائمة قيد الإنشاء'});
        await creating;
      });
      expect(view.onToggleSave.mock.calls).toEqual([[selected]]);
      expect(view.dismiss).toHaveBeenCalledTimes(1);
      await act(async () => view.renderer.unmount());
    },
  );

  it('keeps the reopened visit, newer name and new creation when an old visit settles or dismisses', async () => {
    const oldCreation = deferred<{id: string; name: string}>();
    const newCreation = deferred<{id: string; name: string}>();
    mockCreateSavedFolderOption
      .mockReturnValueOnce(oldCreation.promise)
      .mockReturnValueOnce(newCreation.promise);
    const view = await mountPicker();
    await act(async () => {
      view.picker.open();
    });
    act(() => view.picker.setName('القائمة الأولى'));
    const oldVisit = view.picker;
    let first!: Promise<void>;
    await act(async () => {
      first = view.picker.createAndSave();
    });
    act(() => view.picker.close());
    await act(async () => {
      view.picker.open();
    });
    act(() => view.picker.setName('القائمة الثانية'));
    let second!: Promise<void>;
    await act(async () => {
      second = view.picker.createAndSave();
    });
    act(() => {
      oldVisit.close();
      oldVisit.saveInWatchLater();
    });
    await act(async () => {
      oldCreation.resolve({id: 'old', name: 'القائمة الأولى'});
      await first;
    });
    expect(view.picker.name).toBe('القائمة الثانية');
    expect(view.picker.creating).toBe(true);
    expect(view.picker.folders).toEqual([]);
    expect(view.onToggleSave).not.toHaveBeenCalled();
    expect(view.dismiss).not.toHaveBeenCalled();
    await act(async () => {
      newCreation.resolve({id: 'new', name: 'القائمة الثانية'});
      await second;
    });
    expect(view.onToggleSave.mock.calls).toEqual([
      [{id: 'new', name: 'القائمة الثانية'}],
    ]);
    expect(view.dismiss).toHaveBeenCalledTimes(1);
    expect(view.picker.name).toBe('');
    expect(view.picker.creating).toBe(false);
    await act(async () => view.renderer.unmount());
  });

  it.each(['scope', 'account', 'unmount'] as const)(
    'ignores an accepted folder from a retired %s owner',
    async transition => {
      const creation = deferred<{id: string; name: string}>();
      mockCreateSavedFolderOption.mockReturnValue(creation.promise);
      const view = await mountPicker();
      await act(async () => {
        view.picker.open();
      });
      act(() => view.picker.setName('قائمة قديمة'));
      let creating!: Promise<void>;
      await act(async () => {
        creating = view.picker.createAndSave();
      });
      await act(async () => {
        if (transition === 'unmount') view.renderer.unmount();
        else {
          if (transition === 'account') {
            mockScope = 'account-b';
            mockEpoch += 1;
          }
          view.updateScope(transition === 'scope' ? '4:44' : '3:44');
        }
      });
      if (transition !== 'unmount') {
        await act(async () => {
          view.picker.open();
        });
        act(() => view.picker.setName('اسم حالي'));
      }
      await act(async () => {
        creation.resolve({id: 'created-server-side', name: 'قائمة قديمة'});
        await creating;
      });
      expect(view.onToggleSave).not.toHaveBeenCalled();
      expect(view.dismiss).not.toHaveBeenCalled();
      if (transition !== 'unmount') {
        expect(view.picker.name).toBe('اسم حالي');
        expect(view.picker.folders).toEqual([]);
        await act(async () => view.renderer.unmount());
      }
    },
  );

  it('does not start a creation after account capture returns to a retired visit', async () => {
    const boundary = deferred<{epoch: number; scope: string}>();
    const view = await mountPicker();
    await act(async () => {
      view.picker.open();
    });
    act(() => view.picker.setName('قائمة قديمة'));
    mockCaptureBoundary.mockReturnValueOnce(boundary.promise);
    let creating!: Promise<void>;
    await act(async () => {
      creating = view.picker.createAndSave();
    });
    act(() => view.picker.close());
    await act(async () => {
      boundary.resolve({epoch: 1, scope: 'account-a'});
      await creating;
    });
    expect(mockCreateSavedFolderOption).not.toHaveBeenCalled();
    expect(view.onToggleSave).not.toHaveBeenCalled();
    await act(async () => view.renderer.unmount());
  });

  it('preserves a newer name while completing only the captured creation once', async () => {
    const creation = deferred<{id: string; name: string}>();
    mockCreateSavedFolderOption.mockReturnValue(creation.promise);
    const view = await mountPicker();
    await act(async () => {
      view.picker.open();
    });
    act(() => view.picker.setName('القائمة المطلوبة'));
    let creating!: Promise<void>;
    await act(async () => {
      creating = view.picker.createAndSave();
      void view.picker.createAndSave();
    });
    act(() => view.picker.setName('اسم للزيارة التالية'));
    await act(async () => {
      creation.resolve({id: 'created', name: 'القائمة المطلوبة'});
      await creating;
    });
    expect(mockCreateSavedFolderOption).toHaveBeenCalledTimes(1);
    expect(view.onToggleSave.mock.calls).toEqual([
      [{id: 'created', name: 'القائمة المطلوبة'}],
    ]);
    expect(view.picker.name).toBe('اسم للزيارة التالية');
    expect(view.picker.creating).toBe(false);
    await act(async () => view.renderer.unmount());
  });

  it('keeps failed creation editable and permits one explicit retry', async () => {
    mockCreateSavedFolderOption
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce({id: 'created', name: 'للمراجعة'});
    const view = await mountPicker();
    await act(async () => {
      view.picker.open();
    });
    act(() => view.picker.setName('للمراجعة'));
    await act(async () => {
      await view.picker.createAndSave();
    });
    expect(view.picker.name).toBe('للمراجعة');
    expect(view.picker.creating).toBe(false);
    expect(view.picker.error).toContain('معرفناش نعمل القائمة');
    expect(view.onToggleSave).not.toHaveBeenCalled();
    await act(async () => {
      await view.picker.createAndSave();
    });
    expect(mockCreateSavedFolderOption).toHaveBeenCalledTimes(2);
    expect(view.onToggleSave.mock.calls).toEqual([
      [{id: 'created', name: 'للمراجعة'}],
    ]);
    expect(view.picker.error).toBe('');
    await act(async () => view.renderer.unmount());
  });

  it('coalesces loading within one visit and ignores an old read after reopening', async () => {
    const oldRead = deferred<{id: string; name: string}[]>();
    const newRead = deferred<{id: string; name: string}[]>();
    mockGetSavedFolderOptions
      .mockReturnValueOnce(oldRead.promise)
      .mockReturnValueOnce(newRead.promise);
    const view = await mountPicker();
    await act(async () => {
      view.picker.open();
      view.picker.open();
    });
    expect(mockGetSavedFolderOptions).toHaveBeenCalledTimes(1);
    act(() => view.picker.close());
    await act(async () => {
      view.picker.open();
    });
    expect(mockGetSavedFolderOptions).toHaveBeenCalledTimes(2);
    await act(async () => {
      oldRead.resolve([{id: 'old', name: 'قديم'}]);
    });
    expect(view.picker.folders).toEqual([]);
    expect(view.picker.loading).toBe(true);
    await act(async () => {
      newRead.resolve([{id: 'current', name: 'حالي'}]);
    });
    expect(view.picker.folders).toEqual([{id: 'current', name: 'حالي'}]);
    expect(view.picker.loading).toBe(false);
    await act(async () => view.renderer.unmount());
  });

  it('retries a failed read in place without clearing a name or repeating a save', async () => {
    const freshRead = deferred<{id: string; name: string}[]>();
    mockGetSavedFolderOptions
      .mockRejectedValueOnce(new Error('offline'))
      .mockReturnValueOnce(freshRead.promise);
    const view = await mountPicker();
    await act(async () => view.picker.open());
    act(() => view.picker.setName('قائمتي'));
    expect(view.picker.loadError).toBe('معرفناش نحمّل قوائمك');
    expect(view.picker.error).toBe('');

    await act(async () => {
      view.picker.retryFolders();
      view.picker.retryFolders();
    });
    expect(view.picker.loading).toBe(true);
    expect(view.picker.loadError).toBe('معرفناش نحمّل قوائمك');
    expect(mockGetSavedFolderOptions.mock.calls).toEqual([
      [],
      [{requireFresh: true}],
    ]);
    expect(view.present).toHaveBeenCalledTimes(1);
    expect(view.picker.name).toBe('قائمتي');
    expect(mockCreateSavedFolderOption).not.toHaveBeenCalled();
    expect(view.onToggleSave).not.toHaveBeenCalled();

    await act(async () => freshRead.resolve([{id: '8', name: 'للمراجعة'}]));
    expect(view.picker.loadError).toBe('');
    expect(view.picker.loading).toBe(false);
    expect(view.picker.folders).toEqual([{id: '8', name: 'للمراجعة'}]);
    expect(view.picker.name).toBe('قائمتي');
    act(() => view.picker.saveInFolder(view.picker.folders[0]));
    expect(view.onToggleSave.mock.calls).toEqual([
      [{id: '8', name: 'للمراجعة'}],
    ]);
    await act(async () => view.renderer.unmount());
  });

  it('preserves known folders and the draft name after another failed read', async () => {
    mockGetSavedFolderOptions
      .mockResolvedValueOnce([{id: '8', name: 'للمراجعة'}])
      .mockRejectedValueOnce(new Error('offline'))
      .mockRejectedValueOnce(new Error('still offline'));
    const view = await mountPicker();
    await act(async () => view.picker.open());
    act(() => view.picker.close());
    await act(async () => view.picker.open());
    act(() => view.picker.setName('قائمتي'));
    await act(async () => view.picker.retryFolders());
    expect(view.picker.folders).toEqual([{id: '8', name: 'للمراجعة'}]);
    expect(view.picker.name).toBe('قائمتي');
    expect(view.picker.loadError).toBe('معرفناش نحمّل قوائمك');
    expect(view.picker.loading).toBe(false);
    expect(view.onToggleSave).not.toHaveBeenCalled();
    await act(async () => view.renderer.unmount());
  });

  it('does not turn a folder creation failure into a read retry', async () => {
    mockCreateSavedFolderOption.mockRejectedValueOnce(new Error('offline'));
    const view = await mountPicker();
    await act(async () => view.picker.open());
    act(() => view.picker.setName('قائمتي'));
    await act(async () => view.picker.createAndSave());
    act(() => view.picker.retryFolders());
    expect(view.picker.loadError).toBe('');
    expect(view.picker.error).toContain('معرفناش نعمل القائمة');
    expect(mockGetSavedFolderOptions).toHaveBeenCalledTimes(1);
    expect(mockCreateSavedFolderOption).toHaveBeenCalledTimes(1);
    await act(async () => view.renderer.unmount());
  });

  it('does not let read recovery retire an active creation', async () => {
    const creation = deferred<{id: string; name: string}>();
    mockGetSavedFolderOptions.mockRejectedValueOnce(new Error('offline'));
    mockCreateSavedFolderOption.mockReturnValueOnce(creation.promise);
    const view = await mountPicker();
    await act(async () => view.picker.open());
    act(() => view.picker.setName('قائمتي'));
    let creating!: Promise<void>;
    await act(async () => {
      creating = view.picker.createAndSave();
    });
    act(() => view.picker.retryFolders());
    expect(mockGetSavedFolderOptions).toHaveBeenCalledTimes(1);
    await act(async () => {
      creation.resolve({id: '8', name: 'قائمتي'});
      await creating;
    });
    expect(view.onToggleSave.mock.calls).toEqual([[{id: '8', name: 'قائمتي'}]]);
    await act(async () => view.renderer.unmount());
  });

  it('ignores late retry results and callbacks after closing and reopening', async () => {
    const oldRead = deferred<{id: string; name: string}[]>();
    mockGetSavedFolderOptions
      .mockRejectedValueOnce(new Error('offline'))
      .mockReturnValueOnce(oldRead.promise)
      .mockResolvedValueOnce([{id: '9', name: 'حالي'}]);
    const view = await mountPicker();
    await act(async () => view.picker.open());
    const oldVisit = view.picker;
    await act(async () => view.picker.retryFolders());
    act(() => view.picker.close());
    await act(async () => view.picker.open());
    act(() => view.picker.setName('اسم حالي'));
    act(() => oldVisit.retryFolders());
    expect(mockGetSavedFolderOptions).toHaveBeenCalledTimes(3);
    expect(mockGetSavedFolderOptions.mock.calls[2]).toEqual([]);
    await act(async () => oldRead.resolve([{id: '8', name: 'قديم'}]));
    expect(view.picker.folders).toEqual([{id: '9', name: 'حالي'}]);
    expect(view.picker.name).toBe('اسم حالي');
    expect(view.picker.loading).toBe(false);
    expect(view.picker.loadError).toBe('');
    expect(view.onToggleSave).not.toHaveBeenCalled();
    await act(async () => view.renderer.unmount());
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

describe('leaving one saved list from the reels owner', () => {
  const mountOwner = async () => {
    let saved!: ReturnType<typeof useReelsSavedLessons>;
    const setConnectionNote = jest.fn();
    const Harness = () => {
      saved = useReelsSavedLessons({
        loadedCourse: {current: {id: '3'}} as React.MutableRefObject<any>,
        mounted: {current: true},
        ownerGeneration: {current: 1},
        scopeKey: 'account-a:course-3',
        setConnectionNote,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    act(() => saved.setSavedLessons(new Set(['44'])));
    return {
      get saved() {
        return saved;
      },
      renderer,
      setConnectionNote,
    };
  };
  const reel = {lessonId: '44'} as any;
  const folder = {id: '8', name: 'للمراجعة'};

  beforeEach(() => {
    jest.clearAllMocks();
    mockScope = 'account-a';
    mockEpoch = 1;
    mockCaptureBoundary
      .mockReset()
      .mockImplementation(async () => ({epoch: mockEpoch, scope: mockScope}));
  });

  it.each([
    ['keeps the bookmark when the server says another list holds it', true, false, true],
    ['clears the bookmark when the server says no list holds it', false, true, false],
    ['falls back to the loaded membership when the server does not say', null, true, true],
  ] as const)('%s', async (_, stillSaved, hint, expected) => {
    const removal = deferred<{stillSaved: boolean | null}>();
    mockRemoveLessonFromSavedFolder.mockReturnValueOnce(removal.promise);
    const view = await mountOwner();
    let result!: Promise<boolean>;
    await act(async () => {
      result = view.saved.removeFromList(reel, folder, hint);
    });
    // Optimistic: the icon follows the sheet's membership view at once.
    expect(view.saved.savedLessons.has('44')).toBe(hint);
    expect(view.saved.savingLessons.has('44')).toBe(true);
    await act(async () => {
      removal.resolve({stillSaved});
      await expect(result).resolves.toBe(true);
    });
    expect(mockRemoveLessonFromSavedFolder).toHaveBeenCalledWith('44', '8');
    expect(mockToggleWatchLater).not.toHaveBeenCalled();
    expect(view.saved.savedLessons.has('44')).toBe(expected);
    expect(view.saved.savingLessons.has('44')).toBe(false);
    expect(view.setConnectionNote).not.toHaveBeenCalled();
    await act(async () => view.renderer.unmount());
  });

  it('restores the bookmark and reports the failure when the removal fails', async () => {
    mockRemoveLessonFromSavedFolder.mockRejectedValueOnce(new Error('offline'));
    const view = await mountOwner();
    await act(async () => {
      await expect(
        view.saved.removeFromList(reel, folder, false),
      ).rejects.toThrow('offline');
    });
    expect(view.saved.savedLessons.has('44')).toBe(true);
    expect(view.saved.savingLessons.has('44')).toBe(false);
    expect(view.setConnectionNote).toHaveBeenCalledWith(
      'معرفناش نحدّث المحفوظات\nاتأكد من النت وجرب تاني',
    );
    await act(async () => view.renderer.unmount());
  });

  it('does not overlap a removal with another command on the same reel', async () => {
    const removal = deferred<{stillSaved: boolean | null}>();
    mockRemoveLessonFromSavedFolder.mockReturnValueOnce(removal.promise);
    const view = await mountOwner();
    let first!: Promise<boolean>;
    await act(async () => {
      first = view.saved.removeFromList(reel, folder, true);
    });
    await act(async () => {
      await expect(view.saved.toggleSaved(reel, null)).resolves.toBe(false);
    });
    expect(mockToggleWatchLater).not.toHaveBeenCalled();
    await act(async () => {
      removal.resolve({stillSaved: true});
      await first;
    });
    await act(async () => view.renderer.unmount());
  });
});

describe('save sheet list membership', () => {
  const mountWithMembership = async (
    onRemoveFromList: jest.Mock<Promise<boolean>, any[]>,
  ) => {
    let picker!: ReturnType<typeof useSavedFolderPicker>;
    const onToggleSave = jest.fn();
    const dismiss = jest.fn();
    const Harness = () => {
      picker = useSavedFolderPicker({
        dismiss,
        lessonId: '44',
        onBeforeOpen: () => true,
        onRemoveFromList,
        onToggleSave,
        present: jest.fn(),
        scopeKey: '3:44',
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => picker.open());
    return {
      get picker() {
        return picker;
      },
      dismiss,
      onToggleSave,
      renderer,
    };
  };

  beforeEach(() => {
    jest.clearAllMocks();
    mockScope = 'account-a';
    mockEpoch = 1;
    mockCaptureBoundary
      .mockReset()
      .mockImplementation(async () => ({epoch: mockEpoch, scope: mockScope}));
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'المشاهدة لاحقًا'},
      {id: '8', name: 'للمراجعة'},
      {id: '9', name: 'أفكار'},
    ]);
    mockGetSavedLessonFolderMembership.mockResolvedValue({
      folderIds: ['7', '8'],
      isSaved: true,
    });
  });

  it('loads the lists that hold the lesson alongside the folder list', async () => {
    const view = await mountWithMembership(jest.fn(async () => true));
    expect(mockGetSavedLessonFolderMembership).toHaveBeenCalledWith('44');
    expect(Array.from(view.picker.membership ?? [])).toEqual(['7', '8']);
    expect(view.picker.watchLaterFolder).toEqual({
      id: '7',
      name: 'المشاهدة لاحقًا',
    });
    await act(async () => view.renderer.unmount());
  });

  it('keeps the sheet usable with add-only rows when membership cannot be read', async () => {
    mockGetSavedLessonFolderMembership.mockRejectedValueOnce(new Error('404'));
    const view = await mountWithMembership(jest.fn(async () => true));
    expect(view.picker.membership).toBeNull();
    expect(view.picker.loadError).toBe('');
    expect(view.picker.folders).toHaveLength(2);
    act(() => view.picker.toggleFolder(view.picker.folders[0]));
    expect(view.onToggleSave).toHaveBeenCalledWith({id: '8', name: 'للمراجعة'});
    await act(async () => view.renderer.unmount());
  });

  it('removes from one list in place, telling the owner another list remains', async () => {
    const removal = deferred<boolean>();
    const onRemoveFromList = jest.fn(() => removal.promise);
    const view = await mountWithMembership(onRemoveFromList);
    await act(async () => view.picker.toggleFolder({id: '8', name: 'للمراجعة'}));
    expect(onRemoveFromList).toHaveBeenCalledWith(
      {id: '8', name: 'للمراجعة'},
      true,
    );
    expect(view.picker.membership?.has('8')).toBe(false);
    expect(view.picker.removingFolderId).toBe('8');
    // A second command waits for the first.
    await act(async () => view.picker.toggleWatchLater());
    expect(onRemoveFromList).toHaveBeenCalledTimes(1);
    await act(async () => removal.resolve(true));
    expect(view.picker.membership?.has('8')).toBe(false);
    expect(view.picker.removingFolderId).toBeNull();
    expect(view.onToggleSave).not.toHaveBeenCalled();
    expect(view.dismiss).not.toHaveBeenCalled();

    // Watch later now holds it alone: leaving it unsaves the lesson.
    await act(async () => view.picker.toggleWatchLater());
    expect(onRemoveFromList).toHaveBeenLastCalledWith(
      {id: '7', name: 'المشاهدة لاحقًا'},
      false,
    );
    await act(async () => view.renderer.unmount());
  });

  it('rolls the row back and explains when the removal is not confirmed', async () => {
    const view = await mountWithMembership(jest.fn(async () => false));
    await act(async () => view.picker.toggleFolder({id: '8', name: 'للمراجعة'}));
    expect(view.picker.membership?.has('8')).toBe(true);
    expect(view.picker.removingFolderId).toBeNull();
    expect(view.picker.error).toBe(
      'معرفناش نحدّث المحفوظات\nاتأكد من النت وجرب تاني',
    );
    await act(async () => view.renderer.unmount());
  });

  it('adds to a list that does not hold the lesson with the existing save', async () => {
    const onRemoveFromList = jest.fn(async () => true);
    const view = await mountWithMembership(onRemoveFromList);
    act(() => view.picker.toggleFolder({id: '9', name: 'أفكار'}));
    expect(view.onToggleSave).toHaveBeenCalledWith({id: '9', name: 'أفكار'});
    expect(view.dismiss).toHaveBeenCalledTimes(1);
    expect(onRemoveFromList).not.toHaveBeenCalled();
    await act(async () => view.renderer.unmount());
  });

  it('does not let a removal that settles after closing touch the next visit', async () => {
    const removal = deferred<boolean>();
    const view = await mountWithMembership(jest.fn(() => removal.promise));
    await act(async () => view.picker.toggleFolder({id: '8', name: 'للمراجعة'}));
    act(() => view.picker.close());
    mockGetSavedLessonFolderMembership.mockResolvedValueOnce({
      folderIds: ['7'],
      isSaved: true,
    });
    await act(async () => view.picker.open());
    await act(async () => removal.resolve(false));
    expect(Array.from(view.picker.membership ?? [])).toEqual(['7']);
    expect(view.picker.error).toBe('');
    await act(async () => view.renderer.unmount());
  });
});
