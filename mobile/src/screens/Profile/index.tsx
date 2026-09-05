import {useNavigation, useRoute} from '@react-navigation/native';
import type {RootNavigation, RootRoute} from '../../navigation/types';
import React, {useEffect, useState} from 'react';
import {Image, Modal, Pressable, Text, View} from 'react-native';
import {SettingsIcon, ShareProfileIcon} from '../../assets/SVG';
import TabBar from '../../components/TabBar';
import {Container, Content} from '../../components/containers/Containers';
import {
  MetaPill,
  PremiumCard,
  ResponsiveFrame,
} from '../../components/ui/PremiumUI';
import HeaderWithBack from '../../components/view/HeaderWithBack';
import Certificates from './Certificates';
import Gallery from './Gallery';
import SavedVideos from './SavedVideos';
import {isolateBidirectionalText} from '../../constants/arabicFormatting';
import QRCode from '../../components/ui/QRCode';
import {DefaultAvatar} from '../../components/ui/DefaultAvatar';
import {profileStyles as styles} from './styles';
import {useProfileOverview} from './useProfileOverview';
import {openGuestLogin} from '../../navigation/journeyNavigation';

type ProfileTab = 'portfolio' | 'certificates' | 'saved';

const tabs: {key: ProfileTab; label: string}[] = [
  {key: 'portfolio', label: 'أعمالي'},
  {key: 'certificates', label: 'الشهادات'},
  {key: 'saved', label: 'المحفوظات'},
];

export default function Profile() {
  const navigation = useNavigation<RootNavigation>();
  const route = useRoute<RootRoute<'Profile'>>();
  const [activeTab, setActiveTab] = useState<ProfileTab>('portfolio');
  const [failedAvatarUri, setFailedAvatarUri] = useState<string>();
  const [showPortfolioQr, setShowPortfolioQr] = useState(false);
  const {
    authenticatedIdentity,
    avatarUri,
    canSharePortfolio,
    certificateHolderName,
    displayName,
    identityKey,
    openPortfolio,
    portfolioLinkLabel,
    profileError,
    publicPortfolioUrl,
    retry,
    role,
    setHasShareablePortfolio,
    sharePortfolio,
  } = useProfileOverview();
  const showPortfolioActions =
    activeTab === 'portfolio' && canSharePortfolio;

  useEffect(() => {
    if (!showPortfolioActions) setShowPortfolioQr(false);
  }, [showPortfolioActions]);

  useEffect(() => {
    if (route.params?.tab) setActiveTab(route.params.tab);
  }, [route.params?.tab]);

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame>
          <HeaderWithBack
            hasArrow={false}
            rightContent={() => (
              <Pressable
                accessibilityLabel="الإعدادات"
                accessibilityRole="button"
                onPress={() => navigation.navigate('Settings')}
                style={styles.headerButton}>
                <SettingsIcon />
              </Pressable>
            )}
            title="حسابي"
          />

          {!!profileError && authenticatedIdentity && (
            <Pressable
              accessibilityLiveRegion="polite"
              accessibilityRole="button"
              onPress={() => retry()}
              style={styles.staleNotice}>
              <Text style={styles.staleNoticeText}>{profileError}</Text>
              <Text style={styles.staleNoticeAction}>إعادة المحاولة</Text>
            </Pressable>
          )}

          <PremiumCard style={styles.profileCard}>
            <View style={styles.profileTop}>
              <Pressable
                accessibilityHint="يفتح بيانات الحساب"
                accessibilityLabel={
                  authenticatedIdentity
                    ? `تغيير صورة ${displayName}`
                    : 'تسجيل الدخول لتغيير صورة الحساب'
                }
                accessibilityRole="button"
                onPress={() =>
                  authenticatedIdentity
                    ? navigation.navigate('EditAccount')
                    : openGuestLogin(navigation, {name: 'EditAccount'})
                }
                style={({pressed}) => [
                  styles.avatarButton,
                  pressed && styles.pressed,
                ]}>
                <View
                  accessibilityElementsHidden
                  importantForAccessibility="no-hide-descendants">
                  {avatarUri && avatarUri !== failedAvatarUri ? (
                    <Image
                      onError={() => setFailedAvatarUri(avatarUri)}
                      progressiveRenderingEnabled
                      resizeMethod="resize"
                      source={{uri: avatarUri}}
                      style={styles.avatar}
                    />
                  ) : (
                    <DefaultAvatar
                      accessibilityLabel={`صورة ${displayName}`}
                      size={72}
                    />
                  )}
                </View>
              </Pressable>
              <View style={styles.profileCopy}>
                <Text style={styles.name}>{displayName}</Text>
                {!!role && <Text style={styles.role}>{role}</Text>}
                {!authenticatedIdentity && (
                  <MetaPill
                    label="تصفّح كضيف"
                    tone="neutral"
                    style={styles.availability}
                  />
                )}
              </View>
              {showPortfolioActions && (
                <Pressable
                  accessibilityLabel="مشاركة البورتفوليو"
                  accessibilityRole="button"
                  onPress={() => void sharePortfolio()}
                  style={({pressed}) => [
                    styles.shareButton,
                    pressed && styles.pressed,
                  ]}>
                  <ShareProfileIcon />
                </Pressable>
              )}
            </View>
            {showPortfolioActions && (
              <View style={styles.publicActions}>
                <Pressable
                  accessibilityLabel="فتح رابط مشاركة البورتفوليو"
                  accessibilityRole="link"
                  onPress={() => void openPortfolio()}
                  style={({pressed}) => [
                    styles.publicLink,
                    pressed && styles.pressed,
                  ]}>
                  <Text numberOfLines={1} style={styles.publicLinkText}>
                    {isolateBidirectionalText(portfolioLinkLabel)}
                  </Text>
                </Pressable>
                <Pressable
                  accessibilityLabel="عرض رمز QR للبورتفوليو"
                  accessibilityRole="button"
                  onPress={() => setShowPortfolioQr(true)}
                  style={({pressed}) => [
                    styles.qrButton,
                    pressed && styles.pressed,
                  ]}>
                  <Text style={styles.qrButtonText}>QR</Text>
                </Pressable>
              </View>
            )}
          </PremiumCard>

          <Modal
            animationType="fade"
            onRequestClose={() => setShowPortfolioQr(false)}
            statusBarTranslucent
            transparent
            visible={showPortfolioQr && showPortfolioActions}>
            <View style={styles.qrOverlay}>
              <View accessibilityViewIsModal>
                <PremiumCard style={styles.qrCard}>
                  <Text accessibilityRole="header" style={styles.qrTitle}>
                    بورتفوليو {displayName}
                  </Text>
                  <QRCode
                    accessibilityLabel="رمز QR لفتح البورتفوليو"
                    size={184}
                    value={publicPortfolioUrl}
                  />
                  <Text style={styles.qrHint}>امسح الرمز لفتح البورتفوليو</Text>
                  <Pressable
                    accessibilityRole="button"
                    onPress={() => setShowPortfolioQr(false)}
                    style={({pressed}) => [
                      styles.qrClose,
                      pressed && styles.pressed,
                    ]}>
                    <Text style={styles.qrCloseText}>إغلاق</Text>
                  </Pressable>
                </PremiumCard>
              </View>
            </View>
          </Modal>

          <View accessibilityRole="tablist" style={styles.tabs}>
            {tabs.map(tab => {
              const selected = activeTab === tab.key;
              return (
                <Pressable
                  accessibilityRole="tab"
                  accessibilityState={{selected}}
                  key={tab.key}
                  onPress={() => setActiveTab(tab.key)}
                  style={({pressed}) => [
                    styles.tab,
                    selected && styles.activeTab,
                    pressed && styles.pressed,
                  ]}>
                  <Text
                    style={[
                      styles.tabLabel,
                      selected && styles.activeTabLabel,
                    ]}>
                    {tab.label}
                  </Text>
                </Pressable>
              );
            })}
          </View>

          {activeTab === 'portfolio' && (
            <Gallery
              key={`portfolio:${identityKey}`}
              onSharePortfolio={canSharePortfolio ? sharePortfolio : undefined}
              onShareablePortfolioChange={setHasShareablePortfolio}
            />
          )}
          {activeTab === 'certificates' && (
            <Certificates
              key={`certificates:${identityKey}`}
              displayName={certificateHolderName}
            />
          )}
          {activeTab === 'saved' && (
            <SavedVideos key={`saved:${identityKey}`} />
          )}
        </ResponsiveFrame>
      </Content>
      <TabBar />
    </Container>
  );
}
