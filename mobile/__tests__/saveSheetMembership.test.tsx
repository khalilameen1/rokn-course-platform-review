import React from 'react';
import {Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetSavedFolderOptions = jest.fn();
const mockGetSavedLessonFolderMembership = jest.fn();

jest.mock('react-redux', () => ({
  useSelector: () => ({api_token: 'token-a', user: {id: 7}}),
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: () => undefined,
  captureAccountSessionBoundary: async () => ({epoch: 1, scope: 'account-a'}),
  sessionIdentityKey: () => 'account-a',
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  createSavedFolderOption: jest.fn(),
  getSavedFolderOptions: (...args: unknown[]) =>
    mockGetSavedFolderOptions(...args),
  getSavedLessonFolderMembership: (...args: unknown[]) =>
    mockGetSavedLessonFolderMembership(...args),
}));
jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react') as typeof React;
    ReactModule.useEffect(effect, [effect]);
  },
}));
jest.mock('@gorhom/bottom-sheet', () => {
  const ReactModule = require('react');
  const {View} = require('react-native');
  return {
    BottomSheetBackdrop: View,
    BottomSheetScrollView: View,
    BottomSheetModal: ReactModule.forwardRef(
      ({children}: {children: React.ReactNode}, _ref: unknown) => (
        <View>{children}</View>
      ),
    ),
  };
});
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => false,
}));
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/CourseIndexModule',
  () => () => null,
);
jest.mock('../src/components/ui/CopyIcon', () => ({CopyIcon: () => null}));
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/useAttachmentPrompt',
  () => ({
    useAttachmentPrompt: () => ({
      attachments: [],
      markAttachmentsVisible: jest.fn(),
      openAttachments: jest.fn(),
    }),
  }),
);
jest.mock('../src/components/VideoPlayer/attachmentActions', () => ({
  openCourseAttachment: jest.fn(),
}));

import FeedSideBar from '../src/components/VideoPlayer/FeedSideBar';
import type {
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';

const reel: CourseReel = {
  id: '1',
  lessonId: '44',
  sectionId: '1',
  moduleId: '1',
  title: 'المقطع',
  caption: '',
  videoUrl: 'https://example.com/video',
  availableQualities: ['auto'],
  isPreview: false,
  isLocked: false,
  isCompleted: false,
  reelNumber: 1,
};
const course: CourseLearningData = {
  id: '10',
  title: 'الكورس',
  totalReels: 1,
  modules: [],
  attachments: [],
};

const deferred = () => {
  let resolve!: (value: boolean) => void;
  const promise = new Promise<boolean>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('save sheet rows reflect and toggle list membership', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  let onToggleSave: jest.Mock<Promise<boolean>, unknown[]>;
  let onRemoveFromList: jest.Mock<Promise<boolean>, unknown[]>;

  const element = (isSaved: boolean, savePending = false) => (
    <FeedSideBar
      course={course}
      currentReel={reel}
      currentFeedKey="1"
      isSaved={isSaved}
      savePending={savePending}
      onToggleSave={onToggleSave}
      onRemoveFromList={onRemoveFromList}
      onBeforeOpenSave={() => true}
      onOpenChat={jest.fn()}
      onSelectFeedItem={jest.fn()}
      currentTime={0}
    />
  );
  const openSheet = async (isSaved = true) => {
    await act(async () => {
      renderer = TestRenderer.create(element(isSaved));
    });
    const saveButton = renderer.root.findAll(
      node =>
        node.props.accessibilityLabel === 'محفوظ' &&
        typeof node.props.onPress === 'function',
    )[0];
    await act(async () => saveButton.props.onPress());
  };
  /** The pressable list row whose first text is the list name. */
  const row = (name: string) =>
    renderer.root.findAll(
      node =>
        typeof node.props.onPress === 'function' &&
        node.props.accessibilityRole === 'button' &&
        node.findAllByType(Text)[0]?.props.children === name,
    )[0];
  const rowAction = (name: string) =>
    row(name).findAllByType(Text)[1].props.children;
  const hasText = (text: string) =>
    renderer.root.findAllByType(Text).some(node => node.props.children === text);

  beforeEach(() => {
    jest.clearAllMocks();
    onToggleSave = jest.fn(async () => true);
    onRemoveFromList = jest.fn(async () => true);
    mockGetSavedFolderOptions.mockResolvedValue([
      {id: '7', name: 'المشاهدة لاحقًا'},
      {id: '8', name: 'للمراجعة'},
      {id: '9', name: 'أفكار تصميم'},
    ]);
    mockGetSavedLessonFolderMembership.mockResolvedValue({
      folderIds: ['7', '8'],
      isSaved: true,
    });
  });
  afterEach(async () => {
    await act(async () => renderer.unmount());
  });

  it('labels each list by whether it holds the lesson', async () => {
    await openSheet();
    expect(mockGetSavedLessonFolderMembership).toHaveBeenCalledWith('44');
    expect(rowAction('المشاهدة لاحقًا')).toBe('اتحفظ');
    expect(rowAction('للمراجعة')).toBe('اتحفظ');
    expect(rowAction('أفكار تصميم')).toBe('ضيفه');
    expect(row('المشاهدة لاحقًا').props.accessibilityState).toMatchObject({
      disabled: false,
      selected: true,
    });
    expect(row('للمراجعة').props.accessibilityState.selected).toBe(true);
    expect(row('أفكار تصميم').props.accessibilityState.selected).toBe(false);
    expect(hasText('شيله من المحفوظات')).toBe(true);
  });

  it('removes from that list only, flipping the row at once', async () => {
    const removal = deferred();
    onRemoveFromList.mockReturnValueOnce(removal.promise);
    await openSheet();
    await act(async () => row('للمراجعة').props.onPress());

    expect(onRemoveFromList).toHaveBeenCalledWith(
      {id: '8', name: 'للمراجعة'},
      true,
    );
    expect(onToggleSave).not.toHaveBeenCalled();
    expect(rowAction('للمراجعة')).toBe('ضيفه');
    // Every row waits while the command is pending.
    expect(row('أفكار تصميم').props.disabled).toBe(true);
    expect(row('المشاهدة لاحقًا').props.disabled).toBe(true);

    await act(async () => removal.resolve(true));
    expect(rowAction('للمراجعة')).toBe('ضيفه');
    expect(rowAction('المشاهدة لاحقًا')).toBe('اتحفظ');
    expect(row('أفكار تصميم').props.disabled).toBe(false);
  });

  it('removes from watch later only when its row is tapped while saved', async () => {
    await openSheet();
    await act(async () => row('المشاهدة لاحقًا').props.onPress());
    expect(onRemoveFromList).toHaveBeenCalledWith(
      {id: '7', name: 'المشاهدة لاحقًا'},
      true,
    );
    expect(onToggleSave).not.toHaveBeenCalled();
    expect(rowAction('المشاهدة لاحقًا')).toBe('حفظ');
    expect(rowAction('للمراجعة')).toBe('اتحفظ');
  });

  it('puts the row back and explains when the removal fails', async () => {
    onRemoveFromList.mockResolvedValueOnce(false);
    await openSheet();
    await act(async () => row('للمراجعة').props.onPress());
    expect(rowAction('للمراجعة')).toBe('اتحفظ');
    expect(row('للمراجعة').props.accessibilityState.selected).toBe(true);
    expect(hasText('معرفناش نحدّث المحفوظات\nاتأكد من النت وجرب تاني')).toBe(
      true,
    );
  });

  it('adds to a list that does not hold the lesson with the existing save', async () => {
    await openSheet();
    await act(async () => row('أفكار تصميم').props.onPress());
    expect(onToggleSave).toHaveBeenCalledWith({id: '9', name: 'أفكار تصميم'});
    expect(onRemoveFromList).not.toHaveBeenCalled();
  });

  it('keeps add-only rows when the membership read fails', async () => {
    mockGetSavedLessonFolderMembership.mockRejectedValueOnce(new Error('404'));
    await openSheet();
    expect(rowAction('للمراجعة')).toBe('ضيفه');
    expect(row('للمراجعة').props.accessibilityState.selected).toBe(false);
    await act(async () => row('للمراجعة').props.onPress());
    expect(onToggleSave).toHaveBeenCalledWith({id: '8', name: 'للمراجعة'});
    expect(onRemoveFromList).not.toHaveBeenCalled();
  });
});
