import {getStateFromPath} from '@react-navigation/native';
import {roknLinking} from '../src/navigation/roknLinking';

jest.mock('@react-navigation/native', () => ({getStateFromPath: jest.fn()}));
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  openRoknDestination: jest.fn(() => true),
}));
jest.mock('react-native', () => ({
  Linking: {addEventListener: jest.fn(() => ({remove: jest.fn()}))},
}));

describe('saved library app-link entry', () => {
  beforeEach(() => {
    (getStateFromPath as jest.Mock)
      .mockReset()
      .mockReturnValue({routes: [{name: 'SavedLibrary', path: 'saved'}]});
  });

  it.each(['profile/saved', '/saved'])(
    'opens the saved library above Home on a cold start from %s',
    path => {
      expect(roknLinking.getStateFromPath?.(path, roknLinking.config)).toEqual(
        {
          index: 1,
          routes: [{name: 'Home'}, {name: 'SavedLibrary', path: 'saved'}],
        },
      );
      expect(getStateFromPath).toHaveBeenCalledWith(
        'saved',
        roknLinking.config,
      );
    },
  );

  it('registers the screen path the old profile tab used to own', () => {
    const screens = roknLinking.config?.screens as Record<string, string>;
    expect(screens.SavedLibrary).toBe('saved');
    expect(screens.Profile).toBe('profile/:tab?');
  });
});
