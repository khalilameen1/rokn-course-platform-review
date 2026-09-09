import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetSavedFolderOptions = jest.fn();
const mockCreateSavedFolderOption = jest.fn();
const mockSaveLessonToFolder = jest.fn();
const mockToggleWatchLater = jest.fn();
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
    expect(view.picker.error).toContain('تعذّر إنشاء القائمة');
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
