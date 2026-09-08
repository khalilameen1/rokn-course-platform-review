import Clipboard from '@react-native-clipboard/clipboard';
import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {AccessibilityInfo, Alert, Text} from 'react-native';
import {CopyButton} from '../src/components/ui/CopyButton';

jest.mock('@react-native-clipboard/clipboard', () => ({setString: jest.fn()}));

describe('shared icon copy feedback', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const button = () =>
    renderer.root.findAll(
      node =>
        node.props.accessibilityLabel === 'نسخ النص' &&
        typeof node.props.onPress === 'function',
    )[0];
  const mount = (value = '  Code 001\n') => {
    act(() => {
      renderer = TestRenderer.create(
        <CopyButton value={value} accessibilityLabel="نسخ النص" />,
      );
    });
  };
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers();
    jest.mocked(Clipboard.setString).mockReset();
    jest
      .spyOn(AccessibilityInfo, 'announceForAccessibility')
      .mockImplementation(() => {});
    jest.spyOn(Alert, 'alert').mockImplementation(() => {});
  });
  afterEach(() => {
    act(() => renderer?.unmount());
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('copies exact text and restarts the temporary success interval after a second tap', () => {
    mount();
    act(() => button().props.onPress());
    expect(Clipboard.setString).toHaveBeenLastCalledWith('  Code 001\n');
    expect(button().props.accessibilityLabel).toBe('نسخ النص');
    expect(button().props.accessibilityValue).toEqual({text: 'تم النسخ'});
    expect(renderer.root.findAllByType(Text)).toHaveLength(0);
    act(() => jest.advanceTimersByTime(1500));
    act(() => button().props.onPress());
    act(() => jest.advanceTimersByTime(500));
    expect(button().props.accessibilityValue).toEqual({text: 'تم النسخ'});
    act(() => jest.advanceTimersByTime(1500));
    expect(button().props.accessibilityValue).toEqual({text: ''});
    expect(AccessibilityInfo.announceForAccessibility).toHaveBeenCalledTimes(2);
  });

  it('clears success and its timer on value change and unmount', () => {
    mount('first');
    act(() => button().props.onPress());
    expect(jest.getTimerCount()).toBe(1);
    act(() =>
      renderer.update(
        <CopyButton value="second" accessibilityLabel="نسخ النص" />,
      ),
    );
    expect(button().props.accessibilityValue).toEqual({text: ''});
    expect(jest.getTimerCount()).toBe(0);
    act(() => button().props.onPress());
    expect(Clipboard.setString).toHaveBeenLastCalledWith('second');
    act(() => renderer.unmount());
    expect(jest.getTimerCount()).toBe(0);
  });

  it('clears a previous success if clipboard writing subsequently fails', () => {
    mount();
    act(() => button().props.onPress());
    jest.mocked(Clipboard.setString).mockImplementationOnce(() => {
      throw new Error('unavailable');
    });
    act(() => button().props.onPress());
    expect(button().props.accessibilityValue).toEqual({text: ''});
    expect(jest.getTimerCount()).toBe(0);
    expect(Alert.alert).toHaveBeenCalledWith('تعذّر النسخ', 'حاول مرة أخرى');
    expect(AccessibilityInfo.announceForAccessibility).toHaveBeenCalledTimes(1);
  });

  it('does not copy or announce an empty value', () => {
    mount(' \n');
    expect(button().props.disabled).toBe(true);
    act(() => button().props.onPress());
    expect(Clipboard.setString).not.toHaveBeenCalled();
    expect(AccessibilityInfo.announceForAccessibility).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
    expect(jest.getTimerCount()).toBe(0);
  });
});
