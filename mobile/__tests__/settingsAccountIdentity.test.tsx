import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {useSettingsController} from '../src/screens/settings/useSettingsController';

let mockSession: unknown = null;
jest.mock('react-redux', () => ({
  useDispatch: () => jest.fn(),
  useSelector: () => mockSession,
}));
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: jest.fn()}),
}));
jest.mock('../src/screens/settings/useAccountSettingsActions', () => ({
  useAccountSettingsActions: () => ({}),
}));
jest.mock('../src/screens/settings/useSettingsPreferences', () => ({
  useSettingsPreferences: () => ({}),
}));

describe('settings account identity', () => {
  const readIdentity = (session: unknown) => {
    mockSession = session;
    let identity: ReturnType<typeof useSettingsController>['accountIdentity'];
    const Probe = () => {
      identity = useSettingsController().accountIdentity;
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    act(() => {
      renderer = TestRenderer.create(<Probe />);
    });
    act(() => renderer.unmount());
    return identity!;
  };

  it('uses the server account ID independently of the name or social provider ID', () => {
    expect(
      readIdentity({
        api_token: 'session',
        user: {id: 526784991, name: '  خالد  ', social_id: 'provider-123'},
      }),
    ).toEqual({id: '526784991', name: 'خالد'});
    expect(
      readIdentity({
        api_token: 'session',
        user: {id: 526784991, name: 'اسم جديد'},
      })?.id,
    ).toBe('526784991');
  });

  it('does not expose a stale account identity to a guest', () => {
    expect(
      readIdentity({user: {id: 123, name: 'Previous account'}}),
    ).toBeNull();
    expect(readIdentity(null)).toBeNull();
  });

  it('never invents an ID from a provider identity, email or rounded number', () => {
    for (const user of [
      {social_id: '123', email: 'test@example.com'},
      {id: 0},
      {id: -2},
      {id: '12abc'},
      {id: 9007199254740992},
    ]) {
      expect(readIdentity({api_token: 'session', user})).toBeNull();
    }
  });

  it('supports the existing numeric-string and user_id session contracts', () => {
    expect(readIdentity({api_token: 'session', user: {id: '123'}})).toEqual({
      id: '123',
      name: 'حسابي',
    });
    expect(readIdentity({api_token: 'session', user: {user_id: 124}})?.id).toBe(
      '124',
    );
  });
});
