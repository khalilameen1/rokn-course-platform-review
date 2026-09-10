import React from 'react';
import {Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import CourseCard, {Course} from '../src/components/view/CourseCard';
import CoursesSection from '../src/components/view/CoursesSection';
import CarouselItem from '../src/components/view/CarouselItem';
import {CoinAmount} from '../src/components/ui/RoknCoin';
import {cleanUnicodeText} from '../src/utils/unicodeText';

jest.mock('../src/constants/designSystem', () => ({
  Accessibility: {minTouchTarget: 48},
  Palette: {},
  Radius: {},
  Spacing: {},
  Type: {},
  rtlRowStyle: {},
  textDirection: {},
  useResponsiveLayout: () => ({
    largeText: false,
    railCardWidth: 180,
    gutter: 16,
    contentWidth: 390,
    isTablet: false,
  }),
}));
jest.mock('../src/assets/SVG', () => ({ArrowRight: () => null}));
jest.mock('../src/components/ui/PremiumUI', () => {
  const {Text: MockText} = require('react-native');
  return {
    MetaPill: ({label}: {label: string}) => <MockText>{label}</MockText>,
    SectionHeading: ({title}: {title: string}) => <MockText>{title}</MockText>,
  };
});
jest.mock('../src/components/ui/CourseArtwork', () => ({
  CourseArtwork: () => null,
}));
jest.mock('../src/components/ui/RoknCoin', () => {
  const {Text: MockText} = require('react-native');
  return {
    CoinAmount: ({value}: {value: number}) => <MockText>{value}</MockText>,
  };
});

const upcoming: Course = {
  id: '11',
  title: 'تصوير بالموبايل',
  description: '',
  instructor: '',
  image: {uri: 'https://example.com/cover.png'},
  category: 'skills',
  label: 'قريبًا',
  published: false,
  coinPrice: 400,
};
const onPress = jest.fn();

describe('course card labels', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const render = async (element: React.ReactElement) => {
    await act(async () => {
      renderer = TestRenderer.create(element);
    });
  };
  const visibleCount = (value: string | number) =>
    renderer.root
      .findAllByType(Text)
      .filter(node => node.props.children === value).length;
  afterEach(async () => {
    await act(async () => {
      renderer?.unmount();
    });
  });

  it('shows coming soon once for the whole named row and never a price', async () => {
    await render(
      <CoursesSection
        data={[upcoming]}
        title="قريبًا"
        onCoursePress={onPress}
      />,
    );
    expect(visibleCount('قريبًا')).toBe(1);
    expect(visibleCount(400)).toBe(0);
  });

  it('keeps one availability label outside its named row', async () => {
    await render(<CourseCard item={upcoming} onPress={onPress} />);
    expect(visibleCount('قريبًا')).toBe(1);
    expect(visibleCount(400)).toBe(0);
  });

  it('updates badge visibility when the row title changes without changing the course', async () => {
    const data = [upcoming];
    await render(
      <CoursesSection data={data} title="قريبًا" onCoursePress={onPress} />,
    );
    await act(async () => {
      renderer.update(
        <CoursesSection
          data={data}
          title="نتائج البحث"
          onCoursePress={onPress}
        />,
      );
    });
    expect(visibleCount('قريبًا')).toBe(1);
  });

  it('preserves distinct badges without the published course price', async () => {
    await render(
      <CourseCard
        item={{...upcoming, published: true, label: 'جديد'}}
        sectionTitle="مختارات"
        onPress={onPress}
      />,
    );
    expect(visibleCount('جديد')).toBe(1);
    expect(visibleCount(400)).toBe(0);
    expect(renderer.root.findAllByType(CoinAmount)).toHaveLength(0);
  });

  it.each(['مختارات', 'نتائج البحث'])(
    'hides coin amounts from paid cards in %s while keeping the details action',
    async sectionTitle => {
      const paidCourse = {...upcoming, published: true, label: 'جديد'};
      const onCoursePress = jest.fn();
      await render(
        <CoursesSection
          data={[paidCourse]}
          title={sectionTitle}
          onCoursePress={onCoursePress}
        />,
      );
      expect(visibleCount(400)).toBe(0);
      expect(renderer.root.findAllByType(CoinAmount)).toHaveLength(0);
      const button = renderer.root.find(
        node =>
          node.props.accessibilityRole === 'button' &&
          typeof node.props.onPress === 'function',
      );
      expect(cleanUnicodeText(button.props.accessibilityLabel)).toBe(
        paidCourse.title,
      );
      await act(async () => button.props.onPress());
      expect(onCoursePress).toHaveBeenCalledTimes(1);
      expect(onCoursePress).toHaveBeenCalledWith(paidCourse);
    },
  );

  it.each([
    {owned: false, coinPrice: 0, started: false, progress: 0, state: 'مجاني'},
    {
      owned: true,
      coinPrice: 400,
      started: false,
      progress: 0,
      state: 'ضمن كورساتك',
    },
    {
      owned: true,
      coinPrice: 400,
      started: true,
      progress: 20,
      state: 'قيد التعلّم',
    },
    {
      owned: true,
      coinPrice: 400,
      started: true,
      progress: 100,
      state: 'راجع الكورس',
    },
  ])(
    'preserves $state without rendering a coin amount',
    async ({state, ...status}) => {
      await render(
        <CourseCard
          item={{...upcoming, ...status, published: true, label: 'جديد'}}
          onPress={onPress}
        />,
      );
      expect(visibleCount(state)).toBe(1);
      expect(visibleCount(400)).toBe(0);
      expect(renderer.root.findAllByType(CoinAmount)).toHaveLength(0);
    },
  );

  it.each([
    {published: true, owned: false, coinPrice: 400, state: ''},
    {published: true, owned: false, coinPrice: 0, state: 'مجاني'},
    {published: true, owned: true, coinPrice: 400, state: 'ضمن كورساتك'},
    {published: false, owned: false, coinPrice: 400, state: 'قريبًا'},
  ])(
    'announces the featured course title instructor and $state as one details action',
    async ({state, ...availability}) => {
      const onButtonPress = jest.fn();
      await render(
        <CarouselItem
          course={{
            ...upcoming,
            ...availability,
            instructor: 'مدرب ركن',
          }}
          onButtonPress={onButtonPress}
        />,
      );
      const button = renderer.root.find(
        node =>
          node.props.accessibilityRole === 'button' &&
          typeof node.props.onPress === 'function',
      );
      expect(button.props.accessibilityRole).toBe('button');
      expect(cleanUnicodeText(button.props.accessibilityLabel)).toBe(
        ['تصوير بالموبايل', 'مدرب ركن', state].filter(Boolean).join(' — '),
      );
      expect(button.props.accessibilityHint).toBe('يفتح تفاصيل الكورس');
      expect(visibleCount(400)).toBe(0);
      expect(renderer.root.findAllByType(CoinAmount)).toHaveLength(0);
      expect(button.props.accessibilityLabel).not.toContain('400');
      expect(button.props.accessibilityLabel).not.toContain('عملة');

      await act(async () => button.props.onPress());
      expect(onButtonPress).toHaveBeenCalledTimes(1);
    },
  );
});
