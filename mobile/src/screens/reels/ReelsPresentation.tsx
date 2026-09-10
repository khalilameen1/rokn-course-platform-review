import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../../constants/arabicFormatting';
import {
  Palette,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {Fonts} from '../../constants/styleConstants';

export const ReelsLoadingState = () => (
  <View
    accessibilityLiveRegion="polite"
    accessibilityLabel="جارٍ تجهيز الكورس"
    style={styles.loadingState}>
    <View style={styles.loadingMark}>
      <ActivityIndicator color="#FFFFFF" size="large" />
    </View>
    <Text accessibilityRole="header" style={styles.loadingTitle}>
      جارٍ فتح الكورس
    </Text>
    <Text style={styles.loadingText}>سنفتح آخر مقطع وصلت إليه</Text>
  </View>
);

export const ReelsUnavailableState = ({
  message,
  onPrimary,
  onSecondary,
  primaryLabel,
  secondaryLabel,
  title,
}: {
  message: string;
  onPrimary: () => void;
  onSecondary: () => void;
  primaryLabel: string;
  secondaryLabel: string;
  title: string;
}) => (
  <View accessibilityLiveRegion="assertive" style={styles.loadingState}>
    <Text accessibilityRole="header" style={styles.loadErrorTitle}>
      {title}
    </Text>
    <Text style={styles.loadErrorText}>{message}</Text>
    <Pressable
      accessibilityRole="button"
      style={styles.loadRetryButton}
      onPress={onPrimary}>
      <Text style={styles.loadRetryText}>{primaryLabel}</Text>
    </Pressable>
    <Pressable
      accessibilityRole="button"
      style={styles.loadBackButton}
      onPress={onSecondary}>
      <Text style={styles.loadBackText}>{secondaryLabel}</Text>
    </Pressable>
  </View>
);

export const ReelsPreviewGate = ({
  bottomInset,
  onBackToDetails,
  onStartLearning,
  previewCount,
  topInset,
}: {
  bottomInset: number;
  onBackToDetails: () => void;
  onStartLearning: () => void;
  previewCount: number;
  topInset: number;
}) => (
  <View
    accessibilityViewIsModal
    accessibilityLabel="انتهت المعاينة المجانية"
    style={styles.previewGate}>
    <ScrollView
      bounces={false}
      contentInsetAdjustmentBehavior="automatic"
      contentContainerStyle={[
        styles.previewGateScrollContent,
        {
          paddingTop: topInset + 20,
          paddingBottom: Math.max(bottomInset, 18) + 18,
        },
      ]}
      showsVerticalScrollIndicator={false}
      style={styles.previewGateScroll}>
      <View style={styles.previewGateContent}>
        <View style={styles.previewBadge}>
          <Text style={styles.previewBadgeText}>معاينة الكورس</Text>
        </View>
        <Text accessibilityRole="header" style={styles.previewGateTitle}>
          انتهت المعاينة المجانية
        </Text>
        <Text style={styles.previewGateText}>
          شاهدت {formatArabicNumber(previewCount)} مقطع مجانًا
          {'\n'}افتح الكورس للمتابعة من المقطع التالي
        </Text>
        <Pressable
          accessibilityRole="button"
          onPress={onStartLearning}
          style={({pressed}) => [
            styles.previewGatePrimary,
            pressed && styles.previewGatePrimaryPressed,
          ]}>
          <Text style={styles.previewGatePrimaryText}>اختر الفئة المناسبة</Text>
        </Pressable>
        <Pressable
          accessibilityRole="button"
          onPress={onBackToDetails}
          style={({pressed}) => [
            styles.previewGateSecondary,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.previewGateSecondaryText}>
            العودة لتفاصيل الكورس
          </Text>
        </Pressable>
      </View>
    </ScrollView>
  </View>
);

export const ReelsConnectionNote = ({
  message,
  onPress,
  topInset,
}: {
  message: string;
  onPress?: () => void;
  topInset: number;
}) => (
  <Pressable
    accessibilityHint={onPress ? 'يعيد تحميل أحدث نسخة' : undefined}
    accessibilityLiveRegion="polite"
    accessibilityRole={onPress ? 'button' : 'alert'}
    disabled={!onPress}
    onPress={onPress}
    style={[styles.connectionNote, {top: topInset + 12}]}>
    <View style={styles.connectionDot} />
    <Text style={styles.connectionText}>
      {formatArabicDisplayText(message)}
    </Text>
  </Pressable>
);

const styles = StyleSheet.create({
  loadingState: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 24,
    backgroundColor: Palette.canvas,
  },
  loadingMark: {
    width: 72,
    height: 72,
    borderRadius: 24,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.surface,
  },
  loadingTitle: {
    ...Type.section,
    ...textDirection,
    textAlign: 'center',
    color: '#FFFFFF',
    marginTop: 20,
  },
  loadingText: {
    ...Type.body,
    ...textDirection,
    textAlign: 'center',
    color: Palette.textMuted,
    marginTop: 5,
  },
  loadErrorTitle: {
    ...Type.title,
    ...textDirection,
    color: '#FFFFFF',
    textAlign: 'center',
  },
  loadErrorText: {
    ...Type.body,
    ...textDirection,
    maxWidth: 420,
    color: Palette.textMuted,
    marginTop: 8,
    textAlign: 'center',
  },
  loadRetryButton: {
    minWidth: 190,
    minHeight: 48,
    borderRadius: 16,
    paddingHorizontal: 20,
    paddingVertical: 13,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.primary,
    marginTop: 22,
  },
  loadRetryText: {
    ...Type.button,
    ...textDirection,
    textAlign: 'center',
    color: '#FFFFFF',
  },
  loadBackButton: {
    minHeight: 48,
    paddingHorizontal: 16,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 6,
  },
  loadBackText: {
    ...Type.bodyStrong,
    ...textDirection,
    textAlign: 'center',
    color: 'rgba(255,255,255,.66)',
  },
  previewGate: {
    ...StyleSheet.absoluteFillObject,
    zIndex: 150,
    overflow: 'hidden',
    backgroundColor: Palette.canvas,
  },
  previewGateScroll: {flex: 1, width: '100%'},
  previewGateScrollContent: {
    flexGrow: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 20,
  },
  previewGateContent: {
    width: '100%',
    maxWidth: 480,
    alignItems: 'center',
    paddingVertical: 12,
  },
  previewBadge: {
    minHeight: 32,
    justifyContent: 'center',
  },
  previewBadgeText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
  },
  previewGateTitle: {
    ...Type.title,
    ...textDirection,
    color: '#FFFFFF',
    textAlign: 'center',
    marginTop: 20,
  },
  previewGateText: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: 10,
  },
  previewGatePrimary: {
    width: '100%',
    minHeight: 54,
    borderRadius: 17,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 12,
    paddingHorizontal: 18,
    backgroundColor: Palette.primary,
    marginTop: 22,
  },
  previewGatePrimaryPressed: {
    backgroundColor: Palette.primaryPressed,
    transform: [{scale: 0.985}],
  },
  previewGatePrimaryText: {
    ...Type.button,
    ...textDirection,
    textAlign: 'center',
    color: '#FFFFFF',
  },
  previewGateSecondary: {
    minHeight: 48,
    justifyContent: 'center',
    paddingHorizontal: 18,
    paddingVertical: 10,
    marginTop: 7,
  },
  previewGateSecondaryText: {
    ...Type.bodyStrong,
    ...textDirection,
    textAlign: 'center',
    color: 'rgba(255,255,255,.72)',
  },
  pressed: {opacity: 0.72},
  connectionNote: {
    position: 'absolute',
    alignSelf: 'center',
    maxWidth: '86%',
    minHeight: 48,
    borderRadius: 14,
    paddingHorizontal: 13,
    paddingVertical: 10,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 7,
    backgroundColor: 'rgba(7,11,18,.96)',
    zIndex: 100,
  },
  connectionDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    backgroundColor: '#76A9FF',
  },
  connectionText: {
    ...textDirection,
    flexShrink: 1,
    color: 'rgba(255,255,255,.86)',
    fontFamily: Fonts.medium,
    fontSize: 11,
    textAlign: 'center',
  },
});
