import React from 'react';
import {Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

import {CourseChatGate} from '../src/components/VideoPlayer/courseChat/CourseChatGate';

const baseProps = {
  accessUnavailable: false,
  courseAccessRequired: false,
  courseChatUnavailable: false,
  onUpgrade: jest.fn(),
  onOpenCourseAccess: jest.fn(),
  planLimitReached: false,
};

describe('course enquiries entry gate', () => {
  beforeEach(() => jest.clearAllMocks());

  it.each([false, true])(
    'explains the upgrade before opening checkout when exhausted is %s',
    async exhausted => {
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(
          <CourseChatGate {...baseProps} planLimitReached={exhausted} />,
        );
      });
      const copy = renderer.root
        .findAllByType(Text)
        .map(node => node.props.children);
      expect(copy).toContain(
        exhausted ? 'استخدمت كل رسائلك' : 'اشتراكك لا يشمل الشات',
      );
      expect(baseProps.onUpgrade).not.toHaveBeenCalled();
      await act(async () =>
        renderer.root
          .findByProps({accessibilityLabel: 'ترقية الاشتراك'})
          .props.onPress(),
      );
      expect(baseProps.onUpgrade).toHaveBeenCalledTimes(1);
      await act(async () => renderer.unmount());
    },
  );

  it('returns a guest sample to course access without requesting an upgrade quote', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <CourseChatGate {...baseProps} courseAccessRequired />,
      );
    });

    const action = renderer.root.findByProps({
      accessibilityLabel: 'عرض الاشتراكات',
    });
    await act(async () => action.props.onPress());

    expect(baseProps.onOpenCourseAccess).toHaveBeenCalledTimes(1);
    expect(baseProps.onUpgrade).not.toHaveBeenCalled();
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
    expect(copy).toContain('الشات غير متاح في هذا الكورس');
    expect(
      renderer.root.findAllByProps({
        accessibilityLabel: 'ترقية الاشتراك',
      }),
    ).toHaveLength(0);
    expect(baseProps.onUpgrade).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });
});
