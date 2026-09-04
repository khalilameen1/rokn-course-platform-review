jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: jest.fn(),
  setItem: jest.fn(),
  removeItem: jest.fn(),
  clear: jest.fn(),
}));

import {extractApiToken, extractUserProfile} from '../src/constants/helpers';
import {normalizeText} from '../src/utils/searchText';

describe('canonical secure session', () => {
  it('reads the top-level API token', () => {
    expect(extractApiToken({api_token: ' direct-token '})).toBe(
      'direct-token',
    );
  });

  it.each([
    {data: {api_token: 'nested-token'}},
    {data: {data: {api_token: 'deep-token'}}},
    {user: {api_token: 'user-token'}},
    {api_token: '  '},
  ])('rejects non-canonical or empty token shapes', input => {
    expect(extractApiToken(input)).toBeNull();
  });

  it('reads the top-level user profile', () => {
    const user = {id: 17, name: 'Rokn learner'};
    expect(extractUserProfile({user})).toEqual(user);
  });
});

describe('search normalization', () => {
  it('normalizes every Arabic digit occurrence for consistent matching', () => {
    expect(normalizeText('كورس ٢٠٢٦ خطوة ٣٠')).toBe('كورس 2026 خطوه 30');
  });
});
