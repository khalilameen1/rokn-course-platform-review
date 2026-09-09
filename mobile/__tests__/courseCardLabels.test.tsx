import React from 'react';
import {Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import CourseCard, {Course} from '../src/components/view/CourseCard';
import CoursesSection from '../src/components/view/CoursesSection';

jest.mock('../src/constants/designSystem', () => ({
  Palette: {},
  Radius: {},
  Spacing: {},
  Type: {},
  textDirection: {},
  useResponsiveLayout: () => ({
    largeText: false,
    railCardWidth: 180,
    gutter: 16,
  }),
}));
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
  return {CoinAmount: ({value}: {value: number}) => <MockText>{value}</MockText>};
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

  it('preserves distinct badges and the published course price', async () => {
    await render(
      <CourseCard
        item={{...upcoming, published: true, label: 'جديد'}}
        sectionTitle="مختارات"
        onPress={onPress}
      />,
    );
    expect(visibleCount('جديد')).toBe(1);
    expect(visibleCount(400)).toBe(1);
  });
});
