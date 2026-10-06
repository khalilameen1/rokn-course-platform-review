import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Text} from 'react-native';
import {hide} from 'expo-splash-screen';
import AppErrorBoundary from '../src/components/ui/AppErrorBoundary';

jest.mock('react-native-restart', () => ({Restart: jest.fn()}));
jest.mock('../src/services/operationalTelemetry', () => ({reportClientError: jest.fn()}));
jest.mock('react-native-safe-area-context', () => ({SafeAreaProvider: 'SafeAreaProvider', SafeAreaView: 'SafeAreaView'}));

it('reveals the committed recovery UI when a render fault interrupts startup', () => {
  const consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});
  jest.mocked(hide).mockClear();
  const Broken = (): React.ReactNode => {throw new Error('startup fixture');};
  let renderer!: TestRenderer.ReactTestRenderer;
  try {
    act(() => {renderer = TestRenderer.create(<AppErrorBoundary><Broken /></AppErrorBoundary>);});
    expect(renderer.root.findAllByType(Text).map(node => node.props.children)).toContain('حدث توقف غير متوقع');
    expect(hide).toHaveBeenCalledTimes(1);
    expect(renderer.root.findAllByProps({accessibilityRole: 'button'}).length).toBeGreaterThanOrEqual(2);
  } finally {
    if (renderer) act(() => renderer.unmount());
    consoleError.mockRestore();
  }
});
