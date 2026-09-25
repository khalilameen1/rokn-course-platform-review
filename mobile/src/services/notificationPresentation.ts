import {Platform} from 'react-native';
import * as Notifications from 'expo-notifications';
import {BrandColors} from '../constants/brandTokens';

const PUSH_CHANNELS = {
  updates: 'rokn-updates',
  learning: 'rokn-learning',
  offers: 'rokn-offers',
} as const;

export const configureNotificationPresentation = () => {
  Notifications.setNotificationHandler({
    handleNotification: async () => ({
      shouldPlaySound: false,
      shouldSetBadge: false,
      shouldShowBanner: true,
      shouldShowList: true,
    }),
  });
};

export const prepareNotificationChannels = async () => {
  if (Platform.OS !== 'android') return;
  await Promise.all([
    Notifications.setNotificationChannelAsync(PUSH_CHANNELS.updates, {
      name: 'تحديثات الحساب',
      description: 'نتائج المشاريع والشهادات وحركة الرصيد',
      importance: Notifications.AndroidImportance.DEFAULT,
      vibrationPattern: [0, 160],
      lightColor: BrandColors.primary,
    }),
    Notifications.setNotificationChannelAsync(PUSH_CHANNELS.learning, {
      name: 'تذكيرات التعلّم',
      description: 'تذكيرات هادئة مرتبطة بمكانك داخل الكورس',
      importance: Notifications.AndroidImportance.DEFAULT,
      vibrationPattern: [0, 160],
      lightColor: BrandColors.primary,
    }),
    Notifications.setNotificationChannelAsync(PUSH_CHANNELS.offers, {
      name: 'عروض ركن',
      description: 'الكورسات الجديدة وعروض الرصيد التي اخترت استقبالها',
      importance: Notifications.AndroidImportance.DEFAULT,
      vibrationPattern: [0, 160],
      lightColor: BrandColors.primary,
    }),
  ]);
};
