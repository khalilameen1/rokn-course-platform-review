import React from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import {
  Accessibility,
  Palette,
  Spacing,
  Type,
  textDirection,
} from '../../../constants/designSystem';

// The same read recovery appears beside the empty report or its retained
// transcript. It never receives the report-generation or paid-send actions.
export const ProjectFeedbackReadRecovery = ({
  error,
  retrying,
  onRetry,
}: {
  error: string;
  retrying: boolean;
  onRetry: () => void;
}) => (
  <View style={styles.notice}>
    <Text accessibilityRole="alert" style={styles.error}>
      {error}
    </Text>
    <Pressable
      accessibilityRole="button"
      accessibilityLabel="إعادة تحميل تقرير المشروع والمناقشة"
      accessibilityState={{busy: retrying, disabled: retrying}}
      disabled={retrying}
      onPress={onRetry}
      style={styles.retry}>
      <Text style={styles.action}>
        {retrying ? 'جارٍ التحديث' : 'إعادة التحميل'}
      </Text>
    </Pressable>
  </View>
);

const styles = StyleSheet.create({
  notice: {width: '100%', marginTop: Spacing.sm},
  error: {...Type.body, ...textDirection, color: Palette.danger},
  retry: {
    minHeight: Accessibility.minTouchTarget,
    minWidth: Accessibility.minTouchTarget,
    alignSelf: 'flex-start',
    justifyContent: 'center',
  },
  action: {...Type.bodyStrong, ...textDirection, color: Palette.text},
});
