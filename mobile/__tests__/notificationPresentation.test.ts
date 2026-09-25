jest.mock('react-native', () => ({Platform: {OS: 'android'}}));
jest.mock('expo-notifications', () => ({
  AndroidImportance: {DEFAULT: 3},
  setNotificationHandler: jest.fn(),
  setNotificationChannelAsync: jest.fn(async () => null),
}));

// Presentation is safe before an account or navigation tree exists.
jest.mock('../src/constants/helpers', () => {
  throw new Error('Presentation must not load account storage');
});
jest.mock('../src/services/pushDeviceRegistration', () => {
  throw new Error('Presentation must not register a device');
});
jest.mock('../src/services/pushNotificationNavigation', () => {
  throw new Error('Presentation must not initialize notification routing');
});

import {Platform} from 'react-native';
import * as Notifications from 'expo-notifications';
import {
  configureNotificationPresentation,
  prepareNotificationChannels,
} from '../src/services/notificationPresentation';

describe('native notification presentation', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    (Platform as {OS: string}).OS = 'android';
  });

  it('configures foreground delivery only through the explicit runtime entry point', async () => {
    jest.isolateModules(() => {
      require('../src/services/notificationPresentation');
      const native = require('expo-notifications');
      expect(native.setNotificationHandler).not.toHaveBeenCalled();
    });
    configureNotificationPresentation();
    const handler = (Notifications.setNotificationHandler as jest.Mock).mock
      .calls[0][0];
    await expect(handler.handleNotification()).resolves.toEqual({
      shouldPlaySound: false,
      shouldSetBadge: false,
      shouldShowBanner: true,
      shouldShowList: true,
    });
    expect(Notifications.setNotificationChannelAsync).not.toHaveBeenCalled();
  });

  it('preserves Android channel identities and labels without reading a session', async () => {
    await prepareNotificationChannels();
    expect(
      (Notifications.setNotificationChannelAsync as jest.Mock).mock.calls,
    ).toEqual([
      [
        'rokn-updates',
        expect.objectContaining({name: 'تحديثات الحساب', importance: 3}),
      ],
      [
        'rokn-learning',
        expect.objectContaining({name: 'تذكيرات التعلّم', importance: 3}),
      ],
      [
        'rokn-offers',
        expect.objectContaining({name: 'عروض ركن', importance: 3}),
      ],
    ]);
    expect(Notifications.setNotificationHandler).not.toHaveBeenCalled();
  });

  it('does not create Android channels on iOS', async () => {
    (Platform as {OS: string}).OS = 'ios';
    await prepareNotificationChannels();
    expect(Notifications.setNotificationChannelAsync).not.toHaveBeenCalled();
  });
});
