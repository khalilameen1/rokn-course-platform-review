import React from 'react';
import {Pressable, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import type {useSavedLibrary} from '../src/screens/Profile/saved/useSavedLibrary';
import type {SavedLesson} from '../src/services/roknApi';

let mockLibrary: ReturnType<typeof useSavedLibrary>;
jest.mock('react-native', () =>
  Object.create(jest.requireActual('react-native'), {
    Pressable: {value: 'Pressable'},
    ScrollView: {value: 'ScrollView'},
  }),
);
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: jest.fn()}),
}));
jest.mock('react-native-gesture-handler', () => ({Swipeable: 'Swipeable'}));
jest.mock('../src/components/ui/PremiumUI', () => ({
  StatusView: 'StatusView',
  SectionHeading: 'SectionHeading',
}));
jest.mock('../src/components/ui/Skeleton', () => ({
  SavedLibrarySkeleton: 'SavedLibrarySkeleton',
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('../src/screens/Profile/saved/useSavedLibrary', () => ({
  useSavedLibrary: () => mockLibrary,
}));

import SavedVideos from '../src/screens/Profile/SavedVideos';

describe('saved folder navigation during scoped reads', () => {
  beforeEach(() => {
    mockLibrary = {
      activeFolderId: '7',
      identityOwned: true,
      serverSession: true,
      saved: [],
      visibleSaved: [],
      groupedSaved: [],
      folderOptions: [{id: '7', name: 'قائمتي', lessonsCount: 21}],
      folderCounts: new Map([['7', 21]]),
      loading: false,
      error: '',
      actionError: '',
      folderError: '',
      folderLoadError: '',
      nextPage: null,
      newFolderName: '',
      removingSaved: new Set(),
      selectFolder: jest.fn(),
      retry: jest.fn(),
    } as unknown as ReturnType<typeof useSavedLibrary>;
  });

  it.each(['loading', 'error'])(
    'keeps the all and folder chips usable during %s',
    async state => {
      mockLibrary.loading = state === 'loading';
      mockLibrary.error = state === 'error' ? 'تعذّر الاتصال' : '';
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<SavedVideos />);
      });
      try {
        const chips = renderer.root
          .findAllByType(Pressable)
          .filter(
            item =>
              typeof item.props.accessibilityState?.selected === 'boolean',
          );
        expect(chips).toHaveLength(2);
        act(() => chips[0].props.onPress());
        expect(mockLibrary.selectFolder).toHaveBeenCalledWith('all');
        expect(chips[1].props.accessibilityState.selected).toBe(true);
        if (state === 'error') {
          const status = renderer.root.findAll(
            item => item.type === ('StatusView' as unknown),
          )[0];
          expect(status.props.state).toBe('error');
          act(() => status.props.onAction());
          expect(mockLibrary.retry).toHaveBeenCalledTimes(1);
        }
      } finally {
        await act(async () => renderer.unmount());
      }
    },
  );

  it('describes an empty selected folder without claiming the entire library is empty', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<SavedVideos />);
    });
    try {
      const status = renderer.root.findAll(
        item => item.type === ('StatusView' as unknown),
      )[0];
      expect(status.props.title).toBe('لا توجد مقاطع في هذه القائمة');
    } finally {
      await act(async () => renderer.unmount());
    }
  });

  it('keeps playable lessons beside a recoverable folder-index error', async () => {
    const lesson: SavedLesson = {
      id: 'lesson-1',
      courseId: 'course-1',
      courseTitle: 'الكورس',
      title: 'المقطع المحفوظ',
      duration: '01:00',
      folderId: '7',
      folderName: 'قائمتي',
    };
    mockLibrary.saved = [lesson];
    mockLibrary.visibleSaved = [lesson];
    mockLibrary.groupedSaved = [['7', {name: 'قائمتي', items: [lesson]}]];
    mockLibrary.folderLoadError = 'تعذّر تحديث القوائم';
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<SavedVideos />);
    });
    try {
      const notice = renderer.root.findByProps({
        accessibilityLabel: 'إعادة تحميل المحفوظات والقوائم',
      });
      expect(notice.props.disabled).toBe(false);
      expect(
        notice.findAllByType(Text).map(item => item.props.children),
      ).toContain('تعذّر تحديث القوائم');
      expect(
        renderer.root.findByProps({accessibilityLabel: 'تشغيل المقطع المحفوظ'}),
      ).toBeTruthy();
      expect(
        renderer.root.findAll(item => item.type === ('StatusView' as unknown)),
      ).toHaveLength(0);
      act(() => notice.props.onPress());
      expect(mockLibrary.retry).toHaveBeenCalledTimes(1);

      mockLibrary.loading = true;
      await act(async () => renderer.update(<SavedVideos />));
      const busyNotice = renderer.root.findByProps({
        accessibilityLabel: 'إعادة تحميل المحفوظات والقوائم',
      });
      expect(busyNotice.props.disabled).toBe(true);
      expect(busyNotice.props.accessibilityState.busy).toBe(true);
      expect(
        renderer.root.findByProps({accessibilityLabel: 'تشغيل المقطع المحفوظ'}),
      ).toBeTruthy();
    } finally {
      await act(async () => renderer.unmount());
    }
  });

  it('does not turn a successful last-item removal into a full library failure', async () => {
    mockLibrary.activeFolderId = 'all';
    mockLibrary.folderLoadError = 'تمت إزالة المقطع\nتعذّر تحديث عدد المقاطع';
    mockLibrary.folderCounts = new Map();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<SavedVideos />);
    });
    try {
      const status = renderer.root.findAll(
        item => item.type === ('StatusView' as unknown),
      )[0];
      expect(status.props.state).toBe('empty');
      expect(status.props.title).toBe('لا توجد مقاطع محفوظة');
      const notice = renderer.root.findByProps({
        accessibilityLabel: 'إعادة تحميل المحفوظات والقوائم',
      });
      act(() => notice.props.onPress());
      expect(mockLibrary.retry).toHaveBeenCalledTimes(1);
    } finally {
      await act(async () => renderer.unmount());
    }
  });

  it('shows one primary recovery action when both reads fail without lessons', async () => {
    mockLibrary.error = 'تعذّر تحميل المحفوظات';
    mockLibrary.folderLoadError = 'تعذّر تحديث القوائم';
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<SavedVideos />);
    });
    try {
      expect(
        renderer.root.findAllByProps({
          accessibilityLabel: 'إعادة تحميل المحفوظات والقوائم',
        }),
      ).toHaveLength(0);
      const status = renderer.root.findAll(
        item => item.type === ('StatusView' as unknown),
      )[0];
      expect(status.props.state).toBe('error');
      act(() => status.props.onAction());
      expect(mockLibrary.retry).toHaveBeenCalledTimes(1);
    } finally {
      await act(async () => renderer.unmount());
    }
  });
});
