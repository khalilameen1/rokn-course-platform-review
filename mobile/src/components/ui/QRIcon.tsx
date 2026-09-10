import React from 'react';
import Svg, {Path, Rect} from 'react-native-svg';
import {Palette} from '../../constants/designSystem';

/** Action icon only; the shareable code is rendered by QRCode. */
export const QRIcon = ({color = Palette.text}: {color?: string}) => (
  <Svg
    accessible={false}
    accessibilityElementsHidden
    importantForAccessibility="no-hide-descendants"
    width={24}
    height={24}
    viewBox="0 0 24 24"
    fill="none"
    stroke={color}
    strokeWidth={1.8}
    strokeLinecap="round"
    strokeLinejoin="round">
    <Rect x={3} y={3} width={6} height={6} rx={1} />
    <Rect x={15} y={3} width={6} height={6} rx={1} />
    <Rect x={3} y={15} width={6} height={6} rx={1} />
    <Path d="M12 3v2m0 4v3H9m-6 0h2m7 4v5m3-9h6m-6 3v3h3m3-3v6h-5" />
  </Svg>
);
