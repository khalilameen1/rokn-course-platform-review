import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Image, StyleSheet, View} from 'react-native';
import type {ImageStyle} from 'react-native';
import {PortfolioProjectGrid} from '../src/screens/Profile/gallery/PortfolioProjectGrid';
import type {Project} from '../src/screens/Profile/gallery/portfolioModel';
import {galleryStyles} from '../src/screens/Profile/gallery/galleryStyles';
import {styles as myCornerStyles} from '../src/screens/myCorner/styles';

const projects: Project[] = [1, 2, 3].map(id => ({
  id: String(id),
  title: `مشروع ${id}`,
  summary: 'وصف المشروع',
  cover: 1,
  skills: [],
  source: 'remote',
  media: [],
  shareReady: false,
}));

describe('portfolio grid allocated width', () => {
  it.each([
    {name: 'portfolio detail', style: galleryStyles.detailCover, ratio: 1.5},
    {
      name: 'primary course',
      style: myCornerStyles.primaryCourseCover,
      ratio: 2.5,
    },
    {
      name: 'large-text course',
      style: myCornerStyles.largeTextCourseCover,
      ratio: 2.6,
    },
  ])('clears bundled fallback height for $name', ({style, ratio}) => {
    const effectiveStyle = StyleSheet.flatten<ImageStyle>([
      {width: 959, height: 535},
      style,
    ]);
    expect(effectiveStyle.width).toBe('100%');
    expect(effectiveStyle.height).toBeUndefined();
    expect(effectiveStyle.aspectRatio).toBe(ratio);
  });

  it.each([
    {width: 248, fontScale: 1, gap: 12, columns: 1},
    {width: 375, fontScale: 1, gap: 12, columns: 1},
    {width: 680, fontScale: 1, gap: 18, columns: 2},
    {width: 1064, fontScale: 1, gap: 18, columns: 3},
    {width: 680, fontScale: 1.5, gap: 18, columns: 1},
    {width: 1064, fontScale: 1.5, gap: 18, columns: 2},
  ])(
    'fits $columns columns into $width dp at font scale $fontScale',
    config => {
      let renderer!: TestRenderer.ReactTestRenderer;
      const onOpen = jest.fn();
      const element = (
        <PortfolioProjectGrid
          fontScale={config.fontScale}
          gap={config.gap}
          projects={projects}
          onOpen={onOpen}
          onCoverError={jest.fn()}
          onCoverLoad={jest.fn()}
        />
      );
      try {
        act(() => {
          renderer = TestRenderer.create(element);
        });
        const cards = () =>
          renderer.root.findAll(
            node =>
              node.props.accessibilityRole === 'button' &&
              typeof node.props.style === 'function',
          );
        const cardStyle = () =>
          StyleSheet.flatten(cards()[0].props.style({pressed: false}));
        const grid = renderer.root
          .findAllByType(View)
          .find(node => node.props.onLayout)!;
        expect(cardStyle().width).toBe('100%');
        expect(StyleSheet.flatten(grid.props.style).width).toBe('100%');
        act(() => {
          grid.props.onLayout({nativeEvent: {layout: {width: config.width}}});
        });
        const width = cardStyle().width as number;
        expect(width).toBeGreaterThan(0);
        expect(width).toBeLessThanOrEqual(config.width);
        expect(
          width * config.columns + config.gap * (config.columns - 1),
        ).toBeCloseTo(config.width);
        expect(cardStyle().maxWidth).toBe('100%');
        expect(cardStyle().overflow).not.toBe('hidden');
        // RN prepends bundled-image dimensions before the supplied style.
        // Explicitly clear intrinsic height; otherwise Yoga derives an oversized
        // cover width from that height even when its card width is correct.
        const coverStyle = renderer.root.findAllByType(Image)[0].props.style;
        const effectiveCoverStyle = StyleSheet.flatten([
          {width: 320, height: 480},
          coverStyle,
        ]);
        expect(effectiveCoverStyle.width).toBe('100%');
        expect(effectiveCoverStyle.height).toBeUndefined();
        expect(effectiveCoverStyle.aspectRatio).toBe(1.4);
        act(() => cards()[0].props.onPress());
        expect(onOpen).toHaveBeenCalledWith(projects[0]);

        // A tablet can become a narrow split window without retaining stale
        // window-derived card widths or dropping any projects.
        act(() => {
          grid.props.onLayout({nativeEvent: {layout: {width: 240}}});
        });
        expect(cardStyle().width).toBe(240);
        expect(cards()).toHaveLength(projects.length);
      } finally {
        act(() => renderer?.unmount());
      }
    },
  );
});
