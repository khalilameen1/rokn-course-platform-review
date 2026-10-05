import React from 'react';
import {Text, TextInput} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockPage = jest.fn();
const mockTrack = jest.fn(async (_event: unknown) => undefined);
const mockStatusTitles: string[] = [];
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: jest.fn()}),
  useIsFocused: () => true,
}));
jest.mock('react-redux', () => ({useSelector: () => null}));
jest.mock('react-i18next', () => ({
  useTranslation: () => ({t: (key: string) => key}),
}));
jest.mock('../src/constants/helpers', () => ({
  sessionIdentityKey: () => 'guest',
}));
jest.mock('../src/services/roknApi', () => ({
  getPublishedCoursesPage: (...args: unknown[]) => mockPage(...args),
  getCachedPublishedCourses: jest.fn(async () => []),
  subscribeToUnavailableCourses: jest.fn(() => () => undefined),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: (event: unknown) => mockTrack(event),
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
jest.mock('../src/screens/home/useCourseAccessOverlay', () => ({
  useCourseAccessOverlay: () => ({
    courses: [],
    session: false,
    refresh: jest.fn(async () => undefined),
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
jest.mock('../src/screens/home/HomeOverlays', () => ({
  HomeOverlays: () => null,
}));
jest.mock('../src/screens/appInitializer/StartupExperience', () => ({
  useStartupExperience: () => null,
}));
jest.mock('../src/components/TabBar', () => () => null);
jest.mock('../src/components/search/SearchAssist', () => () => null);
jest.mock('../src/assets/SVG', () => ({
  SearchIcon: () => null,
  NotificationIcon: () => null,
}));
jest.mock('../src/components/containers/Containers', () => {
  const ReactModule = require('react');
  const {View} = require('react-native');
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  return {Container: Wrapper, Content: Wrapper};
});
jest.mock('../src/components/ui/PremiumUI', () => {
  const ReactModule = require('react');
  const {Text: NativeText, View} = require('react-native');
  return {
    ResponsiveFrame: ({children}: {children?: React.ReactNode}) =>
      ReactModule.createElement(View, null, children),
    StatusView: ({title}: {title: string}) => {
      mockStatusTitles.push(title);
      return ReactModule.createElement(NativeText, null, title);
    },
  };
});
jest.mock('../src/components/ui/Skeleton', () => ({
  CatalogueSkeleton: () => null,
}));
jest.mock('../src/components/view/CourseCarousel', () => () => null);
jest.mock('../src/components/view/CoursesSection', () => {
  const ReactModule = require('react');
  const {Text: NativeText, View} = require('react-native');
  return ({
    data,
    title,
  }: {
    data: {id: string; title: string}[];
    title: string;
  }) =>
    ReactModule.createElement(
      View,
      null,
      ReactModule.createElement(NativeText, null, title),
      ...data.map(course =>
        ReactModule.createElement(NativeText, {key: course.id}, course.title),
      ),
    );
});

// Keep Home, its public catalogue join, search selector and feed real. Only the
// transports and unrelated account, overlays and leaf styling are controlled.
import Home from '../src/screens/Home';

type Page = Awaited<
  ReturnType<typeof import('../src/services/roknApi').getPublishedCoursesPage>
>;
const page = (id?: string): Page => ({
  courses: id ? [{id, title: id} as Page['courses'][number]] : [],
  page: 1,
  hasMore: false,
  total: id ? 1 : 0,
  revision: 12,
  reset: false,
  fromCache: false,
});
const deferred = () => {
  let resolve!: (value: Page) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<Page>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const zeroEvents = () =>
  mockTrack.mock.calls.filter(
    ([event]) =>
      (event as {event_name: string}).event_name === 'search_zero_results',
  );

describe('Home renders and records only settled search results', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const input = () => renderer!.root.findByType(TextInput);
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Home />);
    });
    await act(async () => {
      renderer!.root
        .find(
          node =>
            node.props.accessibilityLabel === 'البحث عن كورس' &&
            typeof node.props.onPress === 'function',
        )
        .props.onPress();
    });
    mockStatusTitles.length = 0;
    mockTrack.mockClear();
  };
  const type = async (value: string) => {
    await act(async () => input().props.onChangeText(value));
  };
  const request = async () => {
    await act(async () => jest.advanceTimersByTime(350));
  };
  const text = () =>
    renderer!.root.findAllByType(Text).map(node => node.props.children);
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockStatusTitles.length = 0;
    mockPage.mockReset().mockResolvedValue(page('home'));
  });
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it.each([true, false])(
    'does not render or record an empty search before its reply hasMatches=%s',
    async hasMatches => {
      await mount();
      const reply = deferred();
      mockPage.mockReturnValueOnce(reply.promise);
      await type('تصميم');
      expect(mockPage).toHaveBeenCalledTimes(1);
      expect(mockStatusTitles).not.toContain('لم نجد نتيجة مطابقة');
      expect(zeroEvents()).toHaveLength(0);
      await request();
      expect(zeroEvents()).toHaveLength(0);
      expect(mockStatusTitles).not.toContain('لم نجد نتيجة مطابقة');
      await act(async () =>
        reply.resolve(page(hasMatches ? 'design-result' : undefined)),
      );
      if (hasMatches) {
        expect(text()).toContain('design-result');
        expect(mockStatusTitles).not.toContain('لم نجد نتيجة مطابقة');
        expect(zeroEvents()).toHaveLength(0);
      } else {
        expect(text()).toContain('لم نجد نتيجة مطابقة');
        expect(zeroEvents()).toHaveLength(1);
        expect(zeroEvents()[0][0]).toMatchObject({
          screen_key: 'search',
          value: 5,
        });
      }
    },
  );

  it('shows a current failure rather than counting it as no matching courses', async () => {
    await mount();
    mockPage.mockRejectedValueOnce(new Error('offline'));
    await type('تصميم');
    await request();
    expect(text()).toContain('تعذّر البحث الآن');
    expect(mockStatusTitles).not.toContain('لم نجد نتيجة مطابقة');
    expect(zeroEvents()).toHaveLength(0);
    const reply = deferred();
    mockPage.mockReturnValueOnce(reply.promise);
    await type('برمجة');
    expect(text()).not.toContain('تعذّر البحث الآن');
    expect(mockStatusTitles).not.toContain('لم نجد نتيجة مطابقة');
    await request();
    await act(async () => reply.resolve(page('programming-result')));
    expect(text()).toContain('programming-result');
    expect(zeroEvents()).toHaveLength(0);
  });

  it('does not announce or count an abandoned empty reply as the next query result', async () => {
    await mount();
    const oldReply = deferred();
    mockPage.mockReturnValueOnce(oldReply.promise);
    await type('تصميم');
    await request();
    const nextReply = deferred();
    mockPage.mockReturnValueOnce(nextReply.promise);
    await type('برمجة');
    await request();
    await act(async () => oldReply.resolve(page()));
    expect(zeroEvents()).toHaveLength(0);
    expect(mockStatusTitles).not.toContain('لم نجد نتيجة مطابقة');
    await act(async () => nextReply.resolve(page('programming-result')));
    expect(text()).toContain('programming-result');
    expect(zeroEvents()).toHaveLength(0);
  });

  it('does not recount an accepted empty result for an equivalent Arabic spelling', async () => {
    await mount();
    mockPage.mockResolvedValueOnce(page());
    await type('إدارة');
    await request();
    expect(zeroEvents()).toHaveLength(1);
    const calls = mockPage.mock.calls.length;
    await type('اداره');
    await request();
    expect(mockPage).toHaveBeenCalledTimes(calls);
    expect(zeroEvents()).toHaveLength(1);
  });
});
