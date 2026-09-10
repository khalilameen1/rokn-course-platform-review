import React from 'react';
import {StyleSheet} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {Container, Content} from '../components/containers/Containers';
import {SettingsChoiceModal} from '../components/settings/SettingsChoiceModal';
import {SettingsAccountIdentity} from '../components/settings/SettingsAccountIdentity';
import NotificationPermissionPrimer from '../components/ui/NotificationPermissionPrimer';
import {ResponsiveFrame} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {Spacing} from '../constants/designSystem';
import {SettingsSections} from './settings/SettingsSections';
import {useSettingsController} from './settings/useSettingsController';

export default function Settings() {
  const insets = useSafeAreaInsets();
  const controller = useSettingsController();

  return (
    <Container noPadding>
      <Content
        noPadding
        paddingBottom={Math.max(Spacing.section, insets.bottom + Spacing.xl)}>
        <ResponsiveFrame style={styles.frame}>
          <HeaderWithBack title="الإعدادات" />
          {controller.accountIdentity && (
            <SettingsAccountIdentity
              key={controller.accountIdentity.id}
              {...controller.accountIdentity}
            />
          )}
          <SettingsSections {...controller.sectionsProps} />
        </ResponsiveFrame>
      </Content>

      <SettingsChoiceModal
        bottomInset={insets.bottom}
        choice={controller.choiceModal}
        onClose={controller.closeChoiceModal}
        onSelect={controller.selectChoice}
        quality={controller.quality}
        reminderHour={controller.reminderHour}
      />
      <NotificationPermissionPrimer
        onClose={controller.closeNotificationPrimer}
        onEnable={controller.confirmNotifications}
        visible={controller.notificationPrimer}
      />
    </Container>
  );
}

const styles = StyleSheet.create({
  frame: {maxWidth: 760},
});
