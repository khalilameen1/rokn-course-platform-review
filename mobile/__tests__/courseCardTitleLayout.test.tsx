import React from 'react';
import {StyleSheet, Text, View} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import CourseCard from '../src/components/view/CourseCard';
import type {Course} from '../src/types/Course';
import {cleanUnicodeText} from '../src/utils/unicodeText';

let mockFontScale = 1;

jest.mock('../src/constants/designSystem', () => ({
  Palette: {},
  Radius: {},
  Spacing: {xs: 8},
  Type: {
    bodyStrong: {fontSize: 15, lineHeight: 25},
    caption: {fontSize: 12, lineHeight: 20},
  },
  textDirection: {direction: 'rtl', writingDirection: 'rtl'},
  useResponsiveLayout: () => ({
    fontScale: mockFontScale,
    largeText: mockFontScale >= 1.3,
    railCardWidth: 180,
  }),
}));
jest.mock('../src/components/ui/PremiumUI', () => ({MetaPill: () => null}));
jest.mock('../src/components/ui/CourseArtwork', () => ({
  CourseArtwork: () => null,
}));

const shortCourse: Course = {
  id: 'short',
  title: 'مونتاج الريلز',
  description: '',
  instructor: 'مدرب ركن',
  image: {uri: 'https://example.com/course.webp'},
  category: 'skills',
  published: true,
};
const longCourse: Course = {
  ...shortCourse,
  id: 'long',
  title: 'تصميم إعلانات السوشيال ميديا وإعداد ملفاتها للنشر',
  instructor: '',
};

describe('home course card text slots', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const onPress = jest.fn();
  const renderPair = async () => {
    await act(async () => {
      renderer = TestRenderer.create(
        <View>
          <CourseCard item={shortCourse} onPress={onPress} />
          <CourseCard item={longCourse} onPress={onPress} />
        </View>,
      );
    });
  };
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    mockFontScale = 1;
    onPress.mockClear();
  });

  it.each([
    {scale: 0.85, lines: 2, titleHeight: 44},
    {scale: 1, lines: 2, titleHeight: 50},
    {scale: 1.3, lines: 4, titleHeight: 132},
    {scale: 2, lines: 4, titleHeight: 200},
  ])(
    'reserves equal slots for short and long names at font scale $scale',
    async ({scale, lines, titleHeight}) => {
      mockFontScale = scale;
      await renderPair();
      const titles = renderer.root
        .findAllByType(Text)
        .filter(node =>
          [shortCourse.title, longCourse.title].includes(
            cleanUnicodeText(node.props.children),
          ),
        );
      expect(titles).toHaveLength(2);
      for (const title of titles) {
        const style = StyleSheet.flatten(title.props.style);
        expect(title.props.numberOfLines).toBe(lines);
        expect(title.props.ellipsizeMode).toBe('tail');
        expect(title.props.allowFontScaling).not.toBe(false);
        expect(title.props.adjustsFontSizeToFit).not.toBe(true);
        expect(title.props.maxFontSizeMultiplier).toBeUndefined();
        expect(style.minHeight).toBe(titleHeight);
        expect(style.height).toBeUndefined();
        expect(style.fontSize).toBe(15);
        expect(style.includeFontPadding).toBe(false);
        expect(style.textAlignVertical).toBe('top');
        expect(style.writingDirection).toBe('rtl');
      }
      // Home omits author copy and its spacer; equal scaled title slots remain.
      expect(renderer.root.findAllByType(Text)).toHaveLength(2);
      expect(
        titles.map(node => cleanUnicodeText(node.props.children)),
      ).not.toContain(shortCourse.instructor);
    },
  );

  it('keeps the full title in accessibility and sends the unchanged course to details', async () => {
    await renderPair();
    const buttons = renderer.root.findAll(
      node =>
        node.props.accessibilityRole === 'button' &&
        typeof node.props.onPress === 'function',
    );
    expect(buttons).toHaveLength(2);
    expect(cleanUnicodeText(buttons[1].props.accessibilityLabel)).toContain(
      longCourse.title,
    );
    await act(async () => buttons[1].props.onPress());
    expect(onPress).toHaveBeenCalledWith(longCourse);
    expect(onPress).toHaveBeenCalledTimes(1);
  });
});
