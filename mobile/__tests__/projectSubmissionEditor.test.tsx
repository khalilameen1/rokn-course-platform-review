import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Image, StyleSheet, Text, TextInput, View} from 'react-native';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import ProjectSubmissionEditor from '../src/components/VideoPlayer/projectTransition/ProjectSubmissionEditor';
import type {SelectedProjectFile} from '../src/components/VideoPlayer/types';

const {execPath} = require('node:process') as {execPath: string};
const file: SelectedProjectFile = {
  uri: 'file:///project.png',
  name: 'مشروع ريلز 2026 تصميم الهوية النهائي.png',
  type: 'image/png',
};
const base = {
  draftSaveError: false,
  fileSubmissionEnabled: true,
  filePickerDisabled: false,
  fileTypesLabel: 'صور أو PDF أو DOC',
  maximumFiles: 3,
  note: 'نفذت المشروع\nوهذا وصفه',
  selectedFiles: [file],
  sending: false,
  submitDisabled: false,
  textSubmissionEnabled: true,
  onChangeNote: jest.fn(),
  onChooseFile: jest.fn(),
  onRemoveFile: jest.fn(),
  onSubmit: jest.fn(),
};

describe('project submission editor', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const render = (props: Partial<typeof base> = {}) => {
    act(() => {
      renderer = TestRenderer.create(
        <ProjectSubmissionEditor {...base} {...props} />,
      );
    });
  };
  const button = (label: string) =>
    renderer.root.findAll(
      node =>
        node.props.accessibilityLabel === label &&
        typeof node.props.onPress === 'function',
    )[0];
  const submitButton = () =>
    renderer.root.findAll(node => node.props.onPress === base.onSubmit)[0];
  beforeEach(() => jest.clearAllMocks());
  afterEach(() => act(() => renderer?.unmount()));

  it('labels the draft persistently and preserves authored text and attachment actions', () => {
    render();
    const texts = renderer.root
      .findAllByType(Text)
      .map(node => node.props.children);
    expect(texts).toContain('تسليمك');
    expect(texts).toContain('ما نفذته');
    expect(texts).toContain(file.name);
    const input = renderer.root.findByType(TextInput);
    expect(input.props.accessibilityLabel).toBe('ما نفذته');
    expect(input.props.value).toBe(base.note);
    expect(input.props.multiline).toBe(true);
    expect(input.props.textAlignVertical).toBe('top');
    act(() => {
      input.props.onChangeText('const limit = 3;');
      button('إضافة ملف').props.onPress();
      button(`إزالة ${file.name}`).props.onPress();
      submitButton().props.onPress();
    });
    expect(base.onChangeNote).toHaveBeenCalledWith('const limit = 3;');
    expect(base.onChooseFile).toHaveBeenCalledTimes(1);
    expect(base.onRemoveFile).toHaveBeenCalledWith(file);
    expect(base.onSubmit).toHaveBeenCalledTimes(1);
  });

  it.each([
    [true, false],
    [false, true],
    [true, true],
  ])(
    'preserves existing work while new input permissions are text=%s and file=%s',
    (textSubmissionEnabled, fileSubmissionEnabled) => {
      render({textSubmissionEnabled, fileSubmissionEnabled});
      expect(renderer.root.findAllByType(TextInput)).toHaveLength(1);
      expect(Boolean(button('إضافة ملف'))).toBe(fileSubmissionEnabled);
      expect(Boolean(button(`إزالة ${file.name}`))).toBe(true);
    },
  );

  it('keeps pending and file limits locked and does not invent a saved-draft success', () => {
    render({
      sending: true,
      submitDisabled: true,
      filePickerDisabled: true,
      maximumFiles: 1,
      draftSaveError: true,
    });
    expect(renderer.root.findByType(TextInput).props.editable).toBe(false);
    expect(button('إضافة ملف').props.disabled).toBe(true);
    expect(button(`إزالة ${file.name}`).props.accessibilityState).toEqual({
      disabled: true,
    });
    const submit = submitButton();
    expect(submit.props.disabled).toBe(true);
    expect(submit.props.accessibilityState).toEqual({
      busy: true,
      disabled: true,
    });
    const texts = renderer.root
      .findAllByType(Text)
      .map(node => node.props.children);
    expect(texts).toContain('جارٍ التسليم');
    expect(texts).toContain('اكتمل عدد الملفات');
    expect(
      renderer.root
        .findAllByType(Text)
        .find(node => node.props.accessibilityRole === 'alert'),
    ).toBeDefined();
    expect(texts).not.toContain('تم حفظ المسودة');
  });

  it.each([280, 528, 740])(
    'keeps file removal reachable at content width %s with large text',
    width => {
      render();
      const removal = button(`إزالة ${file.name}`);
      const row = removal.parent!;
      const name = row
        .findAllByType(Text)
        .find(node => node.props.children === file.name)!;
      const tree = {
        name: 'row',
        style: {...StyleSheet.flatten(row.props.style), width},
        children: [
          {style: StyleSheet.flatten(row.findByType(Image).props.style)},
          {
            name: 'filename',
            style: StyleSheet.flatten(name.props.style),
            measure: {width: 600, height: 40},
          },
          {name: 'remove', style: StyleSheet.flatten(removal.props.style)},
        ],
      };
      const geometry = JSON.parse(
        execFileSync(
          execPath,
          [path.join(__dirname, 'fixtures/chatYogaLayout.mjs')],
          {input: JSON.stringify(tree), encoding: 'utf8'},
        ),
      );
      expect(geometry.remove.width).toBe(48);
      expect(geometry.remove.height).toBeGreaterThanOrEqual(48);
      expect(geometry.remove.left).toBeGreaterThanOrEqual(0);
      expect(geometry.remove.left + geometry.remove.width).toBeLessThanOrEqual(
        width,
      );
      expect(geometry.filename.width).toBeGreaterThan(80);
      expect(
        StyleSheet.flatten(button('إضافة ملف').props.style).minHeight,
      ).toBeGreaterThanOrEqual(48);
      const submit = submitButton();
      expect(StyleSheet.flatten(submit.props.style)).toMatchObject({
        width: '100%',
        minHeight: 52,
      });
      const inputStyle = StyleSheet.flatten(
        renderer.root.findByType(TextInput).props.style,
      );
      expect(inputStyle.maxHeight).toBeGreaterThan(inputStyle.minHeight);
      expect(
        renderer.root
          .findAllByType(View)
          .some(
            node =>
              StyleSheet.flatten(node.props.style)?.borderStyle === 'dashed',
          ),
      ).toBe(false);
    },
  );
});
