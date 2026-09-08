import React from 'react';
import Svg, {Path, Rect} from 'react-native-svg';
import {Palette} from '../../constants/designSystem';

export const CopyIcon = ({
  copied = false,
  color = Palette.textMuted,
}: {copied?: boolean; color?: string}) => (
  <Svg
    accessible={false}
    accessibilityElementsHidden
    importantForAccessibility="no-hide-descendants"
    width={19}
    height={19}
    viewBox="0 0 24 24"
    fill="none"
    stroke={color}
    strokeWidth={1.8}
    strokeLinecap="round"
    strokeLinejoin="round">
    {copied ? (
      <Path d="m5 12 4 4L19 6" />
    ) : (
      <>
        <Rect x={8} y={8} width={12} height={13} rx={2} />
        <Path d="M16 5V4a1 1 0 0 0-1-1H5a2 2 0 0 0-2 2v10a1 1 0 0 0 1 1h1" />
      </>
    )}
  </Svg>
);
