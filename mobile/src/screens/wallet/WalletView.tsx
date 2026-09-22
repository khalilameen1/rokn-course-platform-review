import React from 'react';
import {useNavigation} from '@react-navigation/native';
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import type {RootNavigation} from '../../navigation/types';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import TabBar from '../../components/TabBar';
import {Container, Content} from '../../components/containers/Containers';
import {ResponsiveFrame, StatusView} from '../../components/ui/PremiumUI';
import HeaderWithBack from '../../components/view/HeaderWithBack';
import {AccordionArrowDown, SettingsHistoryIcon} from '../../assets/SVG';
import {
  Palette,
  Spacing,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {formatArabicNumber} from '../../constants/arabicFormatting';
import {RoknCoinStack} from '../../components/ui/RoknCoin';
import type {WalletController} from './useWalletController';
import {walletStyles as styles} from './walletStyles';
import {RewardsTaskList} from './RewardsTaskList';
import {RewardsDetailsSheet} from './RewardsDetailsSheet';

export const WalletView = ({controller}: {controller: WalletController}) => {
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const {width, fontScale} = useResponsiveLayout();
  const stacked = width < 360 || fontScale >= 1.3;
  const {
    ownerReady,
    serverSession,
    displayedBalance,
    displayedRewardBalance,
    manualRefreshing,
    refreshWalletManually,
    refreshWallet,
    walletStatus,
    setWalletModal,
  } = controller;
  const header = <HeaderWithBack hasArrow={false} title="مكافآتي" />;
  if (!ownerReady || serverSession === null) {
    return (
      <Container noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            {header}
            {walletStatus === 'error' ? (
              <StatusView
                state="error"
                title="تعذّر تحميل مكافآتك"
                description="تحقق من الاتصال ثم حاول مرة أخرى"
                actionLabel="إعادة المحاولة"
                onAction={() => void refreshWallet()}
              />
            ) : (
              <>
                <ActivityIndicator color={Palette.primary} />
                <Text style={styles.remoteNote}>جارٍ تحميل مكافآتك</Text>
              </>
            )}
          </ResponsiveFrame>
        </Content>
        <TabBar />
      </Container>
    );
  }
  if (serverSession === false) {
    return (
      <Container noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            {header}
            <StatusView
              actionLabel="تسجيل الدخول"
              description="سجّل الدخول لعرض رصيدك والمهام المتاحة"
              onAction={() => openGuestLogin(navigation, {name: 'Wallet'})}
              state="empty"
              title="مكافآتك في حسابك"
            />
          </ResponsiveFrame>
        </Content>
        <TabBar />
      </Container>
    );
  }
  return (
    <Container noPadding>
      <Content
        noPadding
        refreshControl={
          <RefreshControl
            onRefresh={() => void refreshWalletManually()}
            refreshing={manualRefreshing}
            tintColor={Palette.primary}
          />
        }
        paddingBottom={Math.max(Spacing.xl, insets.bottom + Spacing.md)}>
        <ResponsiveFrame>
          <View style={styles.rewardsHeader}>
            <Text accessibilityRole="header" style={styles.rewardsTitle}>
              مكافآتي
            </Text>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="سجل المكافآت"
              onPress={() => setWalletModal('transactions')}
              style={styles.historyButton}>
              <SettingsHistoryIcon
                width={23}
                height={23}
                stroke={Palette.textMuted}
              />
            </Pressable>
          </View>
          <View
            style={[styles.rewardsHero, stacked && styles.rewardsHeroStacked]}>
            <View style={styles.rewardsBalanceCopy}>
              <Text style={styles.balanceCaption}>رصيدك</Text>
              <Text
                accessibilityLabel="رصيد المكافآت"
                accessibilityLiveRegion="polite"
                style={styles.rewardsBalance}>
                {displayedBalance === null
                  ? '—'
                  : formatArabicNumber(displayedRewardBalance)}
              </Text>
              <Pressable
                accessibilityRole="button"
                onPress={() => setWalletModal('rules')}
                style={({pressed}) => [
                  styles.disclosure,
                  pressed && styles.pressed,
                ]}>
                <Text style={styles.rulesLinkLabel}>كيف يعمل الرصيد</Text>
                <AccordionArrowDown width={14} height={14} />
              </Pressable>
            </View>
            <RoknCoinStack
              size={stacked ? 132 : 162}
              style={styles.rewardsArt}
            />
          </View>
          {displayedBalance === null && walletStatus === 'loading' && (
            <Text style={styles.balanceHint}>جارٍ تحديث الرصيد</Text>
          )}
          {walletStatus === 'error' && (
            <Pressable
              accessibilityRole="button"
              onPress={() => void refreshWallet()}
              style={styles.inlineRetry}>
              <Text style={styles.apiError}>تعذّر تحديث الرصيد</Text>
              <Text style={styles.retryLabel}>إعادة المحاولة</Text>
            </Pressable>
          )}
          <RewardsTaskList controller={controller} stacked={stacked} />
        </ResponsiveFrame>
      </Content>
      <TabBar />
      <RewardsDetailsSheet controller={controller} stacked={stacked} />
    </Container>
  );
};
export default WalletView;
