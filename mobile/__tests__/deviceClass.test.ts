import {deviceClassForScreen} from '../src/constants/deviceClass';

describe('device class', () => {
  it('uses the shortest logical side so rotation does not change the device', () => {
    expect(deviceClassForScreen(390, 844)).toBe('phone');
    expect(deviceClassForScreen(844, 390)).toBe('phone');
    expect(deviceClassForScreen(800, 1280)).toBe('tablet');
    expect(deviceClassForScreen(1280, 800)).toBe('tablet');
  });

  it('keeps the native iPad signal authoritative', () => {
    expect(deviceClassForScreen(568, 744, true)).toBe('tablet');
  });
});
