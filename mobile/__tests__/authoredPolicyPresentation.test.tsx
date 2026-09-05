import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Text} from 'react-native';
import {cleanUnicodeText} from '../src/utils/unicodeText';

const mockMethods = jest.fn();
const mockNavigation = {addListener: () => () => undefined};
const mockRoute = {params: {}};
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => mockNavigation,
  useRoute: () => mockRoute,
}));
jest.mock('react-redux', () => ({
  useDispatch: () => jest.fn(),
  useSelector: () => null,
}));
jest.mock('expo-apple-authentication', () => ({}));
jest.mock('../src/services/socialAuth', () => ({
  getSocialAuthMethods: () => mockMethods(),
  signInWithSocialProvider: jest.fn(),
}));
jest.mock('../src/services/secureSession', () => ({
  peekSecureSession: () => ({session: null}),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/services/guestAccountMigration', () => ({}));
jest.mock('../src/navigation/authReturn', () => ({}));
jest.mock('../src/components/containers/Containers', () => ({
  Container: ({children}: {children: React.ReactNode}) => children,
  Content: ({children}: {children: React.ReactNode}) => children,
}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));

import SocialAuthShell from '../src/components/auth/SocialAuthShell';
import SocialAuthView from '../src/components/auth/SocialAuthView';
import AppUpdateGate from '../src/components/AppUpdateGate';
import {parseAppVersionResponse} from '../src/services/appVersionPolicy';

const visibleText = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root
    .findAllByType(Text)
    .map(node => cleanUnicodeText(node.props.children));

describe('authored settings and release text', () => {
  it('renders a discovered recommendation without treating Google as an API error', async () => {
    const recommendation = 'Google: Fast sign-in + 25 coins — ريلز 2026';
    mockMethods.mockResolvedValue({
      providers: ['google'],
      recommendedProvider: 'google',
      recommendationText: recommendation,
      authorizationUrls: {google: 'https://rokn.app/api/auth/google'},
      authorizationApiUrl: 'https://rokn.app/api',
      welcomeBonus: 25,
    });
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<SocialAuthShell />);
      });
      expect(visibleText(renderer)).toContain(recommendation);
      expect(renderer.root.findByType(SocialAuthView).props).toMatchObject({
        orderedProviderIds: ['google'],
        recommendedProvider: 'google',
        phase: 'ready',
      });
    } finally {
      act(() => renderer?.unmount());
    }
  });

  it('renders authored update notes while retaining the mandatory gate', () => {
    const message = 'Rokn 2: fixes for OAuth';
    const notes = 'ريلز 2026\nSQLSTATE <input required>\n  const limit = 3;';
    const notice = parseAppVersionResponse(
      {
        update_required: true,
        is_force_update: true,
        download_url: 'https://rokn.app/releases/Rokn.apk',
        update_message: message,
        release_notes: notes,
      },
      'direct',
    );
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      act(() => {
        renderer = TestRenderer.create(
          <AppUpdateGate notice={notice} onDismiss={jest.fn()} />,
        );
      });
      expect(visibleText(renderer)).toContain(message);
      expect(visibleText(renderer)).toContain(notes);
      expect(visibleText(renderer)).toContain('حدّث ركن للمتابعة');
      expect(visibleText(renderer)).not.toContain('لاحقًا');
    } finally {
      act(() => renderer?.unmount());
    }
  });
});
