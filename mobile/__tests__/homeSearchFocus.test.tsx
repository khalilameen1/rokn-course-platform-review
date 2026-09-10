import React from 'react';
import {TextInput} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockNavigate = jest.fn();
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: mockNavigate}),
  useIsFocused: () => true,
}));
jest.mock('react-redux', () => ({useSelector: () => null}));
jest.mock('react-i18next', () => ({
  useTranslation: () => ({t: (key: string) => key}),
}));
jest.mock('../src/constants/helpers', () => ({
  sessionIdentityKey: () => 'guest',
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(async () => undefined),
}));
jest.mock('../src/services/searchHistory', () => ({
  getSearchHistory: jest.fn(async () => []),
  clearSearchHistory: jest.fn(async () => undefined),
  rememberSearch: jest.fn(async (query: string) => [query]),
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppActiveState: () => true,
  useAppForegroundState: () => true,
}));
jest.mock('../src/screens/home/useHomeCatalogue', () => ({
  useHomeCatalogue: () => ({
    catalogue: [],
    remoteCourses: [],
    loading: false,
    error: '',
    handleScroll: jest.fn(),
    loadedSearchQuery: '',
    serverSession: false,
  }),
}));
jest.mock('../src/screens/home/useHomeScrollMemory', () => ({
  useHomeScrollMemory: () => ({
    bind: jest.fn(),
    record: jest.fn(),
    markUserMoved: jest.fn(),
  }),
}));
jest.mock('../src/screens/home/useHomeEngagement', () => ({
  useHomeEngagement: () => ({}),
}));
jest.mock('../src/screens/home/HomeCatalogueFeed', () => () => null);
jest.mock('../src/screens/home/HomeOverlays', () => ({
  HomeOverlays: () => null,
}));
jest.mock('../src/components/TabBar', () => () => null);
jest.mock('../src/components/search/SearchAssist', () => () => null);
jest.mock('../src/assets/SVG', () => ({
  SearchIcon: () => null,
  NotificationIcon: () => null,
}));
jest.mock('../src/components/containers/Containers', () => ({
  Container: ({children}: {children: React.ReactNode}) => children,
  Content: ({children}: {children: React.ReactNode}) => children,
}));
jest.mock('../src/components/ui/PremiumUI', () => ({
  ResponsiveFrame: ({children}: {children: React.ReactNode}) => children,
}));

import Home from '../src/screens/Home';

describe('Home search focus', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;

  beforeEach(() => jest.useFakeTimers());
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it('refocuses the existing query input after blur without clearing or remounting it', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Home />);
    });
    const searchButton = () =>
      renderer!.root.find(
        node =>
          node.props.accessibilityLabel === 'البحث عن كورس' &&
          typeof node.props.onPress === 'function',
      );
    expect(renderer!.root.findAllByType(TextInput)).toHaveLength(0);

    await act(async () => searchButton().props.onPress());
    const input = renderer!.root.findByType(TextInput);
    const mountedInput = input.instance;
    expect(input.props.autoFocus).toBe(true);
    await act(async () => {
      input.props.onFocus();
      input.props.onChangeText('التصميم');
    });
    await act(async () => {
      renderer!.root.findByType(TextInput).props.onBlur();
      jest.advanceTimersByTime(120);
    });

    const focus = jest.spyOn(mountedInput, 'focus');
    focus.mockClear();
    await act(async () => searchButton().props.onPress());

    expect(focus).toHaveBeenCalledTimes(1);
    const refocusedInput = renderer!.root.findByType(TextInput);
    expect(refocusedInput.instance).toBe(mountedInput);
    expect(refocusedInput.props.value).toBe('التصميم');
    expect(searchButton().props.accessibilityState.expanded).toBe(true);
  });
});
