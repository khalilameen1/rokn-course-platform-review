import React from 'react';
import {Pressable, ScrollView, Text} from 'react-native';
import {subscriptionMessages} from '../../../constants/subscriptionMessages';
import {courseChatStyles as styles} from './styles';

export const CourseChatGate = ({
  accessUnavailable,
  courseAccessRequired,
  courseChatUnavailable,
  onOpenCourseAccess,
  onUpgrade,
  planLimitReached,
}: {
  accessUnavailable: boolean;
  courseAccessRequired: boolean;
  courseChatUnavailable: boolean;
  onOpenCourseAccess: () => void;
  onUpgrade: () => void;
  planLimitReached: boolean;
}) => {
  const message = courseAccessRequired
    ? subscriptionMessages.chatSubscribe
    : planLimitReached
    ? subscriptionMessages.chatExhausted
    : subscriptionMessages.chatUpgrade;
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
          : message.title}
      </Text>
      <Text style={styles.entitlementText}>
        {accessUnavailable
          ? 'أغلق الشات وحدّث الكورس قبل المحاولة مرة أخرى'
          : courseChatUnavailable
          ? 'يمكنك متابعة مشاهدة الكورس'
          : message.body}
      </Text>
      {!accessUnavailable && !courseChatUnavailable && (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={message.action}
          onPress={courseAccessRequired ? onOpenCourseAccess : onUpgrade}
          style={({pressed}) => [
            styles.entitlementButton,
            pressed && styles.entitlementButtonPressed,
          ]}>
          <Text style={styles.entitlementButtonText}>{message.action}</Text>
        </Pressable>
      )}
    </ScrollView>
  );
};
