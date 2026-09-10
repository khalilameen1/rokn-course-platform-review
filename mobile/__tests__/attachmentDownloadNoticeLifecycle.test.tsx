import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Modal, Platform, StyleSheet, Text} from 'react-native';
import {AttachmentDownloadNoticeHost} from '../src/components/VideoPlayer/AttachmentDownloadNoticeHost';
import {Palette} from '../src/constants/designSystem';
import {
  AttachmentDownloadNotice,
  beginAttachmentDownloadNotice,
  cancelAttachmentDownloadNotices,
} from '../src/components/VideoPlayer/attachmentDownloadNotice';

jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 20, bottom: 12, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => false,
}));

// Native Modal events are delivered explicitly: this proves the JS event
// boundary, not UIKit presentation/animation acceptance on a physical iPhone.
describe('attachment download notice handoff', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let handles: AttachmentDownloadNotice[];
  const originalOS = Platform.OS;
  const mount = () =>
    act(() => {
      renderer = TestRenderer.create(<AttachmentDownloadNoticeHost />);
    });
  const begin = (
    title = 'ملف الدرس',
    cancel = jest.fn(),
    nativeShows = true,
  ) => {
    let handle!: AttachmentDownloadNotice;
    act(() => {
      handle = beginAttachmentDownloadNotice(title, '12 MB', cancel);
      handles.push(handle);
    });
    if (
      renderer &&
      Platform.OS === 'ios' &&
      nativeShows &&
      modal().props.visible
    ) {
      event('onShow');
    }
    return handle;
  };
  const modal = () => renderer!.root.findByType(Modal);
  const buttons = () =>
    renderer!.root.findAll(
      node =>
        node.props.accessibilityRole === 'button' &&
        typeof node.props.onPress === 'function',
    );
  const event = (name: 'onShow' | 'onDismiss') =>
    act(() => modal().props[name]());
  const dismiss = (handle: AttachmentDownloadNotice) => {
    let receipt!: Promise<void>;
    act(() => {
      receipt = handle.dismiss();
    });
    return receipt;
  };
  beforeEach(() => {
    Object.defineProperty(Platform, 'OS', {configurable: true, value: 'ios'});
    handles = [];
  });
  afterEach(() => {
    act(() => {
      renderer?.unmount();
      handles.forEach(handle => handle.release());
    });
    renderer = undefined;
    Object.defineProperty(Platform, 'OS', {
      configurable: true,
      value: originalOS,
    });
  });

  it('renders one grouped notice with file labels and accessible cancel/hide targets', () => {
    mount();
    begin('أول ملف');
    begin('ثاني ملف');
    expect(renderer!.root.findAllByType(Modal)).toHaveLength(1);
    const texts = renderer!.root
      .findAllByType(Text)
      .map(node => node.props.children);
    expect(texts).toEqual(
      expect.arrayContaining(['أول ملف', 'ثاني ملف', '12 MB', 'إخفاء']),
    );
    const actionLabels = renderer!.root
      .findAllByType(Text)
      .filter(node => ['إخفاء', 'إلغاء'].includes(node.props.children));
    for (const label of actionLabels) {
      expect(StyleSheet.flatten(label.props.style).writingDirection).toBe(
        'rtl',
      );
    }
    const hideLabel = actionLabels.find(
      node => node.props.children === 'إخفاء',
    )!;
    expect(StyleSheet.flatten(hideLabel.props.style).color).toBe(Palette.text);
    expect(buttons().length).toBeGreaterThanOrEqual(3);
    buttons().forEach(button => {
      expect(StyleSheet.flatten(button.props.style)).toMatchObject({
        minHeight: 48,
        minWidth: 48,
      });
    });
  });

  it('waits for actual onDismiss after onShow, not merely visible=false', async () => {
    mount();
    const handle = begin();
    event('onShow');
    const done = jest.fn();
    const receipt = dismiss(handle).then(done);
    expect(modal().props.visible).toBe(false);
    await act(async () => {
      await Promise.resolve();
    });
    expect(done).not.toHaveBeenCalled();
    event('onDismiss');
    await receipt;
    expect(done).toHaveBeenCalledTimes(1);
  });

  it('waits after the committed request even when completion precedes onShow', async () => {
    mount();
    const handle = begin('not shown yet', jest.fn(), false);
    const done = jest.fn();
    const receipt = dismiss(handle).then(done);
    // Fabric emits onDismiss only for an actually presented modal. Never
    // withdraw the committed request while native mounting is still pending.
    expect(modal().props.visible).toBe(true);
    await act(async () => {
      await Promise.resolve();
    });
    expect(done).not.toHaveBeenCalled();
    event('onShow');
    expect(modal().props.visible).toBe(false);
    await act(async () => {
      await Promise.resolve();
    });
    expect(done).not.toHaveBeenCalled();
    event('onDismiss');
    await receipt;
  });

  it('uses a distinct Modal instance when closing and the next cycle start are batched', async () => {
    mount();
    const first = begin();
    const previous = modal();
    const previousDismiss = previous.props.onDismiss;
    const receipt = dismiss(first);
    act(() => {
      previousDismiss();
      first.release();
      handles.push(beginAttachmentDownloadNotice('next', undefined, jest.fn()));
    });
    await receipt;
    expect(modal()).not.toBe(previous);
    expect(modal().props.visible).toBe(true);
    act(() => previousDismiss());
    expect(modal().props.visible).toBe(true);
  });

  it('settles immediately when the notice was never committed for presentation', async () => {
    const handle = begin();
    await dismiss(handle);
    mount();
    expect(modal().props.visible).toBe(false);
    act(() => handle.release());
    expect(renderer!.root.findAllByType(Modal)).toHaveLength(0);
  });

  it('shares one dismissal receipt and keeps new transfers hidden throughout pending native saves', async () => {
    mount();
    const first = begin('one');
    const second = begin('two');
    const firstReceipt = dismiss(first);
    const secondReceipt = dismiss(second);
    expect(secondReceipt).toBe(firstReceipt);
    const duringClosing = begin('three');
    expect(modal().props.visible).toBe(false);
    event('onDismiss');
    await Promise.all([firstReceipt, secondReceipt]);
    act(() => {
      first.release();
      second.release();
    });
    const duringSave = begin('four');
    expect(modal().props.visible).toBe(false);
    act(() => {
      duringClosing.release();
      duringSave.release();
    });
    begin('independent');
    expect(modal().props.visible).toBe(true);
  });

  it('does not release a presented cycle before its onDismiss even when all actions release', async () => {
    mount();
    const first = begin();
    const receipt = dismiss(first);
    act(() => first.release());
    const second = begin('joined');
    expect(modal().props.visible).toBe(false);
    event('onDismiss');
    await receipt;
    act(() => second.release());
    begin('next');
    expect(modal().props.visible).toBe(true);
  });

  it('Hide allows transfers to continue without firing their cancel actions', async () => {
    mount();
    const cancel = jest.fn();
    const handle = begin('one', cancel);
    const controls = buttons();
    act(() => controls[controls.length - 1].props.onPress());
    expect(cancel).not.toHaveBeenCalled();
    const receipt = dismiss(handle);
    event('onDismiss');
    await receipt;
  });

  it('individual Cancel affects only that transfer and closes the grouped notice', async () => {
    mount();
    const firstCancel = jest.fn();
    const secondCancel = jest.fn();
    const first = begin('one', firstCancel);
    begin('two', secondCancel);
    const cancel = buttons()[0].props.onPress;
    act(() => {
      cancel();
      cancel();
    });
    expect(firstCancel).toHaveBeenCalledTimes(1);
    expect(secondCancel).not.toHaveBeenCalled();
    expect(modal().props.visible).toBe(false);
    event('onDismiss');
    await dismiss(first);
  });

  it('account retirement cancels all retained handles once and still waits for dismissal', async () => {
    mount();
    const cancel = jest.fn(() => {
      throw new Error('cancel failed');
    });
    const other = jest.fn();
    const first = begin('one', cancel);
    begin('two', other);
    act(() => {
      cancelAttachmentDownloadNotices();
      cancelAttachmentDownloadNotices();
    });
    expect(cancel).toHaveBeenCalledTimes(1);
    expect(other).toHaveBeenCalledTimes(1);
    const done = jest.fn();
    const receipt = dismiss(first).then(done);
    await act(async () => {
      await Promise.resolve();
    });
    expect(done).not.toHaveBeenCalled();
    event('onDismiss');
    await receipt;
  });

  it('ignores old modal callbacks after a new independent cycle starts', async () => {
    mount();
    const first = begin();
    const old = modal().props;
    const receipt = dismiss(first);
    event('onDismiss');
    await receipt;
    act(() => first.release());
    begin('new');
    act(() => {
      old.onShow();
      old.onDismiss();
      old.onRequestClose();
    });
    expect(modal().props.visible).toBe(true);
  });

  it('host removal cancels actions before releasing pending handoff receipts', async () => {
    mount();
    const sequence: string[] = [];
    const handle = begin(
      'one',
      jest.fn(() => sequence.push('cancel')),
    );
    const receipt = dismiss(handle).then(() => sequence.push('dismiss'));
    act(() => renderer!.unmount());
    renderer = undefined;
    await receipt;
    expect(sequence).toEqual(['cancel', 'dismiss']);
  });

  it('does not mount or retain an Android notice', async () => {
    Object.defineProperty(Platform, 'OS', {
      configurable: true,
      value: 'android',
    });
    mount();
    const cancel = jest.fn();
    await dismiss(begin('android', cancel));
    expect(renderer!.toJSON()).toBeNull();
    expect(cancel).not.toHaveBeenCalled();
  });
});
