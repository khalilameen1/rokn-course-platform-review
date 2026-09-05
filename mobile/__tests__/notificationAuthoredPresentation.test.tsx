import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Text} from 'react-native';
import {cleanUnicodeText} from '../src/utils/unicodeText';

const mockInbox = jest.fn();
jest.mock('react-native-linear-gradient', () => 'LinearGradient');
jest.mock('@react-navigation/native', () => ({useNavigation: () => ({})}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/components/containers/Containers', () => ({
  Container: ({children}: {children: React.ReactNode}) => children,
}));
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/assets/SVG', () => ({NotificationIcon: () => null}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('../src/screens/notifications/useNotificationsInbox', () => ({
  useNotificationsInbox: () => mockInbox(),
}));

import Notifications from '../src/screens/Notifications';
import {HomeOverlays} from '../src/screens/home/HomeOverlays';

const authored = 'ريلز 2026: دورة Grease Pencil';
const body = 'const label = "ريلز"; const limit = 3;';
const texts = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root
    .findAllByType(Text)
    .map(node => cleanUnicodeText(node.props.children));

describe('authored notification rendering', () => {
  it('keeps the inbox title body and authored action while opening the original item', () => {
    const item = {
      id: '9',
      title: authored,
      description: body,
      actionLabel: 'افتح ريلز 2026',
      time: 'منذ 3 دقائق',
      read: false,
      tone: 'learning',
      link: '/course/12',
    };
    const openNotification = jest.fn();
    mockInbox.mockReturnValue({
      source: [item],
      failedImages: {},
      serverSession: true,
      openNotification,
      loading: false,
    });
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      act(() => {
        renderer = TestRenderer.create(<Notifications />);
      });
      expect(texts(renderer)).toContain(authored);
      expect(texts(renderer)).toContain(body);
      expect(texts(renderer)).toContain(item.actionLabel);
      expect(texts(renderer)).toContain('منذ ٣ دقائق');
      act(() =>
        renderer.root
          .findByProps({accessibilityHint: 'يفتح الإشعار'})
          .props.onPress(),
      );
      expect(openNotification).toHaveBeenCalledWith(item, false);
      expect(item.link).toBe('/course/12');
    } finally {
      act(() => renderer?.unmount());
    }
  });

  it('does not relocalize the authored campaign when the home overlay renders it', () => {
    const onDismissCampaign = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      act(() => {
        renderer = TestRenderer.create(
          <HomeOverlays
            campaign={{
              id: '9',
              title: authored,
              description: body,
              actionLabel: 'افتح الكورس',
            }}
            campaignImageFailed={false}
            onCampaignImageError={jest.fn()}
            onDismissCampaign={onDismissCampaign}
            onDismissWelcome={jest.fn()}
            onOpenWelcome={jest.fn()}
            guestPrompt={null}
            onDismissGuestPrompt={jest.fn()}
            onOpenGuestPrompt={jest.fn()}
            welcomeMessage={null}
            rewardPrompt={null}
            onDismissRewardPrompt={jest.fn()}
            onOpenRewardPrompt={jest.fn()}
          />,
        );
      });
      expect(texts(renderer)).toContain(authored);
      expect(texts(renderer)).toContain(body);
    } finally {
      act(() => renderer?.unmount());
    }
  });
});
