import {useWindowDimensions} from 'react-native';
import {Colors, Fonts, PixelPerfect} from './styleConstants';
import {BrandColors} from './brandTokens';

export const Palette = {
  ...BrandColors,
  action: BrandColors.primary,
  actionPressed: BrandColors.primaryPressed,
} as const;

export const Spacing = {
  xxs: PixelPerfect(4),
  xs: PixelPerfect(8),
  sm: PixelPerfect(12),
  md: PixelPerfect(16),
  lg: PixelPerfect(20),
  xl: PixelPerfect(24),
  xxl: PixelPerfect(32),
  section: PixelPerfect(40),
} as const;

export const Radius = {
  sm: PixelPerfect(10),
  md: PixelPerfect(14),
  lg: PixelPerfect(18),
  xl: PixelPerfect(24),
  pill: 999,
} as const;

export const Type = {
  display: {fontFamily: Fonts.bold, fontSize: PixelPerfect(30), lineHeight: PixelPerfect(42)},
  title: {fontFamily: Fonts.bold, fontSize: PixelPerfect(22), lineHeight: PixelPerfect(32)},
  section: {fontFamily: Fonts.bold, fontSize: PixelPerfect(18), lineHeight: PixelPerfect(28)},
  body: {fontFamily: Fonts.regular, fontSize: PixelPerfect(15), lineHeight: PixelPerfect(25)},
  bodyStrong: {fontFamily: Fonts.semiBold, fontSize: PixelPerfect(15), lineHeight: PixelPerfect(25)},
  caption: {fontFamily: Fonts.regular, fontSize: PixelPerfect(12), lineHeight: PixelPerfect(20)},
  button: {fontFamily: Fonts.bold, fontSize: PixelPerfect(16), lineHeight: PixelPerfect(24)},
} as const;

export const Accessibility = {
  // Interactive targets retain Android's 48dp accessibility baseline.
  // Visual icons can stay smaller inside the target.
  minTouchTarget: Math.max(48, PixelPerfect(48)),
} as const;

// Let the native renderer align to the explicit RTL paragraph. A literal
// "right" is mirrored again by React Native when the layout is already RTL.
export const rtlTextAlign = 'auto' as const;

export const textDirection = {
  direction: 'rtl' as const,
  textAlign: rtlTextAlign,
  writingDirection: 'rtl' as const,
};

/**
 * Native RTL is forced before React mounts, so Yoga already places the first
 * child on the physical right. row-reverse here reverses Arabic a second time.
 */
export const rtlRowStyle = {
  direction: 'rtl' as const,
  flexDirection: 'row' as const,
};

/** A fixed row-icon slot that prevents overlap from large text. */
export const fixedIconSlot = {
  width: Accessibility.minTouchTarget,
  minWidth: Accessibility.minTouchTarget,
  height: Accessibility.minTouchTarget,
  flexShrink: 0 as const,
  alignItems: 'center' as const,
  justifyContent: 'center' as const,
};

/** A text column that is allowed to wrap instead of pushing into row actions. */
export const flexibleTextColumn = {
  flexGrow: 1 as const,
  flexShrink: 1 as const,
  minWidth: 0,
};

export const rowDirection = 'row' as const;

/** Logical start is the physical right edge because native RTL is enabled. */
export const rtlStartAlignment = 'flex-start' as const;

/** Responsive values for rails, grids and readable tablet layouts. */
export const useResponsiveLayout = () => {
  const {width, height, fontScale} = useWindowDimensions();
  const shortestSide = Math.min(width, height);
  const isTablet = shortestSide >= 600;
  const isLargeTablet = shortestSide >= 820;
  const largeText = fontScale >= 1.3;
  const gutter = Math.min(isTablet ? 28 : 18, Math.max(12, width * 0.05));
  const maxContentWidth = isLargeTablet ? 1120 : isTablet ? 920 : width;
  const contentWidth = Math.min(width, maxContentWidth);
  const preferredGridColumns = isLargeTablet ? 4 : isTablet ? 3 : 2;
  const gridGap = isTablet ? 18 : 12;
  const minimumReadableCardWidth = 148 * Math.min(1.42, Math.max(1, fontScale));
  const availableGridWidth = Math.max(0, contentWidth - gutter * 2);
  const gridColumns = Math.max(
    1,
    Math.min(
      preferredGridColumns,
      Math.floor(
        (availableGridWidth + gridGap) /
          (minimumReadableCardWidth + gridGap),
      ),
    ),
  );
  const gridCardWidth =
    (contentWidth - gutter * 2 - gridGap * (gridColumns - 1)) / gridColumns;
  const minimumRailCardWidth = 156 * Math.min(1.35, Math.max(1, fontScale));
  const railCardWidth = Math.min(
    isTablet ? 290 : 220,
    Math.max(1, availableGridWidth),
    Math.max(
      minimumRailCardWidth,
      contentWidth * (isTablet ? 0.3 : 0.52),
    ),
  );

  return {
    width,
    height,
    fontScale,
    largeText,
    isTablet,
    isLargeTablet,
    gutter,
    contentWidth,
    maxContentWidth,
    gridColumns,
    gridGap,
    gridCardWidth,
    railCardWidth,
  };
};

export const surfaceShadow = {
  shadowColor: Colors.black,
  shadowOffset: {width: 0, height: 10},
  shadowOpacity: 0.2,
  shadowRadius: 24,
  elevation: 5,
};
