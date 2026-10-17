import React from 'react';
import Svg, {Path} from 'react-native-svg';
import {Palette} from '../../constants/designSystem';

/** The save glyph shared by the player rail and saved collections. */
export const BookmarkIcon = ({
  filled = false,
  size = 28,
  color = '#fff',
}: {
  filled?: boolean;
  size?: number;
  color?: string;
}) => (
  <Svg width={size} height={size} viewBox="0 0 28 28">
    <Path
      d="M7 5.2c0-1 .8-1.8 1.8-1.8h10.4c1 0 1.8.8 1.8 1.8v19.4l-7-4.3-7 4.3V5.2Z"
      fill={filled ? Palette.primary : 'rgba(0,0,0,0)'}
      stroke={color}
      strokeWidth={1.8}
      strokeLinejoin="round"
    />
  </Svg>
);
