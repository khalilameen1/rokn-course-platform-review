import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockDispatch = jest.fn();
const mockDeleteAttempt = jest.fn(async () => undefined);
const mockClearReturn = jest.fn(async () => undefined);
const mockPeek = jest.fn((): {session: {token: string} | null} => ({session: null}));
const mockNavigation = {
  addListener: jest.fn(() => () => undefined),
  canGoBack: jest.fn(() => true),
  goBack: jest.fn(),
  reset: jest.fn(),
  navigate: jest.fn(),
};
const mockReturnTo = {name: 'CourseDetails', params: {courseId: '3'}};
const mockResetState = jest.fn((returnTo, mode) => ({returnTo, mode}));

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => mockNavigation,
  useRoute: () => ({params: {returnTo: mockReturnTo}}),
}));
jest.mock('react-redux', () => ({
  useDispatch: () => mockDispatch,
  useSelector: () => null,
}));
jest.mock('../src/constants/helpers', () => ({
  extractApiToken: (session: {token?: string} | null) => session?.token || '',
}));
jest.mock('../src/store/reducers/auth', () => ({
  LogOut: () => ({type: 'logout'}),
  saveLoginData: (session: unknown) => ({type: 'login', payload: session}),
}));
jest.mock('../src/services/socialAuth', () => ({
  getSocialAuthMethods: async () => ({providers: ['google']}),
  signInWithSocialProvider: jest.fn(),
}));
jest.mock('../src/services/secureSession', () => ({
  peekSecureSession: () => mockPeek(),
  deletePendingSocialAuthAttempt: () => mockDeleteAttempt(),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/services/guestAccountMigration', () => ({}));
jest.mock('../src/navigation/authReturn', () => ({
  clearPendingLoginReturnTo: () => mockClearReturn(),
  loginReturnResetState: (returnTo: unknown, mode: string) =>
    mockResetState(returnTo, mode),
}));
jest.mock('../src/components/auth/SocialAuthView', () => () => null);

import SocialAuthShell from '../src/components/auth/SocialAuthShell';
import SocialAuthView from '../src/components/auth/SocialAuthView';

describe('login sheet dismissal', () => {
  let renderer: TestRenderer.ReactTestRenderer;

  beforeEach(async () => {
    jest.clearAllMocks();
    mockPeek.mockReturnValue({session: null});
    mockNavigation.canGoBack.mockReturnValue(true);
    await act(async () => {
      renderer = TestRenderer.create(<SocialAuthShell />);
    });
  });

  afterEach(() => act(() => renderer.unmount()));

  const dismiss = () =>
    act(async () => renderer.root.findByType(SocialAuthView).props.onExplore());

  it('reveals the mounted page without resetting its scroll or course state', async () => {
    await dismiss();

    expect(mockDeleteAttempt).toHaveBeenCalledTimes(1);
    expect(mockClearReturn).toHaveBeenCalledTimes(1);
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
    expect(mockNavigation.reset).not.toHaveBeenCalled();
  });

  it('uses the existing guest destination policy when there is no page behind it', async () => {
    mockNavigation.canGoBack.mockReturnValue(false);
    await dismiss();

    expect(mockNavigation.goBack).not.toHaveBeenCalled();
    expect(mockResetState).toHaveBeenCalledWith(mockReturnTo, 'guest');
    expect(mockNavigation.reset).toHaveBeenCalledWith({
      returnTo: mockReturnTo,
      mode: 'guest',
    });
  });

  it('closes only one route when the close button and backdrop are tapped together', async () => {
    await act(async () => {
      const {onExplore} = renderer.root.findByType(SocialAuthView).props;
      onExplore();
      onExplore();
    });

    expect(mockDeleteAttempt).toHaveBeenCalledTimes(1);
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
  });

  it('adopts a concurrently restored account instead of logging it out on close', async () => {
    mockPeek.mockReturnValue({session: {token: 'restored-session'}});
    await dismiss();

    expect(mockDispatch).toHaveBeenCalledWith({
      type: 'login',
      payload: {token: 'restored-session'},
    });
    expect(mockDispatch).not.toHaveBeenCalledWith({type: 'logout'});
    expect(mockDeleteAttempt).not.toHaveBeenCalled();
    expect(mockResetState).toHaveBeenCalledWith(mockReturnTo, 'authenticated');
  });
});
