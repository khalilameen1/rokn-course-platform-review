import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Spacing, useResponsiveLayout} from '../src/constants/designSystem';

let mockDimensions = {width: 360, height: 720, scale: 2, fontScale: 1};
jest.mock('react-native/Libraries/Utilities/useWindowDimensions', () => ({
  __esModule: true,
  default: () => mockDimensions,
}));

// Only the native dimensions input is substituted. The production hook and
// spacing tokens are real; this checks layout values, not native text rendering.
const readLayout = (dimensions: typeof mockDimensions) => {
  mockDimensions = Object.freeze({...dimensions});
  let result!: ReturnType<typeof useResponsiveLayout>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Probe = () => {
    result = useResponsiveLayout();
    return null;
  };
  try {
    act(() => {
      renderer = TestRenderer.create(<Probe />);
    });
    return result;
  } finally {
    if (renderer) act(() => renderer!.unmount());
  }
};

const viewports = [
  {
    name: '360dp phone',
    width: 360,
    height: 720,
    isTablet: false,
    contentWidth: 360,
    maxContentWidth: 360,
    gutter: 18,
    gridColumns: 2,
    gridGap: 12,
    gridCardWidth: 156,
    railCardWidth: 187.2,
    featuredHorizontal: false,
    largeTextImageHeight: 182.25,
  },
  {
    name: '800dp tablet',
    width: 800,
    height: 1280,
    isTablet: true,
    contentWidth: 800,
    maxContentWidth: 920,
    gutter: 28,
    gridColumns: 3,
    gridGap: 18,
    gridCardWidth: 236,
    railCardWidth: 240,
    featuredHorizontal: true,
    largeTextImageHeight: 340,
  },
  {
    name: '1280dp landscape tablet',
    width: 1280,
    height: 800,
    isTablet: true,
    contentWidth: 920,
    maxContentWidth: 920,
    gutter: 28,
    gridColumns: 3,
    gridGap: 18,
    gridCardWidth: 276,
    railCardWidth: 276,
    featuredHorizontal: true,
    largeTextImageHeight: 340,
  },
];

describe('responsive layout from native dimensions', () => {
  it('recognizes the Android 1.3 value observed on device without mutating it', () => {
    const reportedFontScale = 1.2999999523162842;
    const layout = readLayout({
      width: 360,
      height: 720,
      scale: 2,
      fontScale: reportedFontScale,
    });
    expect(layout.fontScale).toBe(1.3);
    expect(layout.largeText).toBe(true);
    expect(layout.gridColumns).toBe(1);
    expect(layout.gridCardWidth).toBe(324);
    expect(mockDimensions.fontScale).toBe(reportedFontScale);
  });

  it('keeps a genuinely lower font setting below the large-text breakpoint', () => {
    const layout = readLayout({
      width: 360,
      height: 720,
      scale: 2,
      fontScale: 1.29,
    });
    expect(layout.fontScale).toBe(1.29);
    expect(layout.largeText).toBe(false);
  });

  it.each(viewports)('preserves the current $name geometry', viewport => {
    const layout = readLayout({
      width: viewport.width,
      height: viewport.height,
      scale: 1.5,
      fontScale: 1,
    });
    expect(layout).toEqual(
      expect.objectContaining({
        width: viewport.width,
        height: viewport.height,
        isTablet: viewport.isTablet,
        isLargeTablet: false,
        contentWidth: viewport.contentWidth,
        maxContentWidth: viewport.maxContentWidth,
        gutter: viewport.gutter,
        gridColumns: viewport.gridColumns,
        gridGap: viewport.gridGap,
        largeText: false,
        featuredHorizontal: viewport.featuredHorizontal,
      }),
    );
    expect(layout.gridCardWidth).toBeCloseTo(viewport.gridCardWidth);
    expect(layout.railCardWidth).toBeCloseTo(viewport.railCardWidth);
    const availableWidth = viewport.contentWidth - viewport.gutter * 2;
    expect(
      layout.gridCardWidth * layout.gridColumns +
        layout.gridGap * (layout.gridColumns - 1),
    ).toBeCloseTo(availableWidth);
    const expectedImageWidth = viewport.featuredHorizontal
      ? (availableWidth - Spacing.xl) * 0.58
      : availableWidth;
    expect(layout.featuredImageWidth).toBeCloseTo(expectedImageWidth);
    expect(layout.featuredImageHeight).toBeCloseTo(
      Math.min(viewport.isTablet ? 340 : 196, expectedImageWidth * (9 / 16)),
    );
  });

  it.each(viewports)('stacks the hero at font scale 1.5 on $name', viewport => {
    const layout = readLayout({
      width: viewport.width,
      height: viewport.height,
      scale: 1.5,
      fontScale: 1.5,
    });
    expect(layout.largeText).toBe(true);
    expect(layout.featuredHorizontal).toBe(false);
    expect(layout.featuredImageWidth).toBe(
      viewport.contentWidth - viewport.gutter * 2,
    );
    expect(layout.featuredImageHeight).toBeCloseTo(
      viewport.largeTextImageHeight,
    );
  });

  it.each([320, 360, 390, 430])(
    'leaves room for the catalogue below the feature on a %idp phone',
    width => {
      const layout = readLayout({width, height: 720, scale: 2, fontScale: 1});
      expect(layout.featuredHorizontal).toBe(false);
      expect(layout.featuredImageHeight).toBeLessThanOrEqual(196);
      expect(layout.featuredImageHeight).toBeGreaterThanOrEqual(160);
    },
  );

  it('also normalizes floating-point noise at the horizontal-hero cutoff', () => {
    const layout = readLayout({
      width: 800,
      height: 1280,
      scale: 1.5,
      fontScale: 1.4999999523162842,
    });
    expect(layout.fontScale).toBe(1.5);
    expect(layout.featuredHorizontal).toBe(false);
    expect(layout.featuredImageWidth).toBe(744);
  });
});
