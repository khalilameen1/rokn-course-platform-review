import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {useResponsiveLayout} from '../src/constants/designSystem';

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
  },
];

describe('responsive layout from native dimensions', () => {
  it('reflows the mounted screen through rotation, split-screen and unfolding', () => {
    let layout!: ReturnType<typeof useResponsiveLayout>;
    const Probe = () => {
      layout = useResponsiveLayout();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    act(() => {
      renderer = TestRenderer.create(<Probe />);
    });
    for (const [width, height] of [
      [800, 1280],
      [1280, 800],
      [280, 800],
      [800, 360],
      [800, 1280],
    ]) {
      mockDimensions = {width, height, scale: 2, fontScale: 1.3};
      act(() => renderer.update(<Probe />));
      expect(layout.width).toBe(width);
      expect(layout.height).toBe(height);
      expect(layout.gridCardWidth).toBeGreaterThan(0);
      expect(
        layout.gridColumns * layout.gridCardWidth +
          (layout.gridColumns - 1) * layout.gridGap +
          layout.gutter * 2,
      ).toBeCloseTo(layout.contentWidth);
      expect(layout.contentWidth).toBeLessThanOrEqual(width);
      expect(layout.railCardWidth).toBeLessThanOrEqual(
        layout.contentWidth - layout.gutter * 2,
      );
    }
    act(() => renderer.unmount());
  });
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
      }),
    );
    expect(layout.gridCardWidth).toBeCloseTo(viewport.gridCardWidth);
    expect(layout.railCardWidth).toBeCloseTo(viewport.railCardWidth);
    const availableWidth = viewport.contentWidth - viewport.gutter * 2;
    expect(
      layout.gridCardWidth * layout.gridColumns +
        layout.gridGap * (layout.gridColumns - 1),
    ).toBeCloseTo(availableWidth);
    expect(layout.featuredCardWidth).toBeCloseTo(availableWidth);
    expect(layout.featuredCardMinHeight).toBeCloseTo(
      Math.min(
        viewport.isTablet ? 520 : 480,
        Math.max(408, availableWidth * 1.24),
      ),
    );
  });

  it.each(viewports)(
    'retains the single hero frame at font scale 1.5 on $name',
    viewport => {
      const layout = readLayout({
        width: viewport.width,
        height: viewport.height,
        scale: 1.5,
        fontScale: 1.5,
      });
      expect(layout.largeText).toBe(true);
      expect(layout.featuredCardWidth).toBe(
        viewport.contentWidth - viewport.gutter * 2,
      );
      expect(layout.featuredCardMinHeight).toBeCloseTo(
        Math.min(
          viewport.isTablet ? 520 : 480,
          Math.max(408, layout.featuredCardWidth * 1.24),
        ),
      );
    },
  );

  it.each([320, 360, 390, 430])(
    'uses the approved tall composition on a %idp phone',
    width => {
      const layout = readLayout({width, height: 720, scale: 2, fontScale: 1});
      expect(layout.featuredCardWidth).toBe(width - layout.gutter * 2);
      expect(layout.featuredCardMinHeight).toBeLessThanOrEqual(480);
      expect(layout.featuredCardMinHeight).toBeGreaterThanOrEqual(408);
    },
  );

  it('keeps the hero frame stable across Android font-scale noise', () => {
    const layout = readLayout({
      width: 800,
      height: 1280,
      scale: 1.5,
      fontScale: 1.4999999523162842,
    });
    expect(layout.fontScale).toBe(1.5);
    expect(layout.featuredCardWidth).toBe(744);
    expect(layout.featuredCardMinHeight).toBe(520);
  });
});
