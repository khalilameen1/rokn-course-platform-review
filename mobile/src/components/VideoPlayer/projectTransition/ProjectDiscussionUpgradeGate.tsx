import React from 'react';
import {ActivityIndicator, Pressable, StyleSheet, Text, View} from 'react-native';
import {Palette, Type, textDirection} from '../../../constants/designSystem';
import {useCourseUpgradeOffer} from '../../../hooks/useCourseUpgradeOffer';
import {useAppForegroundState} from '../../../hooks/useAppActiveState';

/** Mounted only after the learner opens an exhausted, ready discussion. */
export const ProjectDiscussionUpgradeGate = ({
  active,
  courseId,
  ownerKey,
  onUpgrade,
}: {
  active: boolean;
  courseId?: string;
  ownerKey: string;
  onUpgrade: () => void;
}) => {
  const foreground = useAppForegroundState();
  const offer = useCourseUpgradeOffer({
    ownerKey,
    courseId: courseId || '',
    requiredFeature: 'project_discussion',
    active: active && foreground && Boolean(courseId),
  });
  const checking = Boolean(courseId) && ['idle', 'loading'].includes(offer.status);
  return (
    <View style={styles.container}>
      <Text style={styles.text}>انتهى حد مناقشة المشاريع في اشتراكك</Text>
      {checking && (
        <ActivityIndicator
          accessibilityLabel="جارٍ التحقق من الترقية"
          color={Palette.primary}
        />
      )}
      {offer.status === 'unavailable' && (
        <Text style={styles.text}>
          لا يوجد اشتراك أعلى يتيح مناقشة إضافية لهذا الكورس
        </Text>
      )}
      {offer.status === 'error' && (
        <Text accessibilityRole="alert" style={styles.text}>
          تعذّر التحقق من الترقية
        </Text>
      )}
      {(offer.status === 'available' || offer.status === 'error') && (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={
            offer.status === 'error' ? 'إعادة المحاولة' : 'قم بترقية الاشتراك'
          }
          onPress={offer.status === 'error' ? offer.retry : onUpgrade}
          style={styles.action}>
          <Text style={styles.actionText}>
            {offer.status === 'error' ? 'إعادة المحاولة' : 'قم بترقية الاشتراك'}
          </Text>
        </Pressable>
      )}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {gap: 12},
  text: {...Type.body, ...textDirection, color: Palette.textMuted},
  action: {
    minHeight: 48,
    paddingHorizontal: 16,
    paddingVertical: 10,
    borderRadius: 12,
    backgroundColor: Palette.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  actionText: {...Type.bodyStrong, ...textDirection, color: Palette.primary},
});
