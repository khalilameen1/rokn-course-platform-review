import React from 'react';
import {StyleSheet, Text, View} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import CarouselItem from '../src/components/view/CarouselItem';
import {CourseArtwork} from '../src/components/ui/CourseArtwork';
import {
  Accessibility,
  Palette,
  Type,
  useResponsiveLayout,
} from '../src/constants/designSystem';
import type {Course} from '../src/types/Course';
import {cleanUnicodeText} from '../src/utils/unicodeText';

jest.mock('../src/constants/designSystem', () => ({
  ...jest.requireActual('../src/constants/designSystem'),
  useResponsiveLayout: jest.fn(() => ({
    gutter: 18,
    featuredHorizontal: false,
    featuredImageWidth: 324,
    featuredImageHeight: 190,
  })),
}));
jest.mock('../src/assets/SVG', () => ({ArrowRight: () => null}));
jest.mock('../src/components/ui/CourseArtwork', () => ({
  CourseArtwork: () => null,
}));

const featured: Course = {
  id: 'featured-blender',
  title: 'تعلم بلندر من البداية وصمم أول مشروع ثلاثي الأبعاد بنفسك',
  instructor: 'مدرب ركن لتعليم التصميم ثلاثي الأبعاد',
  description: '',
  image: {uri: 'https://example.com/blender-cover.png'},
  category: 'skills',
  label: 'كورس الأسبوع',
  published: true,
  coinPrice: 400,
};

describe('compact home featured course', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const mockResponsiveLayout = jest.mocked(useResponsiveLayout);
  const render = async (course = featured, onButtonPress = jest.fn()) => {
    await act(async () => {
      renderer = TestRenderer.create(
        <CarouselItem course={course} onButtonPress={onButtonPress} />,
      );
    });
  };
  const textNodes = () => renderer.root.findAllByType(Text);
  const visibleText = () =>
    textNodes().map(node => cleanUnicodeText(node.props.children));

  beforeEach(() => {
    mockResponsiveLayout.mockReturnValue({
      ...mockResponsiveLayout(),
      featuredHorizontal: false,
    });
  });
  afterEach(async () => {
    await act(async () => renderer?.unmount());
  });

  it('uses compact phone type with a two-line title and one-line instructor', async () => {
    await render();
    const title = textNodes().find(
      node => node.props.accessibilityRole === 'header',
    )!;
    const instructor = textNodes().find(
      node => cleanUnicodeText(node.props.children) === featured.instructor,
    )!;

    expect(StyleSheet.flatten(title.props.style)).toMatchObject(Type.section);
    expect(title.props.numberOfLines).toBe(2);
    expect(title.props.ellipsizeMode).toBe('tail');
    expect(cleanUnicodeText(title.props.accessibilityLabel)).toBe(featured.title);
    expect(instructor.props.numberOfLines).toBe(1);
    expect(instructor.props.ellipsizeMode).toBe('tail');
    expect(renderer.root.findByType(CourseArtwork).props.source).toBe(
      featured.image,
    );
  });

  it('restores a filled 48 dp CTA inside exactly one details action', async () => {
    const onButtonPress = jest.fn();
    await render({...featured, owned: true, started: true}, onButtonPress);
    const buttons = renderer.root.findAll(
      node =>
        node.props.accessibilityRole === 'button' &&
        typeof node.props.onPress === 'function',
    );
    const cta = renderer.root.findAllByType(View).find(node => {
      const style = StyleSheet.flatten(node.props.style);
      return style?.backgroundColor === Palette.action;
    })!;

    expect(buttons).toHaveLength(1);
    expect(StyleSheet.flatten(cta.props.style).minHeight).toBeGreaterThanOrEqual(
      Math.max(48, Accessibility.minTouchTarget),
    );
    expect(visibleText()).toContain('عرض الكورس');
    expect(buttons[0].props.accessibilityHint).toBe('يفتح تفاصيل الكورس');
    expect(cleanUnicodeText(buttons[0].props.accessibilityLabel)).toContain(
      featured.title,
    );
    expect(buttons[0].props.accessibilityLabel).not.toContain('400');
    expect(visibleText().join(' ')).not.toMatch(/400|عملة|استكمل/);
    await act(async () => buttons[0].props.onPress());
    expect(onButtonPress).toHaveBeenCalledTimes(1);
  });

  it('shows upcoming availability only once and suppresses other access states', async () => {
    await render({
      ...featured,
      published: false,
      label: 'قريبًا',
      coinPrice: 0,
      owned: true,
    });
    expect(visibleText().filter(value => value === 'قريبًا')).toHaveLength(1);
    expect(visibleText()).not.toContain('مجاني');
    expect(visibleText()).not.toContain('ضمن كورساتك');
  });

  it.each([
    {label: 'مجاني', expected: 'مجاني'},
    {label: 'كورس الأسبوع', expected: 'كورس الأسبوع · مجاني'},
  ])(
    'keeps $expected in a single compact metadata line',
    async ({label, expected}) => {
      await render({...featured, label, coinPrice: 0});
      const metadata = textNodes().find(
        node => cleanUnicodeText(node.props.children) === expected,
      )!;
      expect(metadata).toBeDefined();
      expect(metadata.props.numberOfLines).toBe(1);
    },
  );

  it('retains larger type for the existing horizontal tablet layout', async () => {
    mockResponsiveLayout.mockReturnValue({
      ...mockResponsiveLayout(),
      featuredHorizontal: true,
    });
    await render();
    const title = textNodes().find(
      node => node.props.accessibilityRole === 'header',
    )!;
    expect(StyleSheet.flatten(title.props.style)).toMatchObject(Type.title);
    expect(title.props.numberOfLines).toBe(2);
  });
});
