import {useNavigation, useRoute} from '@react-navigation/native';
import type {
  ProfileTab,
  RootNavigation,
  RootRoute,
} from '../../navigation/types';
import React, {useCallback, useEffect, useRef, useState} from 'react';
import {Modal, Pressable, ScrollView, Text, View} from 'react-native';
import {RasterImage as Image} from '../../components/ui/RasterImage';
import {SettingsIcon, ShareProfileIcon} from '../../assets/SVG';
import {
  Container,
  Content,
  TAB_SCREEN_EDGES,
} from '../../components/containers/Containers';
import {
  MetaPill,
  PremiumCard,
  ResponsiveFrame,
} from '../../components/ui/PremiumUI';
import HeaderWithBack from '../../components/view/HeaderWithBack';
import Certificates from './Certificates';
import Gallery from './Gallery';
import QRCode from '../../components/ui/QRCode';
import {QRIcon} from '../../components/ui/QRIcon';
import {DefaultAvatar} from '../../components/ui/DefaultAvatar';
import {profileStyles as styles} from './styles';
import {useProfileOverview} from './useProfileOverview';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import {openRootTab} from '../../navigation/rootRoutes';

const tabs: {key: ProfileTab; label: string}[] = [
  {key: 'portfolio', label: 'أعمالي'},
  {key: 'certificates', label: 'الشهادات'},
];

export default function Profile() {
  const navigation = useNavigation<RootNavigation>();
  const route = useRoute<RootRoute<'Profile'>>();
  const [activeTab, setActiveTab] = useState<ProfileTab>('portfolio');
  const [failedAvatarUri, setFailedAvatarUri] = useState<string>();
  const [showPortfolioQr, setShowPortfolioQr] = useState(false);
  const {
    applyPortfolioProfile,
    authenticatedIdentity,
    avatarUri,
    canSharePortfolio,
    certificateHolderName,
    displayName,
    identityKey,
    profileError,
    portfolioSharingNotice,
    publicPortfolioUrl,
    refreshPortfolioShareUrl,
    retry,
    role,
    setHasShareablePortfolio,
    sharePortfolio,
  } = useProfileOverview();
  const showPortfolioActions = activeTab === 'portfolio' && canSharePortfolio;
  const qrContextRef = useRef({activeTab, identityKey, mounted: true});
  qrContextRef.current = {...qrContextRef.current, activeTab, identityKey};

  useEffect(() => {
    qrContextRef.current.mounted = true;
    return () => {
      qrContextRef.current.mounted = false;
    };
  }, []);

  useEffect(() => {
    if (!showPortfolioActions) setShowPortfolioQr(false);
  }, [showPortfolioActions]);

  useEffect(() => {
    const requestedTab = route.params?.tab;
    // A stale route from before المحفوظات moved to ركني may still carry
    // another tab; only the two أنا tabs are selectable. The أنا tab stays
    // mounted, so each navigation request (new params) applies its tab again.
    if (requestedTab === 'portfolio' || requestedTab === 'certificates') {
      setActiveTab(requestedTab);
    }
  }, [route.params]);

  // One-shot request from a passed project; consumed by the gallery.
  const clearAddPortfolioProject = useCallback(
    () => navigation.setParams({addPortfolioProjectId: undefined}),
    [navigation],
  );

  return (
    <Container edges={TAB_SCREEN_EDGES} noPadding>
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

          <View style={styles.profileCard}>
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
            </View>
            {showPortfolioActions && (
              <View style={styles.publicActions}>
                <View style={styles.publicActionButtons}>
                  <Pressable
                    accessibilityLabel="مشاركة البورتفوليو"
                    accessibilityRole="button"
                    onPress={() => void sharePortfolio()}
                    style={({pressed}) => [
                      styles.shareButton,
                      pressed && styles.pressed,
                    ]}>
                    <ShareProfileIcon />
                    <Text style={styles.shareLabel}>مشاركة</Text>
                  </Pressable>
                  <Pressable
                    accessibilityLabel="عرض رمز QR للبورتفوليو"
                    accessibilityRole="button"
                    onPress={async () => {
                      const url = await refreshPortfolioShareUrl();
                      const current = qrContextRef.current;
                      if (
                        url &&
                        current.mounted &&
                        current.activeTab === 'portfolio' &&
                        current.identityKey === identityKey
                      ) {
                        setShowPortfolioQr(true);
                      }
                    }}
                    style={({pressed}) => [
                      styles.qrButton,
                      pressed && styles.pressed,
                    ]}>
                    <QRIcon />
                  </Pressable>
                </View>
              </View>
            )}
          </View>

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

          <ScrollView
            accessibilityRole="tablist"
            contentContainerStyle={styles.tabs}
            horizontal
            showsHorizontalScrollIndicator={false}
            style={styles.tabBar}>
            {tabs.map(tab => {
              const selected = activeTab === tab.key;
              return (
                <Pressable
                  accessibilityLabel={tab.label}
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
                    numberOfLines={1}
                    style={[
                      styles.tabLabel,
                      selected && styles.activeTabLabel,
                    ]}>
                    {tab.label}
                  </Text>
                </Pressable>
              );
            })}
          </ScrollView>

          {activeTab === 'portfolio' && (
            <>
              {!!portfolioSharingNotice && (
                <View
                  accessibilityLiveRegion="polite"
                  style={styles.staleNotice}>
                  <Text style={styles.staleNoticeText}>
                    {portfolioSharingNotice}
                  </Text>
                </View>
              )}
              <Gallery
                key={`portfolio:${identityKey}`}
                identityKey={identityKey}
                onSubscriptions={hasSubscription =>
                  openRootTab(navigation, hasSubscription ? 'MyCorner' : 'Home')
                }
                onSharePortfolio={
                  canSharePortfolio ? sharePortfolio : undefined
                }
                onShareablePortfolioChange={setHasShareablePortfolio}
                portfolioPublicUrl={publicPortfolioUrl}
                onPortfolioProfileChange={applyPortfolioProfile}
                addFromProjectId={route.params?.addPortfolioProjectId}
                onAddFromProjectHandled={clearAddPortfolioProject}
              />
            </>
          )}
          {activeTab === 'certificates' && (
            <Certificates
              key={`certificates:${identityKey}`}
              displayName={certificateHolderName}
            />
          )}
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}
