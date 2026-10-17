import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

let mockParams: {folderId?: string} | undefined;

jest.mock('@react-navigation/native', () => ({
  useRoute: () => ({key: 'saved', name: 'SavedLibrary', params: mockParams}),
}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('react-redux', () => ({
  useSelector: (select: (state: unknown) => unknown) =>
    select({auth: {userData: {id: 1}}}),
}));
jest.mock('../src/constants/helpers', () => ({
  sessionIdentityKey: () => 'learner',
}));
jest.mock('../src/components/containers/Containers', () => ({
  Container: ({children}: {children: React.ReactNode}) => children,
  Content: ({children}: {children: React.ReactNode}) => children,
}));
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/screens/savedLibrary/SavedVideos', () => () => null);

import SavedLibrary from '../src/screens/SavedLibrary';
import SavedVideos from '../src/screens/savedLibrary/SavedVideos';

describe('saved library route', () => {
  it.each([
    [{folderId: '8'}, '8'],
    [undefined, undefined],
  ])('opens with params %p on list %p', async (params, folderId) => {
    mockParams = params;
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<SavedLibrary />);
    });
    try {
      expect(renderer.root.findByType(SavedVideos).props.initialFolderId).toBe(
        folderId,
      );
    } finally {
      await act(async () => renderer.unmount());
    }
  });
});
