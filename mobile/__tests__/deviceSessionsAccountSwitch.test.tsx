import React from 'react';
import {Alert, Pressable, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockUser: Record<string, unknown> = {id: 1, api_token: 'token-a'};

jest.mock('@react-navigation/native', () => {
  const ReactModule = require('react');
  return {
    useFocusEffect: (effect: () => void | (() => void)) =>
      ReactModule.useEffect(effect, [effect]),
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
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  return {Container: Wrapper, Content: Wrapper};
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
import {
  getDeviceSessions,
  revokeDeviceSession,
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
    mockUser = {id: 1, api_token: 'token-a'};
    jest
      .mocked(getDeviceSessions)
      .mockResolvedValueOnce([session('11111111-1111-4111-8111-111111111111')])
      .mockResolvedValueOnce([session('22222222-2222-4222-8222-222222222222')]);
  });

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

  it('ignores a rejected list request owned by the previous account', async () => {
    const oldRequest = deferred<ReturnType<typeof session>[]>();
    jest.mocked(getDeviceSessions).mockReset();
    jest
      .mocked(getDeviceSessions)
      .mockReturnValueOnce(oldRequest.promise)
      .mockResolvedValueOnce([
        session('22222222-2222-4222-8222-222222222222'),
      ]);

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
      .mockResolvedValueOnce([
        session('22222222-2222-4222-8222-222222222222'),
      ]);
    jest.mocked(revokeDeviceSession).mockReturnValueOnce(oldMutation.promise);
    const alert = jest
      .spyOn(Alert, 'alert')
      .mockImplementation((_title, _message, buttons) => {
        void buttons?.find(button => button.style === 'destructive')?.onPress?.();
      });

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<DeviceSessions />);
    });
    const oldRevoke = renderer.root
      .findAllByType(Pressable)
      .find(node =>
        String(renderedText({root: node} as TestRenderer.ReactTestRenderer)).includes(
          'تسجيل الخروج من الجهاز',
        ),
      );
    await act(async () => oldRevoke?.props.onPress());

    mockUser = {id: 2, api_token: 'token-b'};
    await act(async () => renderer.update(<DeviceSessions />));
    await act(async () => oldMutation.resolve());

    expect(renderedText(renderer)).toContain('هذا الجهاز');
    expect(renderedText(renderer)).not.toContain('ستظهر أجهزتك هنا');
    alert.mockRestore();
    await act(async () => renderer.unmount());
  });
});
