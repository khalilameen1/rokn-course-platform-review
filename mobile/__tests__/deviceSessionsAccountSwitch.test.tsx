import React from 'react';
import {Alert, ScrollView, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockUser: Record<string, unknown> = {id: 1, api_token: 'token-a'};
let mockFocused = true;

jest.mock('@react-navigation/native', () => {
  const ReactModule = require('react');
  return {
    useFocusEffect: (effect: () => void | (() => void)) =>
      ReactModule.useEffect(
        () => (mockFocused ? effect() : undefined),
        [effect, mockFocused],
      ),
    useNavigation: () => ({reset: jest.fn()}),
  };
});

jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({bottom: 0, left: 0, right: 0, top: 0}),
}));

jest.mock('react-redux', () => ({
  useSelector: (selector: (state: unknown) => unknown) =>
    selector({auth: {userData: mockUser}}),
}));

jest.mock('../src/components/containers/Containers', () => {
  const ReactModule = require('react');
  const {View} = require('react-native');
  const {Content} = jest.requireActual(
    '../src/components/containers/Containers',
  );
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  // Content owns the production ScrollView and forwards its RefreshControl.
  // Replacing it with a View silently discards the pull-to-refresh boundary.
  return {Container: Wrapper, Content};
});

jest.mock('../src/components/ui/PremiumUI', () => {
  const ReactModule = require('react');
  const {Text: RNText, View} = require('react-native');
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  return {
    PremiumCard: Wrapper,
    ResponsiveFrame: Wrapper,
    StatusView: ({title}: {title: string}) =>
      ReactModule.createElement(RNText, null, title),
  };
});

jest.mock('../src/components/view/HeaderWithBack', () => {
  const ReactModule = require('react');
  const {Text: RNText} = require('react-native');
  return ({title}: {title: string}) =>
    ReactModule.createElement(RNText, null, title);
});

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 0,
    scope: `user-${String(mockUser.id)}`,
  })),
  extractApiToken: (user: Record<string, unknown>) => user.api_token,
  sessionIdentityKey: (user: Record<string, unknown>) =>
    `user-${String(user.id)}`,
}));

jest.mock('../src/constants/deviceClass', () => ({
  currentDeviceClass: () => 'phone',
}));

jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));

jest.mock('../src/services/deviceSessions', () => ({
  getDeviceSessions: jest.fn(),
  revokeDeviceSession: jest.fn(),
  revokeOtherDeviceSessions: jest.fn(),
}));

import DeviceSessions from '../src/screens/DeviceSessions';
import {captureAccountSessionBoundary} from '../src/constants/helpers';
import {
  getDeviceSessions,
  revokeDeviceSession,
  revokeOtherDeviceSessions,
} from '../src/services/deviceSessions';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return {promise, reject, resolve};
};

const renderedText = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root
    .findAllByType(Text)
    .flatMap(node => node.props.children)
    .filter(value => typeof value === 'string')
    .join(' ');

const buttonForText = (
  renderer: TestRenderer.ReactTestRenderer,
  label: string,
) => {
  let node: TestRenderer.ReactTestInstance | null =
    renderer.root
      .findAllByType(Text)
      .find(candidate => candidate.props.children === label) || null;
  while (node && typeof node.props.onPress !== 'function') node = node.parent;
  if (!node) throw new Error(`Missing button: ${label}`);
  return node;
};

const session = (id: string, current = true) => ({
  app_build: '1',
  app_version: '1.0.0',
  current,
  device_class: 'phone' as const,
  expires_at: null,
  id,
  issued_at: '2026-09-05T10:00:00.000Z',
  last_used_at: '2026-09-05T10:00:00.000Z',
  platform: 'android' as const,
});

describe('device sessions account ownership', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.mocked(revokeDeviceSession).mockReset();
    jest.mocked(revokeOtherDeviceSessions).mockReset();
    mockFocused = true;
    mockUser = {id: 1, api_token: 'token-a'};
    jest
      .mocked(getDeviceSessions)
      .mockResolvedValueOnce([session('11111111-1111-4111-8111-111111111111')])
      .mockResolvedValueOnce([session('22222222-2222-4222-8222-222222222222')]);
  });

  it('refreshes through the single production Content scroller and settles its spinner', async () => {
    const current = session('11111111-1111-4111-8111-111111111111');
    const refreshRead = deferred<ReturnType<typeof session>[]>();
    jest
      .mocked(getDeviceSessions)
      .mockReset()
      .mockResolvedValueOnce([current])
      .mockReturnValueOnce(refreshRead.promise);
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<DeviceSessions />);
    });
    try {
      expect(renderer.root.findAllByType(ScrollView)).toHaveLength(1);
      const refreshControl = () =>
        renderer.root.findByType(ScrollView).props.refreshControl;
      expect(refreshControl().props.refreshing).toBe(false);
      await act(async () => refreshControl().props.onRefresh());
      expect(getDeviceSessions).toHaveBeenCalledTimes(2);
      expect(refreshControl().props.refreshing).toBe(true);
      await act(async () => refreshRead.resolve([current]));
      expect(refreshControl().props.refreshing).toBe(false);
      expect(renderedText(renderer)).toContain('هذا الجهاز');
    } finally {
      await act(async () => renderer.unmount());
    }
  });

  it.each(
    ['تسجيل الخروج من الجهاز', 'تسجيل الخروج من الأجهزة الأخرى'].flatMap(
      label => ['account switch', 'blur'].map(exit => [label, exit]),
    ),
  )(
    'does not send %s after ownership changes during session capture: %s',
    async (label, exit) => {
      jest
        .mocked(getDeviceSessions)
        .mockReset()
        .mockResolvedValue([
          session('11111111-1111-4111-8111-111111111111'),
          session('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', false),
        ]);
      const capture = deferred<{epoch: number; scope: string}>();
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation((_title, _message, buttons) => {
          void buttons
            ?.find(button => button.style === 'destructive')
            ?.onPress?.();
        });
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<DeviceSessions />);
      });
      try {
        jest
          .mocked(captureAccountSessionBoundary)
          .mockReturnValueOnce(capture.promise);
        await act(async () => buttonForText(renderer, label).props.onPress());
        if (exit === 'account switch') mockUser = {id: 2, api_token: 'token-b'};
        else mockFocused = false;
        await act(async () => renderer.update(<DeviceSessions />));
        await act(async () =>
          capture.resolve({epoch: 2, scope: `user-${mockUser.id}`}),
        );
        expect(revokeDeviceSession).not.toHaveBeenCalled();
        expect(revokeOtherDeviceSessions).not.toHaveBeenCalled();
      } finally {
        alert.mockRestore();
        await act(async () => renderer.unmount());
      }
    },
  );

  it.each(['success', 'failure'])(
    'settles an interrupted refresh and coalesces queued refreshes after revoke %s',
    async outcome => {
      const current = session('11111111-1111-4111-8111-111111111111');
      const other = session('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', false);
      const stale = deferred<ReturnType<typeof session>[]>();
      const mutation = deferred<void>();
      jest
        .mocked(getDeviceSessions)
        .mockReset()
        .mockResolvedValueOnce([current, other])
        .mockReturnValueOnce(stale.promise)
        .mockResolvedValueOnce(
          outcome === 'success' ? [current] : [current, other],
        );
      jest.mocked(revokeDeviceSession).mockReturnValueOnce(mutation.promise);
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation((_title, _message, buttons) => {
          void buttons
            ?.find(button => button.style === 'destructive')
            ?.onPress?.();
        });
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<DeviceSessions />);
      });
      try {
        const refresh = () =>
          renderer.root
            .findByType(ScrollView)
            .props.refreshControl.props.onRefresh();
        const revokeButton = buttonForText(renderer, 'تسجيل الخروج من الجهاز');
        await act(async () => refresh());
        await act(async () => revokeButton.props.onPress());
        await act(async () => {
          revokeButton.props.onPress();
          refresh();
          refresh();
        });
        expect(revokeDeviceSession).toHaveBeenCalledTimes(1);
        expect(getDeviceSessions).toHaveBeenCalledTimes(2);
        await act(async () => {
          if (outcome === 'success') mutation.resolve();
          else mutation.reject(new Error('offline'));
        });
        await act(async () => stale.resolve([current, other]));
        expect(getDeviceSessions).toHaveBeenCalledTimes(3);
        expect(
          renderer.root.findByType(ScrollView).props.refreshControl.props
            .refreshing,
        ).toBe(false);
        expect(
          renderedText(renderer).includes('تسجيل الخروج من الأجهزة الأخرى'),
        ).toBe(outcome === 'failure');
      } finally {
        alert.mockRestore();
        await act(async () => renderer.unmount());
      }
    },
  );

  it.each(['blur', 'unmount'])(
    'does not run a queued refresh after %s',
    async exit => {
      const mutation = deferred<void>();
      jest
        .mocked(getDeviceSessions)
        .mockReset()
        .mockResolvedValue([
          session('11111111-1111-4111-8111-111111111111'),
          session('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', false),
        ]);
      jest.mocked(revokeDeviceSession).mockReturnValueOnce(mutation.promise);
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation((_title, _message, buttons) => {
          void buttons
            ?.find(button => button.style === 'destructive')
            ?.onPress?.();
        });
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<DeviceSessions />);
      });
      await act(async () =>
        buttonForText(renderer, 'تسجيل الخروج من الجهاز').props.onPress(),
      );
      await act(async () =>
        renderer.root
          .findByType(ScrollView)
          .props.refreshControl.props.onRefresh(),
      );
      await act(async () => {
        if (exit === 'unmount') renderer.unmount();
        else {
          mockFocused = false;
          renderer.update(<DeviceSessions />);
        }
      });
      await act(async () => mutation.resolve());
      expect(getDeviceSessions).toHaveBeenCalledTimes(1);
      if (exit === 'blur') {
        await act(async () => {
          mockFocused = true;
          renderer.update(<DeviceSessions />);
        });
        expect(getDeviceSessions).toHaveBeenCalledTimes(2);
        await act(async () => renderer.unmount());
      }
      alert.mockRestore();
    },
  );

  it.each(['selected', 'other devices'])(
    'does not restore revoked %s from a refresh started during revocation',
    async target => {
      const current = session('11111111-1111-4111-8111-111111111111');
      const other = session('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', false);
      const mutation = deferred<void>();
      const staleRead = deferred<ReturnType<typeof session>[]>();
      let revoked = false;
      jest
        .mocked(getDeviceSessions)
        .mockReset()
        .mockResolvedValueOnce([current, other]);
      jest.mocked(revokeDeviceSession).mockReturnValueOnce(mutation.promise);
      jest
        .mocked(revokeOtherDeviceSessions)
        .mockImplementationOnce(async () => {
          await mutation.promise;
          return 1;
        });
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation((_title, _message, buttons) => {
          void buttons
            ?.find(button => button.style === 'destructive')
            ?.onPress?.();
        });
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<DeviceSessions />);
      });
      try {
        expect(renderedText(renderer)).toContain(
          'تسجيل الخروج من الأجهزة الأخرى',
        );
        jest
          .mocked(getDeviceSessions)
          .mockImplementation(() =>
            revoked ? Promise.resolve([current]) : staleRead.promise,
          );
        const label =
          target === 'selected'
            ? 'تسجيل الخروج من الجهاز'
            : 'تسجيل الخروج من الأجهزة الأخرى';
        const revokeButton = buttonForText(renderer, label);
        await act(async () => revokeButton.props.onPress());
        await act(async () =>
          renderer.root
            .findByType(ScrollView)
            .props.refreshControl.props.onRefresh(),
        );
        revoked = true;
        await act(async () => mutation.resolve());
        await act(async () => staleRead.resolve([current, other]));
        expect(renderedText(renderer)).not.toContain(
          'تسجيل الخروج من الأجهزة الأخرى',
        );
        expect(renderedText(renderer)).toContain('هذا الجهاز');
        expect(
          renderer.root.findByType(ScrollView).props.refreshControl.props
            .refreshing,
        ).toBe(false);
      } finally {
        alert.mockRestore();
        await act(async () => renderer.unmount());
      }
    },
  );

  it('reloads when one authenticated account replaces another on the open screen', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<DeviceSessions />);
    });
    expect(getDeviceSessions).toHaveBeenCalledTimes(1);

    mockUser = {id: 2, api_token: 'token-b'};
    await act(async () => {
      renderer.update(<DeviceSessions />);
    });

    expect(getDeviceSessions).toHaveBeenCalledTimes(2);
    await act(async () => renderer.unmount());
  });

  it.each(
    ['تسجيل الخروج من الجهاز', 'تسجيل الخروج من الأجهزة الأخرى'].flatMap(
      label =>
        ['account switch', 'blur and return', 'unmount'].map(exit => [
          label,
          exit,
        ]),
    ),
  )('does not confirm an old dialog for %s after %s', async (label, exit) => {
    jest
      .mocked(getDeviceSessions)
      .mockReset()
      .mockResolvedValue([
        session('11111111-1111-4111-8111-111111111111'),
        session('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', false),
      ]);
    let confirm: (() => void) | undefined;
    const alert = jest
      .spyOn(Alert, 'alert')
      .mockImplementation((_title, _message, buttons) => {
        confirm = buttons?.find(
          button => button.style === 'destructive',
        )?.onPress;
      });
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<DeviceSessions />);
    });
    try {
      await act(async () => buttonForText(renderer, label).props.onPress());
      expect(confirm).toBeDefined();
      if (exit === 'account switch') {
        mockUser = {id: 2, api_token: 'token-b'};
        await act(async () => renderer.update(<DeviceSessions />));
      } else if (exit === 'blur and return') {
        await act(async () => {
          mockFocused = false;
          renderer.update(<DeviceSessions />);
        });
        await act(async () => {
          mockFocused = true;
          renderer.update(<DeviceSessions />);
        });
      } else {
        await act(async () => renderer.unmount());
      }
      await act(async () => confirm!());
      expect(revokeDeviceSession).not.toHaveBeenCalled();
      expect(revokeOtherDeviceSessions).not.toHaveBeenCalled();
    } finally {
      alert.mockRestore();
      if (exit !== 'unmount') await act(async () => renderer.unmount());
    }
  });

  it('ignores a rejected list request owned by the previous account', async () => {
    const oldRequest = deferred<ReturnType<typeof session>[]>();
    jest.mocked(getDeviceSessions).mockReset();
    jest
      .mocked(getDeviceSessions)
      .mockReturnValueOnce(oldRequest.promise)
      .mockResolvedValueOnce([session('22222222-2222-4222-8222-222222222222')]);

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<DeviceSessions />);
      await Promise.resolve();
    });

    mockUser = {id: 2, api_token: 'token-b'};
    await act(async () => renderer.update(<DeviceSessions />));
    expect(renderedText(renderer)).toContain('هاتف Android');

    await act(async () => oldRequest.reject(new Error('old network error')));

    expect(renderedText(renderer)).not.toContain('تعذّر تحميل الأجهزة الآن');
    expect(renderedText(renderer)).toContain('هاتف Android');
    await act(async () => renderer.unmount());
  });

  it('does not let an old revoke completion clear the new account list', async () => {
    const oldMutation = deferred<void>();
    jest.mocked(getDeviceSessions).mockReset();
    jest
      .mocked(getDeviceSessions)
      .mockResolvedValueOnce([
        session('11111111-1111-4111-8111-111111111111'),
        session('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', false),
      ])
      .mockResolvedValueOnce([session('22222222-2222-4222-8222-222222222222')]);
    jest.mocked(revokeDeviceSession).mockReturnValueOnce(oldMutation.promise);
    const alert = jest
      .spyOn(Alert, 'alert')
      .mockImplementation((_title, _message, buttons) => {
        void buttons
          ?.find(button => button.style === 'destructive')
          ?.onPress?.();
      });

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<DeviceSessions />);
    });
    const oldRevoke = buttonForText(renderer, 'تسجيل الخروج من الجهاز');
    await act(async () => oldRevoke.props.onPress());
    expect(revokeDeviceSession).toHaveBeenCalledTimes(1);

    mockUser = {id: 2, api_token: 'token-b'};
    await act(async () => renderer.update(<DeviceSessions />));
    await act(async () => oldMutation.resolve());

    expect(renderedText(renderer)).toContain('هذا الجهاز');
    expect(renderedText(renderer)).not.toContain('ستظهر أجهزتك هنا');
    alert.mockRestore();
    await act(async () => renderer.unmount());
  });
});
