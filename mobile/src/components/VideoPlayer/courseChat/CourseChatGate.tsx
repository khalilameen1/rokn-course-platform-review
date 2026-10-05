import React from 'react';
import {ActivityIndicator, Pressable, ScrollView, Text} from 'react-native';
import {subscriptionMessages} from '../../../constants/subscriptionMessages';
import {Palette} from '../../../constants/designSystem';
import type {CourseChatUpgradeStatus} from './useCourseChatUpgrade';
import {courseChatStyles as styles} from './styles';

export const CourseChatGate = ({
  accessUnavailable,
  courseAccessRequired,
  courseChatUnavailable,
  onOpenCourseAccess,
  onUpgrade,
  planLimitReached,
  upgradeStatus,
  onRetryUpgrade,
}: {
  accessUnavailable: boolean;
  courseAccessRequired: boolean;
  courseChatUnavailable: boolean;
  onOpenCourseAccess: () => void;
  onUpgrade: () => void;
  planLimitReached: boolean;
  upgradeStatus: CourseChatUpgradeStatus;
  onRetryUpgrade: () => void;
}) => {
  const message = courseAccessRequired
    ? subscriptionMessages.chatSubscribe
    : planLimitReached
    ? subscriptionMessages.chatExhausted
    : subscriptionMessages.chatUpgrade;
  const needsUpgrade =
    !accessUnavailable && !courseChatUnavailable && !courseAccessRequired;
  const checking = needsUpgrade && ['idle', 'loading'].includes(upgradeStatus);
  const unavailable = needsUpgrade && upgradeStatus === 'unavailable';
  const failed = needsUpgrade && upgradeStatus === 'error';
  const upgradeTitle = checking
    ? 'نتحقق من خيارات اشتراكك'
    : failed
    ? 'تعذّر التحقق من الترقية'
    : unavailable && !planLimitReached
    ? 'الشات غير متاح في اشتراكك'
    : message.title;
  const upgradeBody = checking
    ? 'جارٍ التحقق من الترقية المتاحة'
    : failed
    ? 'حاول مرة أخرى'
    : unavailable
    ? planLimitReached
      ? 'لا يوجد اشتراك أعلى يتيح رسائل إضافية لهذا الكورس'
      : 'لا توجد ترقية للشات متاحة لهذا الكورس'
    : message.body;
  const actionLabel = failed ? 'إعادة المحاولة' : message.action;
  return (
    <ScrollView
      contentContainerStyle={styles.entitlementGate}
      keyboardShouldPersistTaps="handled"
      showsVerticalScrollIndicator={false}>
      <Text style={styles.entitlementTitle}>
        {accessUnavailable
          ? 'الشات غير متاح الآن'
          : courseChatUnavailable
          ? 'الشات غير متاح في هذا الكورس'
          : needsUpgrade
          ? upgradeTitle
          : message.title}
      </Text>
      <Text style={styles.entitlementText}>
        {accessUnavailable
          ? 'أغلق الشات وحدّث الكورس قبل المحاولة مرة أخرى'
          : courseChatUnavailable
          ? 'يمكنك متابعة مشاهدة الكورس'
          : needsUpgrade
          ? upgradeBody
          : message.body}
      </Text>
      {checking && (
        <ActivityIndicator
          accessibilityLabel="جارٍ التحقق من الترقية"
          color={Palette.primary}
        />
      )}
      {!accessUnavailable &&
        !courseChatUnavailable &&
        (courseAccessRequired || upgradeStatus === 'available' || failed) && (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={actionLabel}
            onPress={
              courseAccessRequired
                ? onOpenCourseAccess
                : failed
                ? onRetryUpgrade
                : onUpgrade
            }
            style={({pressed}) => [
              styles.entitlementButton,
              pressed && styles.entitlementButtonPressed,
            ]}>
            <Text style={styles.entitlementButtonText}>{actionLabel}</Text>
          </Pressable>
        )}
    </ScrollView>
  );
};
