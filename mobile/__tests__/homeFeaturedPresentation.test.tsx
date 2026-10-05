import React from 'react';
import {AccessibilityInfo, StyleSheet, Text, View} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import LinearGradient from 'react-native-linear-gradient';
import CarouselItem from '../src/components/view/CarouselItem';
import CourseCarousel from '../src/components/view/CourseCarousel';
import {CourseArtwork} from '../src/components/ui/CourseArtwork';
import {CatalogueSkeleton, SkeletonBlock} from '../src/components/ui/Skeleton';
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
    contentWidth: 390,
    railCardWidth: 202.8,
    largeText: false,
    featuredCardWidth: 354,
    featuredCardMinHeight: 438.96,
  })),
}));
jest.mock('react-native-linear-gradient', () => 'LinearGradient');
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

describe('approved artwork-overlay home featured course', () => {
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
      largeText: false,
      featuredCardWidth: 354,
      featuredCardMinHeight: 438.96,
    });
  });
  afterEach(async () => {
    await act(async () => renderer?.unmount());
  });

  it('uses compact phone type with a two-line title and no instructor or classification', async () => {
    await render();
    const title = textNodes().find(
      node => node.props.accessibilityRole === 'header',
    )!;

    expect(StyleSheet.flatten(title.props.style)).toMatchObject({
      fontFamily: Type.display.fontFamily,
      fontSize: Type.display.fontSize * 0.9,
      textAlign: 'center',
    });
    expect(title.props.numberOfLines).toBe(2);
    expect(title.props.ellipsizeMode).toBe('tail');
    expect(cleanUnicodeText(title.props.accessibilityLabel)).toBe(
      featured.title,
    );
    expect(visibleText()).not.toContain(featured.instructor);
    expect(visibleText()).not.toContain(featured.label);
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
    expect(
      StyleSheet.flatten(cta.props.style).minHeight,
    ).toBeGreaterThanOrEqual(Math.max(48, Accessibility.minTouchTarget));
    expect(visibleText()).toContain('عرض الكورس');
    expect(buttons[0].props.accessibilityHint).toBe('يفتح تفاصيل الكورس');
    expect(cleanUnicodeText(buttons[0].props.accessibilityLabel)).toContain(
      featured.title,
    );
    expect(buttons[0].props.accessibilityLabel).not.toContain('400');
    expect(visibleText().join(' ')).not.toMatch(/400|عملة|استكمل/);
    expect(visibleText()).not.toContain(featured.label);
    expect(buttons[0].props.accessibilityLabel).not.toContain(
      featured.instructor,
    );
    expect(visibleText().join(' ')).not.toMatch(
      /ضمن كورساتك|قيد التعلّم|راجع الكورس/,
    );
    expect(buttons[0].props.accessibilityLabel).not.toContain('ضمن كورساتك');
    await act(async () => buttons[0].props.onPress());
    expect(onButtonPress).toHaveBeenCalledTimes(1);
  });

  it('keeps a title-only hero without changing the upcoming course destination', async () => {
    const onButtonPress = jest.fn();
    await render(
      {
        ...featured,
        published: false,
        label: 'قريبًا',
        coinPrice: 0,
        owned: true,
      },
      onButtonPress,
    );
    expect(visibleText()).not.toContain('قريبًا');
    expect(visibleText()).not.toContain('مجاني');
    expect(visibleText()).not.toContain('ضمن كورساتك');
    const button = renderer.root.find(
      node =>
        node.props.accessibilityRole === 'button' &&
        typeof node.props.onPress === 'function',
    );
    await act(async () => button.props.onPress());
    expect(onButtonPress).toHaveBeenCalledTimes(1);
  });

  it.each([
    {label: 'مجاني', expected: 'مجاني'},
    {label: 'كورس الأسبوع', expected: 'مجاني'},
  ])(
    'keeps the hero title-only even with $expected catalogue metadata',
    async ({label, expected}) => {
      await render({...featured, label, coinPrice: 0});
      expect(visibleText()).not.toContain(expected);
      expect(visibleText()).not.toContain('كورس الأسبوع · مجاني');
      expect(visibleText()).not.toContain('مجاني');
    },
  );

  it.each([288, 324, 354, 744, 864])(
    'keeps image title and CTA in one card at %idp',
    async width => {
      mockResponsiveLayout.mockReturnValue({
        ...mockResponsiveLayout(),
        featuredCardWidth: width,
        featuredCardMinHeight: 438,
      });
      await render();
      const title = textNodes().find(
        node => node.props.accessibilityRole === 'header',
      )!;
      const artwork = renderer.root.findByType(CourseArtwork);
      const gradient = renderer.root.findByType(LinearGradient);
      const card = renderer.root.find(
        node =>
          node.props.accessibilityRole === 'button' &&
          typeof node.props.onPress === 'function',
      );
      expect(card.findByType(CourseArtwork)).toBe(artwork);
      expect(card.findByType(LinearGradient)).toBe(gradient);
      const copy = card
        .findAllByType(View)
        .find(
          node => StyleSheet.flatten(node.props.style)?.paddingTop === 142,
        )!;
      expect(copy.findAllByType(Text)).toContain(title);
      expect(
        copy
          .findAllByType(Text)
          .map(node => cleanUnicodeText(node.props.children)),
      ).toContain('عرض الكورس');
      expect(
        StyleSheet.flatten(card.props.style({pressed: false})),
      ).toMatchObject({
        width,
        minHeight: 438,
        overflow: 'hidden',
        justifyContent: 'flex-end',
      });
      expect(StyleSheet.flatten(artwork.props.style)).toMatchObject({
        position: 'absolute',
        width: '100%',
        height: '100%',
      });
      expect(gradient.props.pointerEvents).toBe('none');
      expect(gradient.props.colors[0]).toBe('rgba(7,10,16,0)');
      expect(gradient.props.colors[gradient.props.colors.length - 1]).toBe(
        Palette.canvas,
      );
      expect(StyleSheet.flatten(copy.props.style)).toMatchObject({
        alignItems: 'stretch',
        paddingTop: 142,
      });
      expect(title.props.numberOfLines).toBe(2);
    },
  );

  it('allows large Arabic text to grow the card rather than collide with the action', async () => {
    mockResponsiveLayout.mockReturnValue({
      ...mockResponsiveLayout(),
      largeText: true,
    });
    await render();
    const title = textNodes().find(
      node => node.props.accessibilityRole === 'header',
    )!;
    expect(title.props.numberOfLines).toBe(4);
    const gradient = renderer.root.findByType(LinearGradient);
    expect(gradient.props.colors).toEqual([
      'rgba(7,10,16,0.65)',
      'rgba(7,10,16,0.82)',
      'rgba(7,10,16,0.92)',
      Palette.canvas,
    ]);
    const card = renderer.root.find(
      node =>
        node.props.accessibilityRole === 'button' &&
        typeof node.props.onPress === 'function',
    );
    const copy = card
      .findAllByType(View)
      .find(node => StyleSheet.flatten(node.props.style)?.paddingTop === 142)!;
    const copyStyle = StyleSheet.flatten(copy.props.style);
    expect(copyStyle.position).not.toBe('absolute');
    expect(copyStyle.height).toBeUndefined();
    expect(
      StyleSheet.flatten(card.props.style({pressed: false})).height,
    ).toBeUndefined();
  });

  it('renders only the first editorial course and opens its existing details destination', async () => {
    const onButtonPress = jest.fn();
    await act(async () => {
      renderer = TestRenderer.create(
        <CourseCarousel
          data={[featured, {...featured, id: 'second', title: 'كورس آخر'}]}
          onButtonPress={onButtonPress}
        />,
      );
    });
    expect(renderer.root.findAllByType(CourseArtwork)).toHaveLength(1);
    expect(visibleText()).not.toContain('كورس آخر');
    const card = renderer.root.find(
      node =>
        node.props.accessibilityRole === 'button' &&
        typeof node.props.onPress === 'function',
    );
    await act(async () => card.props.onPress());
    expect(onButtonPress).toHaveBeenCalledTimes(1);
    expect(onButtonPress).toHaveBeenCalledWith(featured);
  });

  it('uses the same hero frame while loading without detached classification or author placeholders', async () => {
    const reduceMotion = jest
      .spyOn(AccessibilityInfo, 'isReduceMotionEnabled')
      .mockResolvedValue(true);
    try {
      await act(async () => {
        renderer = TestRenderer.create(<CatalogueSkeleton />);
      });
      const blocks = renderer.root.findAllByType(SkeletonBlock);
      expect(blocks[0].props).toMatchObject({width: 354, height: 438.96});
      expect(blocks).toHaveLength(15);
      const root = renderer.root
        .findByType(CatalogueSkeleton)
        .findByProps({accessibilityRole: 'progressbar'});
      expect(StyleSheet.flatten(root.props.style)).toMatchObject({
        maxWidth: 390,
        alignSelf: 'center',
      });
    } finally {
      reduceMotion.mockRestore();
    }
  });
});
