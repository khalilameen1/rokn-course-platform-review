import React, {useMemo} from 'react';
import {StyleProp, ViewStyle} from 'react-native';
import Svg, {Path, Rect} from 'react-native-svg';
import {toQR} from 'toqr';

export default function QRCode({
  value,
  size = 156,
  color = '#07101D',
  backgroundColor = '#FFFFFF',
  accessibilityLabel = 'رمز QR للتحقق من الشهادة',
  style,
}: {
  value: string;
  size?: number;
  color?: string;
  backgroundColor?: string;
  accessibilityLabel?: string;
  style?: StyleProp<ViewStyle>;
}) {
  const matrix = useMemo(() => toQR(value, 2), [value]);
  const dimension = Math.round(Math.sqrt(matrix.length));
  const quietZone = 4;
  const viewBoxSize = dimension + quietZone * 2;
  const path = useMemo(() => {
    let result = '';
    for (let index = 0; index < matrix.length; index += 1) {
      if (!matrix[index]) continue;
      const x = (index % dimension) + quietZone;
      const y = Math.floor(index / dimension) + quietZone;
      result += `M${x} ${y}h1v1h-1z`;
    }
    return result;
  }, [dimension, matrix]);

  return (
    <Svg
      accessibilityLabel={accessibilityLabel}
      accessibilityRole="image"
      height={size}
      style={style}
      viewBox={`0 0 ${viewBoxSize} ${viewBoxSize}`}
      width={size}>
      <Rect width={viewBoxSize} height={viewBoxSize} fill={backgroundColor} />
      <Path d={path} fill={color} />
    </Svg>
  );
}
