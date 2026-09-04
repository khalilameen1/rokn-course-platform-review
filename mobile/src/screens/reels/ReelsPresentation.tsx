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
import {Palette, rtlRowStyle} from '../../constants/designSystem';
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
    <View style={styles.previewGateGlow} />
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
          <Text style={styles.previewBadgeText}>معاينة مجانية مكتملة</Text>
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
    backgroundColor: '#070B11',
  },
  loadingMark: {
    width: 72,
    height: 72,
    borderRadius: 24,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#111923',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  loadingTitle: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 18,
    marginTop: 20,
  },
  loadingText: {
    color: 'rgba(255,255,255,.5)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    marginTop: 5,
  },
  loadErrorTitle: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 20,
    textAlign: 'center',
  },
  loadErrorText: {
    maxWidth: 420,
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.regular,
    fontSize: 13,
    lineHeight: 22,
    marginTop: 8,
    textAlign: 'center',
  },
  loadRetryButton: {
    minWidth: 190,
    minHeight: 48,
    borderRadius: 16,
    paddingHorizontal: 20,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
    marginTop: 22,
  },
  loadRetryText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 14,
  },
  loadBackButton: {
    minHeight: 44,
    paddingHorizontal: 16,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 6,
  },
  loadBackText: {
    color: 'rgba(255,255,255,.66)',
    fontFamily: Fonts.medium,
    fontSize: 12,
  },
  previewGate: {
    ...StyleSheet.absoluteFillObject,
    zIndex: 150,
    overflow: 'hidden',
    backgroundColor: 'rgba(5,8,13,.97)',
  },
  previewGateScroll: {flex: 1, width: '100%'},
  previewGateScrollContent: {
    flexGrow: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 20,
  },
  previewGateGlow: {
    position: 'absolute',
    top: '14%',
    width: 330,
    height: 330,
    borderRadius: 165,
    backgroundColor: 'rgba(44,105,219,.12)',
  },
  previewGateContent: {
    width: '100%',
    maxWidth: 480,
    alignItems: 'center',
    paddingVertical: 12,
  },
  previewBadge: {
    minHeight: 32,
    borderRadius: 16,
    justifyContent: 'center',
    paddingHorizontal: 13,
    backgroundColor: 'rgba(44,105,219,.16)',
    borderWidth: 1,
    borderColor: 'rgba(119,164,244,.24)',
  },
  previewBadgeText: {
    color: '#9BBEFF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  previewGateTitle: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 24,
    lineHeight: 34,
    textAlign: 'center',
    marginTop: 20,
  },
  previewGateText: {
    color: 'rgba(255,255,255,.64)',
    fontFamily: Fonts.regular,
    fontSize: 14,
    lineHeight: 24,
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
    backgroundColor: Palette.primary,
    marginTop: 22,
  },
  previewGatePrimaryPressed: {
    backgroundColor: Palette.primaryPressed,
    transform: [{scale: 0.985}],
  },
  previewGatePrimaryText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 15,
  },
  previewGateSecondary: {
    minHeight: 48,
    justifyContent: 'center',
    paddingHorizontal: 18,
    paddingVertical: 10,
    marginTop: 7,
  },
  previewGateSecondaryText: {
    color: 'rgba(255,255,255,.72)',
    fontFamily: Fonts.medium,
    fontSize: 13,
  },
  pressed: {opacity: 0.72},
  connectionNote: {
    position: 'absolute',
    alignSelf: 'center',
    maxWidth: '86%',
    minHeight: 38,
    borderRadius: 19,
    paddingHorizontal: 13,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 7,
    backgroundColor: 'rgba(12,17,25,.92)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.1)',
    zIndex: 100,
  },
  connectionDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    backgroundColor: '#76A9FF',
  },
  connectionText: {
    color: 'rgba(255,255,255,.86)',
    fontFamily: Fonts.medium,
    fontSize: 11,
    textAlign: 'center',
  },
});
