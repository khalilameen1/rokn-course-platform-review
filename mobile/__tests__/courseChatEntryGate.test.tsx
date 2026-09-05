import React from 'react';
import {Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

import {CourseChatGate} from '../src/components/VideoPlayer/courseChat/CourseChatGate';

const baseProps = {
  accessUnavailable: false,
  courseAccessRequired: false,
  courseChatUnavailable: false,
  error: '',
  loading: false,
  onConfirm: jest.fn(),
  onLoadQuote: jest.fn(),
  onOpenCourseAccess: jest.fn(),
  planLimitReached: false,
  quote: null,
  scholarshipAccess: false,
};

describe('course enquiries entry gate', () => {
  beforeEach(() => jest.clearAllMocks());

  it('returns a guest sample to course access without requesting an upgrade quote', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <CourseChatGate {...baseProps} courseAccessRequired />,
      );
    });

    const action = renderer.root.findByProps({
      accessibilityLabel: 'عرض فئات الكورس',
    });
    await act(async () => action.props.onPress());

    expect(baseProps.onOpenCourseAccess).toHaveBeenCalledTimes(1);
    expect(baseProps.onLoadQuote).not.toHaveBeenCalled();
    expect(baseProps.onConfirm).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });

  it('does not invent a purchase action for a free course without enquiries', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <CourseChatGate {...baseProps} courseChatUnavailable />,
      );
    });

    const copy = renderer.root
      .findAllByType(Text)
      .flatMap(node => node.props.children)
      .filter(value => typeof value === 'string');
    expect(copy).toContain('الاستفسارات غير متاحة في هذا الكورس');
    expect(
      renderer.root.findAllByProps({
        accessibilityLabel: 'عرض خيارات الاستفسارات',
      }),
    ).toHaveLength(0);
    expect(baseProps.onLoadQuote).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });
});
