import React from 'react';
import {
  AppState,
  type AppStateStatus,
  Linking,
  Modal,
  Text,
} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockSupported = true;
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 12, bottom: 8, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('../src/services/smartReminders', () => ({
  areSmartRemindersSupported: () => mockSupported,
}));
// Decoration only; exercise the real modal, actions and foreground ownership.
jest.mock('react-native-svg', () => ({
  __esModule: true,
  default: 'Svg',
  Circle: 'Circle',
  Path: 'Path',
}));
jest.mock('../src/assets/SVG', () => ({MoreBellIcon: () => null}));
jest.mock('../src/components/ui/RoknCoin', () => ({RoknCoinStack: () => null}));

import NotificationPermissionPrimer from '../src/components/ui/NotificationPermissionPrimer';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((accept, fail) => {
    resolve = accept;
    reject = fail;
  });
  return {promise, resolve, reject};
};

describe('notification primer recovers according to permission versus activation failure', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const enable = jest.fn<Promise<boolean>, []>();
  const close = jest.fn();
  const listeners = new Set<(state: AppStateStatus) => void>();
  const view = (visible = true) => (
    <NotificationPermissionPrimer
      visible={visible}
      onEnable={enable}
      onClose={close}
    />
  );
  const button = (label: string) =>
    renderer.root
      // RN's memoized export is not the rendered button type. Keep the
      // real primer and select its actual CTA by role and visible text.
      .findAllByProps({accessibilityRole: 'button'})
      .find(node =>
        node.findAllByType(Text).some(text => text.props.children === label),
      )!;
  const text = () =>
    renderer.root.findAllByType(Text).map(node => node.props.children);
  const press = async (label: string) => {
    await act(async () => {
      button(label).props.onPress();
    });
  };
  const emit = async (state: AppStateStatus) => {
    await act(async () => {
      for (const listener of Array.from(listeners)) listener(state);
    });
  };
  const returnFromSettings = async () => {
    await emit('background');
    await emit('active');
  };

  beforeEach(async () => {
    mockSupported = true;
    enable.mockReset().mockResolvedValue(true);
    close.mockReset();
    listeners.clear();
    jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((_event, handler) => {
        listeners.add(handler);
        return {
          remove: () => {
            listeners.delete(handler);
          },
        };
      });
    // The RN preset's pre-existing jest.fn survives restoreAllMocks.
    jest.spyOn(Linking, 'openSettings').mockClear().mockResolvedValue(undefined);
    await act(async () => {
      renderer = TestRenderer.create(view());
    });
  });
  afterEach(async () => {
    await act(async () => renderer.unmount());
    jest.restoreAllMocks();
  });

  it('shows one busy activation after a real settings return and offers retry for a rejected account write', async () => {
    const activation = deferred<boolean>();
    enable
      .mockResolvedValueOnce(false)
      .mockImplementationOnce(() => activation.promise);
    await press('فعّل الإشعارات');
    expect(text()).toContain('الإشعارات متوقفة');
    await press('فتح إعدادات الهاتف');
    expect(enable).toHaveBeenCalledTimes(1);
    await emit('active'); // Settings launch completion alone is not a return.
    expect(enable).toHaveBeenCalledTimes(1);
    await returnFromSettings();
    expect(enable).toHaveBeenCalledTimes(2);
    expect(button('جارٍ التفعيل').props.disabled).toBe(true);
    expect(button('لاحقًا').props.disabled).toBe(true);
    await emit('active');
    await press('جارٍ التفعيل'); // Native disabled plus synchronous flight guard.
    await act(async () =>
      renderer.root.findByType(Modal).props.onRequestClose(),
    );
    expect(enable).toHaveBeenCalledTimes(2);
    expect(close).not.toHaveBeenCalled();
    await act(async () => activation.reject(new Error('offline')));
    expect(text()).toContain('تعذّر تفعيل الإشعارات');
    expect(text()).not.toContain('اسمح بإشعارات ركن من إعدادات الهاتف');
    await press('حاول مرة أخرى');
    expect(enable).toHaveBeenCalledTimes(3);
    expect(Linking.openSettings).toHaveBeenCalledTimes(1);
    expect(close).toHaveBeenCalledTimes(1);
  });

  it('keeps settings as recovery only while the OS still denies permission', async () => {
    enable.mockResolvedValue(false);
    await press('فعّل الإشعارات');
    await press('فتح إعدادات الهاتف');
    await returnFromSettings();
    expect(text()).toContain('الإشعارات متوقفة');
    expect(close).not.toHaveBeenCalled();
    await returnFromSettings(); // No automatic retries on unrelated foregrounds.
    expect(enable).toHaveBeenCalledTimes(2);
  });

  it('uses the same retry for a failed initial activation', async () => {
    enable.mockRejectedValueOnce(new Error('token-registration'));
    await press('فعّل الإشعارات');
    expect(text()).toContain('تعذّر تفعيل الإشعارات');
    await press('حاول مرة أخرى');
    expect(close).toHaveBeenCalledTimes(1);
    expect(Linking.openSettings).not.toHaveBeenCalled();
  });

  it('explains a settings launch failure and lets the user try opening settings again', async () => {
    enable.mockResolvedValueOnce(false);
    (Linking.openSettings as jest.Mock).mockRejectedValueOnce(
      new Error('no-settings'),
    );
    await press('فعّل الإشعارات');
    await press('فتح إعدادات الهاتف');
    expect(text()).toContain('تعذّر فتح إعدادات الهاتف');
    expect(enable).toHaveBeenCalledTimes(1);
    await press('فتح إعدادات الهاتف');
    await returnFromSettings();
    expect(Linking.openSettings).toHaveBeenCalledTimes(2);
    expect(close).toHaveBeenCalledTimes(1);
  });

  it('does not let a late launch rejection overwrite activation already started on return', async () => {
    const launch = deferred<void>();
    const activation = deferred<boolean>();
    enable
      .mockResolvedValueOnce(false)
      .mockImplementationOnce(() => activation.promise);
    (Linking.openSettings as jest.Mock).mockImplementationOnce(
      () => launch.promise,
    );
    await press('فعّل الإشعارات');
    await press('فتح إعدادات الهاتف');
    await press('فتح إعدادات الهاتف');
    expect(Linking.openSettings).toHaveBeenCalledTimes(1);
    await returnFromSettings();
    await act(async () => launch.reject(new Error('late-launch-result')));
    expect(text()).toContain('جارٍ التفعيل');
    expect(text()).not.toContain('تعذّر فتح إعدادات الهاتف');
    await act(async () => activation.resolve(true));
    expect(close).toHaveBeenCalledTimes(1);
  });

  it.each(['success', 'denied', 'failed'] as const)(
    'retains an old physical flight but ignores its %s result after hide and reopen',
    async outcome => {
      const old = deferred<boolean>();
      enable.mockImplementationOnce(() => old.promise);
      await press('فعّل الإشعارات');
      await act(async () => renderer.update(view(false)));
      await act(async () => renderer.update(view(true)));
      expect(text()).toContain('جارٍ التفعيل');
      await press('جارٍ التفعيل');
      expect(enable).toHaveBeenCalledTimes(1);
      await act(async () => {
        if (outcome === 'failed') old.reject(new Error('old-offline'));
        else old.resolve(outcome === 'success');
      });
      expect(close).not.toHaveBeenCalled();
      expect(text()).not.toContain('الإشعارات متوقفة');
      expect(text()).not.toContain('تعذّر تفعيل الإشعارات');
      expect(button('فعّل الإشعارات').props.disabled).toBe(false);
      await press('فعّل الإشعارات');
      expect(enable).toHaveBeenCalledTimes(2);
      expect(close).toHaveBeenCalledTimes(1);
    },
  );

  it('retires a settings-return listener when the primer was closed', async () => {
    enable.mockResolvedValueOnce(false);
    await press('فعّل الإشعارات');
    await press('فتح إعدادات الهاتف');
    await act(async () => renderer.update(view(false)));
    await returnFromSettings();
    await act(async () => renderer.update(view(true)));
    await returnFromSettings();
    expect(enable).toHaveBeenCalledTimes(1);
    expect(text()).toContain('فعّل الإشعارات');
  });

  it('does not invoke the old close callback after unmount', async () => {
    const activation = deferred<boolean>();
    enable.mockImplementationOnce(() => activation.promise);
    await press('فعّل الإشعارات');
    await act(async () => renderer.unmount());
    await act(async () => activation.resolve(true));
    expect(close).not.toHaveBeenCalled();
    expect(listeners.size).toBe(0);
  });

  it('keeps the unsupported-device action local without requesting activation', async () => {
    mockSupported = false;
    await act(async () => renderer.update(view()));
    await press('إغلاق');
    expect(close).toHaveBeenCalledTimes(1);
    expect(enable).not.toHaveBeenCalled();
  });
});
