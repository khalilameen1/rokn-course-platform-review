import React from 'react';
import {Pressable} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import type {useSavedLibrary} from '../src/screens/Profile/saved/useSavedLibrary';

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
});
