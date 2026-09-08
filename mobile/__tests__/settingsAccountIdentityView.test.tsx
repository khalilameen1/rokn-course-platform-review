import Clipboard from '@react-native-clipboard/clipboard';
import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {AccessibilityInfo, Alert, StyleSheet, Text} from 'react-native';
import {SettingsAccountIdentity} from '../src/components/settings/SettingsAccountIdentity';
import {cleanUnicodeText} from '../src/utils/unicodeText';

jest.mock('@react-native-clipboard/clipboard', () => ({setString: jest.fn()}));

describe('settings account identity view', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const copyButton = () =>
    renderer.root.findAll(
      node =>
        node.props.accessibilityLabel === 'نسخ UID' &&
        typeof node.props.onPress === 'function',
    )[0];
  const visibleText = () =>
    renderer.root
      .findAllByType(Text)
      .map(node => cleanUnicodeText(node.props.children));

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

  it('preserves the authored RTL name and renders the exact UID in LTR', () => {
    const name = 'مريم Grease Pencil 2026';
    const id = '9007199254740993';
    act(() => {
      renderer = TestRenderer.create(
        <SettingsAccountIdentity id={id} name={name} />,
      );
    });
    const texts = renderer.root.findAllByType(Text);
    expect(visibleText()).toEqual(expect.arrayContaining([name, `UID: ${id}`]));
    expect(StyleSheet.flatten(texts[0].props.style).writingDirection).toBe(
      'rtl',
    );
    const identity = texts.find(node => node.props.children === `UID: ${id}`)!;
    expect(StyleSheet.flatten(identity.props.style)).toMatchObject({
      direction: 'ltr',
      writingDirection: 'ltr',
      textAlign: 'left',
    });
    expect(identity.props.selectable).toBe(true);
  });

  it('copies only the raw id and confirms within the icon without adding visible text', () => {
    act(() => {
      renderer = TestRenderer.create(
        <SettingsAccountIdentity id="000123" name="محمد" />,
      );
    });
    const target = copyButton();
    const style = StyleSheet.flatten(target.props.style({pressed: false}));
    expect(style.minWidth).toBeGreaterThanOrEqual(48);
    expect(style.minHeight).toBeGreaterThanOrEqual(48);
    const beforeText = visibleText();
    expect(beforeText).not.toContain('نسخ');
    act(() => target.props.onPress());
    expect(Clipboard.setString).toHaveBeenCalledWith('000123');
    expect(Clipboard.setString).toHaveBeenCalledTimes(1);
    expect(copyButton().props.accessibilityValue).toEqual({text: 'تم النسخ'});
    expect(AccessibilityInfo.announceForAccessibility).toHaveBeenCalledWith(
      'تم النسخ',
    );
    expect(visibleText()).toEqual(beforeText);
    expect(
      StyleSheet.flatten(copyButton().props.style({pressed: false})),
    ).toEqual(style);
    act(() => jest.advanceTimersByTime(2000));
    expect(copyButton().props.accessibilityValue).toEqual({text: ''});
    expect(visibleText()).toEqual(beforeText);
  });

  it('reports a clipboard failure without false success and permits recovery', () => {
    jest.mocked(Clipboard.setString).mockImplementationOnce(() => {
      throw new Error('clipboard unavailable');
    });
    act(() => {
      renderer = TestRenderer.create(
        <SettingsAccountIdentity id="123" name="محمد" />,
      );
    });
    expect(() => act(() => copyButton().props.onPress())).not.toThrow();
    expect(Alert.alert).toHaveBeenCalledWith('تعذّر النسخ', 'حاول مرة أخرى');
    expect(copyButton().props.accessibilityValue).toEqual({text: ''});
    expect(AccessibilityInfo.announceForAccessibility).not.toHaveBeenCalled();
    expect(visibleText()).toEqual(['محمد', 'UID: 123']);
    act(() => copyButton().props.onPress());
    expect(copyButton().props.accessibilityValue).toEqual({text: 'تم النسخ'});
    expect(AccessibilityInfo.announceForAccessibility).toHaveBeenCalledTimes(1);
    expect(visibleText()).toEqual(['محمد', 'UID: 123']);
  });

  it('resets copy feedback when the parent keys a different account', () => {
    act(() => {
      renderer = TestRenderer.create(
        <SettingsAccountIdentity key="123" id="123" name="محمد" />,
      );
    });
    act(() => copyButton().props.onPress());
    expect(copyButton().props.accessibilityValue).toEqual({text: 'تم النسخ'});
    act(() =>
      renderer.update(
        <SettingsAccountIdentity key="456" id="456" name="مريم" />,
      ),
    );
    expect(copyButton().props.accessibilityValue).toEqual({text: ''});
    expect(visibleText()).toContain('UID: 456');
    act(() => copyButton().props.onPress());
    expect(Clipboard.setString).toHaveBeenLastCalledWith('456');
  });
});
