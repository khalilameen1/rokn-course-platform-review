import {Dimensions, Platform} from 'react-native';

export type RoknDeviceClass = 'phone' | 'tablet';

export const deviceClassForScreen = (
  width: number,
  height: number,
  nativeTablet = false,
): RoknDeviceClass =>
  nativeTablet || Math.min(Number(width) || 0, Number(height) || 0) >= 600
    ? 'tablet'
    : 'phone';

export const currentDeviceClass = (): RoknDeviceClass => {
  const screen = Dimensions?.get?.('screen') || {width: 0, height: 0};
  return deviceClassForScreen(
    screen.width,
    screen.height,
    Platform.OS === 'ios' && Platform.isPad,
  );
};
