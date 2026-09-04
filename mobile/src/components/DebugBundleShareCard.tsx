import React, {useState} from 'react';
import {ActivityIndicator, Pressable, StyleSheet, Text} from 'react-native';

import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../constants/designSystem';
import {shareDebugBundle} from '../services/debugBundle';
import {PremiumCard} from './ui/PremiumUI';

/** Diagnostics are read only after the learner explicitly asks to share them. */
export const DebugBundleShareCard = () => {
  const [sharing, setSharing] = useState(false);
  const [error, setError] = useState('');

  const share = async () => {
    if (sharing) return;
    setSharing(true);
    setError('');
    try {
      await shareDebugBundle();
    } catch {
      setError('تعذّرت المشاركة الآن\nحاول مرة أخرى');
    } finally {
      setSharing(false);
    }
  };

  return (
    <PremiumCard style={styles.card}>
      <Text accessibilityRole="header" style={styles.title}>
        معلومات التشغيل
      </Text>
      <Text style={styles.description}>شاركها إذا طلبها منك فريق الدعم</Text>

      {!!error && (
        <Text accessibilityRole="alert" style={styles.error}>
          {error}
        </Text>
      )}
      <Pressable
        accessibilityHint="يفتح قائمة المشاركة بمعلومات تشغيل التطبيق"
        accessibilityLabel="مشاركة معلومات التشغيل"
        accessibilityRole="button"
        accessibilityState={{busy: sharing, disabled: sharing}}
        disabled={sharing}
        onPress={() => void share()}
        style={({pressed}) => [
          styles.shareButton,
          sharing && styles.shareButtonDisabled,
          pressed && styles.pressed,
        ]}>
        {sharing ? (
          <ActivityIndicator color={Palette.text} size="small" />
        ) : (
          <Text style={styles.shareButtonText}>مشاركة</Text>
        )}
      </Pressable>
    </PremiumCard>
  );
};

const styles = StyleSheet.create({
  card: {padding: Spacing.lg, gap: Spacing.sm},
  title: {...Type.section, ...textDirection, color: Palette.text},
  description: {...Type.caption, ...textDirection, color: Palette.textMuted},
  error: {...Type.caption, ...textDirection, color: Palette.danger},
  shareButton: {
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  shareButtonDisabled: {opacity: 0.5},
  shareButtonText: {...Type.button, color: Palette.text},
  pressed: {opacity: 0.75},
});
