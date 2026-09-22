import React from 'react';
import {Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {CourseAbout} from '../src/screens/CourseDetails/details/CourseAbout';
import {LockedOutline} from '../src/screens/CourseDetails/details/sections';
import type {CourseDetails} from '../src/services/roknApi';

jest.mock('../src/assets/SVG', () => ({
  AccordionArrowDown: 'Down',
  AccordionArrowUp: 'Up',
  ArrowRight: 'Back',
}));
jest.mock('react-native-svg', () => ({
  __esModule: true,
  default: 'Svg',
  Path: 'Path',
}));
jest.mock('../src/screens/CourseDetails/Lessons', () => 'Lessons');

const details = {
  id: '7',
  description: 'وصف الكورس الأصلي كاملًا',
  instructor: 'اسم المدرب',
  instructorBio: 'خبرة المدرب كاملة',
  modules: [
    {
      id: '1',
      title: 'بناء الإعلان',
      reelCount: 2,
      projectCount: 1,
      items: [
        {
          id: 'preview',
          reelId: 'reel-7',
          type: 'reel',
          title: 'الفكرة',
          isPreview: true,
        },
        {id: 'locked', type: 'reel', title: 'التنفيذ', isPreview: false},
        {id: 'project', type: 'project', title: 'تطبيق عملي'},
      ],
    },
  ],
} as unknown as CourseDetails;
const textValues = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root.findAllByType(Text).map(node => node.props.children);

describe('course details progressive disclosure', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  afterEach(() => act(() => renderer?.unmount()));

  it('uses measured lines for description overflow and keeps instructor details optional', () => {
    act(() => {
      renderer = TestRenderer.create(<CourseAbout details={details} />);
    });
    expect(textValues(renderer)).not.toContain(details.instructorBio);
    expect(textValues(renderer)).not.toContain('عن هذا الكورس');
    const measurement = renderer.root
      .findAllByType(Text)
      .find(node => node.props.onTextLayout)!;
    expect(measurement.parent!.props.importantForAccessibility).toBe(
      'no-hide-descendants',
    );
    const body = () =>
      renderer.root
        .findAllByType(Text)
        .find(
          node =>
            node.props.children === details.description &&
            !node.props.onTextLayout,
        )!;
    expect(body().props.numberOfLines).toBe(4);
    act(() =>
      measurement.props.onTextLayout({nativeEvent: {lines: Array(2).fill({})}}),
    );
    expect(textValues(renderer)).not.toContain('عرض المزيد');
    act(() =>
      measurement.props.onTextLayout({nativeEvent: {lines: Array(9).fill({})}}),
    );
    act(() =>
      renderer.root
        .findByProps({accessibilityLabel: 'عرض وصف الكورس كاملًا'})
        .props.onPress(),
    );
    expect(body().props.numberOfLines).toBeUndefined();
    expect(body().props.children).toBe(details.description);
    act(() =>
      renderer.root
        .findByProps({accessibilityLabel: 'عن المدرب'})
        .props.onPress(),
    );
    expect(textValues(renderer)).toContain(details.instructorBio);
    act(() =>
      renderer.update(
        <CourseAbout
          details={{...details, id: '8', description: 'كورس آخر'}}
        />,
      ),
    );
    expect(textValues(renderer)).not.toContain(details.instructorBio);
    expect(textValues(renderer)).not.toContain('عرض أقل');
  });

  it('keeps preview navigation and accessible locks without repeating their explanations', () => {
    const preview = jest.fn();
    act(() => {
      renderer = TestRenderer.create(
        <LockedOutline details={details} onPreviewSelect={preview} />,
      );
    });
    const module = renderer.root.findAll(
      node =>
        node.props.accessibilityRole === 'button' &&
        node.props.accessibilityState?.expanded === false &&
        node.props.onPress,
    )[0];
    act(() => module.props.onPress());
    expect(textValues(renderer)).not.toContain('يُفتح مع الكورس');
    expect(textValues(renderer)).not.toContain('مغلق');
    const locked = renderer.root.findAll(
      node =>
        node.props.accessibilityLabel?.includes('التنفيذ') &&
        node.props.onPress,
    )[0];
    expect(locked.props.accessibilityLabel).toContain('مغلق');
    expect(locked.props.disabled).toBe(true);
    const free = renderer.root.findAll(
      node =>
        node.props.accessibilityLabel?.includes('شاهد مجانًا') &&
        node.props.onPress,
    )[0];
    act(() => free.props.onPress());
    expect(preview).toHaveBeenCalledWith('reel-7');
  });
});
