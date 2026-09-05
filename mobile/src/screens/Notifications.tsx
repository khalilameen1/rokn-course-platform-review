import {useNavigation} from '@react-navigation/native';
import React, {useCallback, useMemo} from 'react';
import {
  FlatList,
  Image,
  type ListRenderItemInfo,
  Platform,
  Pressable,
  Text,
  View,
} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import {NotificationIcon} from '../assets/SVG';
import {Container} from '../components/containers/Containers';
import {SectionHeading, StatusView} from '../components/ui/PremiumUI';
import {RoknCoinStack} from '../components/ui/RoknCoin';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {
  formatArabicDisplayText,
  formatAuthoredDisplayText,
} from '../constants/arabicFormatting';
import {useResponsiveLayout} from '../constants/designSystem';
import {openGuestLogin} from '../navigation/journeyNavigation';
import type {RootNavigation} from '../navigation/types';
import {
  notificationImageKey,
  type NotificationItem,
} from './notifications/model';
import {notificationStyles as styles} from './notifications/styles';
import {useNotificationsInbox} from './notifications/useNotificationsInbox';

export default function Notifications() {
  const navigation = useNavigation<RootNavigation>();
  const {width, fontScale, contentWidth, gutter} = useResponsiveLayout();
  const compactLayout = width < 380 || fontScale > 1.2;
  const {
    failedImages,
    hasMoreNotifications,
    hasUnread,
    loadMoreNotifications,
    loading,
    loadingMore,
    markAllRead,
    markImageFailed,
    notificationError,
    openNotification,
    refreshNotifications,
    screenReaderEnabled,
    serverSession,
    source,
  } = useNotificationsInbox();

  const renderNotification = useCallback(
    ({item}: ListRenderItemInfo<NotificationItem>) => {
      const read = item.read;
      const actionable = Boolean(item.link) || !read;
      const imageKey = notificationImageKey(item.image);
      const imageFailed = failedImages[item.id] === imageKey;
      const gradient =
        item.tone === 'coins'
          ? ['rgba(216,166,60,0.18)', 'rgba(17,22,32,0.98)']
          : item.tone === 'project'
          ? ['rgba(72,185,138,0.15)', 'rgba(17,22,32,0.98)']
          : ['rgba(44,105,219,0.17)', 'rgba(17,22,32,0.98)'];
      return (
        <View
          style={[
            styles.itemFrame,
            {maxWidth: contentWidth, paddingHorizontal: gutter},
          ]}>
          <Pressable
            accessibilityHint={item.link ? 'يفتح الإشعار' : undefined}
            accessibilityLabel={[
              item.title,
              item.description,
              item.time,
              !read ? 'غير مقروء' : '',
            ]
              .filter(Boolean)
              .join('\n')}
            accessibilityRole={actionable ? 'button' : undefined}
            disabled={!actionable}
            onPress={() => openNotification(item, read)}
            style={({pressed}) => [
              styles.cardPressable,
              pressed && styles.pressed,
            ]}>
            <LinearGradient
              colors={gradient}
              end={{x: 0, y: 1}}
              start={{x: 1, y: 0}}
              style={[
                styles.row,
                compactLayout && styles.rowCompact,
                !read && styles.unreadRow,
              ]}>
              <View
                style={[
                  styles.mark,
                  item.tone === 'coins' && styles.coinMark,
                  item.tone === 'project' && styles.projectMark,
                  compactLayout && styles.markCompact,
                  Boolean(item.image) && !imageFailed && styles.courseMark,
                ]}>
                {item.tone === 'coins' ? (
                  <RoknCoinStack size={compactLayout ? 34 : 42} />
                ) : item.image && !imageFailed ? (
                  <Image
                    accessibilityElementsHidden
                    fadeDuration={120}
                    importantForAccessibility="no"
                    onError={() => markImageFailed(item.id, imageKey)}
                    progressiveRenderingEnabled
                    resizeMethod="resize"
                    source={item.image}
                    style={styles.courseImage}
                  />
                ) : (
                  <NotificationIcon width={23} height={23} />
                )}
              </View>
              <View style={styles.copy}>
                <View style={styles.titleRow}>
                  <Text numberOfLines={2} style={styles.title}>
                    {formatAuthoredDisplayText(item.title)}
                  </Text>
                  {!read && (
                    <View
                      accessibilityLabel="غير مقروء"
                      style={styles.unreadDot}
                    />
                  )}
                </View>
                <Text
                  numberOfLines={compactLayout ? 4 : 3}
                  style={styles.description}>
                  {formatAuthoredDisplayText(item.description)}
                </Text>
                <View
                  style={[
                    styles.metaRow,
                    compactLayout && styles.metaRowCompact,
                  ]}>
                  {!!item.link && !!item.actionLabel && (
                    <View style={styles.actionPill}>
                      <Text style={styles.actionLabel}>
                        {formatAuthoredDisplayText(item.actionLabel)}
                      </Text>
                    </View>
                  )}
                  {!!item.time && (
                    <Text style={styles.time}>
                      {formatArabicDisplayText(item.time)}
                    </Text>
                  )}
                </View>
              </View>
            </LinearGradient>
          </Pressable>
        </View>
      );
    },
    [
      compactLayout,
      contentWidth,
      failedImages,
      gutter,
      markImageFailed,
      openNotification,
    ],
  );

  const frameStyle = useMemo(
    () => ({maxWidth: contentWidth, paddingHorizontal: gutter}),
    [contentWidth, gutter],
  );
  const showLoading = loading && !source.length;
  const showError =
    !showLoading &&
    notificationError &&
    serverSession !== false &&
    !source.length;
  const guestNeedsAccount = serverSession === false;

  return (
    <Container noPadding>
      <FlatList
        accessibilityRole="list"
        contentContainerStyle={styles.listContent}
        data={source}
        initialNumToRender={8}
        keyExtractor={item => item.id}
        ListHeaderComponent={
          <View style={[styles.headerFrame, frameStyle]}>
            <HeaderWithBack title="الإشعارات" />
            <SectionHeading
              actionLabel={hasUnread ? 'تحديد الكل كمقروء' : undefined}
              onAction={markAllRead}
              title="آخر التحديثات"
            />
            {showLoading ? (
              <StatusView
                state="loading"
                description="جارٍ التحديث"
                title="الإشعارات"
              />
            ) : showError ? (
              <StatusView
                actionLabel="إعادة المحاولة"
                description={notificationError}
                onAction={refreshNotifications}
                state="error"
                title="تعذّر تحديث الإشعارات"
              />
            ) : guestNeedsAccount ? (
              <StatusView
                actionLabel="تسجيل الدخول"
                description="سجّل الدخول لعرض تحديثات كورساتك ومكافآتك"
                onAction={() =>
                  openGuestLogin(navigation, {name: 'Notifications'})
                }
                state="empty"
                title="إشعاراتك مرتبطة بحسابك"
              />
            ) : !source.length ? (
              <StatusView
                description="ستظهر إشعاراتك هنا"
                title="لا توجد إشعارات"
              />
            ) : notificationError && serverSession === true ? (
              <Pressable
                accessibilityLiveRegion="polite"
                accessibilityRole="button"
                onPress={refreshNotifications}
                style={styles.staleNotice}>
                <Text style={styles.staleNoticeText}>{notificationError}</Text>
                <Text style={styles.staleNoticeAction}>إعادة المحاولة</Text>
              </Pressable>
            ) : null}
          </View>
        }
        ListFooterComponent={
          serverSession === true && hasMoreNotifications ? (
            <View style={[styles.footerFrame, frameStyle]}>
              <Pressable
                accessibilityRole="button"
                accessibilityState={{busy: loadingMore, disabled: loadingMore}}
                disabled={loadingMore}
                onPress={() => void loadMoreNotifications()}
                style={({pressed}) => [
                  styles.loadMore,
                  pressed && styles.pressed,
                  loadingMore && styles.loadMoreDisabled,
                ]}>
                <Text style={styles.loadMoreText}>
                  {loadingMore ? 'نحمّل الأقدم' : 'عرض إشعارات أقدم'}
                </Text>
              </Pressable>
            </View>
          ) : null
        }
        maxToRenderPerBatch={8}
        onRefresh={() => void refreshNotifications()}
        refreshing={loading && source.length > 0}
        removeClippedSubviews={
          Platform.OS === 'android' && !screenReaderEnabled
        }
        renderItem={renderNotification}
        showsVerticalScrollIndicator={false}
        updateCellsBatchingPeriod={40}
        windowSize={7}
      />
    </Container>
  );
}
