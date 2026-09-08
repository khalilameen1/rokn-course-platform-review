import Clipboard from '@react-native-clipboard/clipboard';
import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {StyleSheet, Text} from 'react-native';
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

  beforeEach(() => jest.mocked(Clipboard.setString).mockReset());
  afterEach(() => act(() => renderer?.unmount()));

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

  it('copies only the raw id and confirms inline with an accessible touch target', () => {
    act(() => {
      renderer = TestRenderer.create(
        <SettingsAccountIdentity id="000123" name="محمد" />,
      );
    });
    const target = copyButton();
    const style = StyleSheet.flatten(target.props.style({pressed: false}));
    expect(style.minWidth).toBeGreaterThanOrEqual(44);
    expect(style.minHeight).toBeGreaterThanOrEqual(44);
    act(() => target.props.onPress());
    expect(Clipboard.setString).toHaveBeenCalledWith('000123');
    expect(Clipboard.setString).toHaveBeenCalledTimes(1);
    expect(visibleText()).toContain('تم النسخ');
    const feedback = renderer.root
      .findAllByType(Text)
      .find(node => node.props.children === 'تم النسخ')!;
    expect(feedback.props.accessibilityLiveRegion).toBe('polite');
  });

  it('shows a useful two-line failure and allows another copy attempt', () => {
    jest.mocked(Clipboard.setString).mockImplementationOnce(() => {
      throw new Error('clipboard unavailable');
    });
    act(() => {
      renderer = TestRenderer.create(
        <SettingsAccountIdentity id="123" name="محمد" />,
      );
    });
    expect(() => act(() => copyButton().props.onPress())).not.toThrow();
    expect(visibleText()).toContain('تعذّر النسخ\nحاول مرة أخرى');
    expect(visibleText()).not.toContain('تم النسخ');
    act(() => copyButton().props.onPress());
    expect(visibleText()).toContain('تم النسخ');
    expect(visibleText()).not.toContain('تعذّر النسخ\nحاول مرة أخرى');
  });

  it('resets copy feedback when the parent keys a different account', () => {
    act(() => {
      renderer = TestRenderer.create(
        <SettingsAccountIdentity key="123" id="123" name="محمد" />,
      );
    });
    act(() => copyButton().props.onPress());
    act(() =>
      renderer.update(
        <SettingsAccountIdentity key="456" id="456" name="مريم" />,
      ),
    );
    expect(visibleText()).not.toContain('تم النسخ');
    expect(visibleText()).toContain('UID: 456');
    act(() => copyButton().props.onPress());
    expect(Clipboard.setString).toHaveBeenLastCalledWith('456');
  });
});
