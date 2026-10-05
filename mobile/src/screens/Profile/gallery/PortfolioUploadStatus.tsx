import React from 'react';
import {ActivityIndicator, StyleSheet, Text, View} from 'react-native';
import {
  Palette,
  Spacing,
  Type,
  textDirection,
} from '../../../constants/designSystem';
import {toArabicDigits} from '../../../constants/arabicFormatting';
import type {PortfolioUploadProgress} from '../../../services/portfolioUploadProgress';

/** One transfer presentation for creation and adding files to an existing project. */
export const PortfolioUploadStatus = ({
  progress,
}: {
  progress: PortfolioUploadProgress | null;
}) => {
  const uploading = progress?.phase === 'uploading';
  const percentage = uploading && progress ? progress.percentage : null;
  const label =
    progress?.phase === 'saving'
      ? 'جار حفظ الملف'
      : progress?.phase === 'finalizing'
      ? 'جار حفظ المشروع'
      : uploading
      ? 'رفع الملفات'
      : 'جار تجهيز الملفات';
  const text =
    percentage === null ? label : `${label} ${toArabicDigits(percentage)}٪`;
  return (
    <View
      accessible
      style={styles.container}
      accessibilityRole="progressbar"
      accessibilityValue={
        percentage === null ? {text} : {min: 0, max: 100, now: percentage, text}
      }>
      <Text style={styles.label}>{text}</Text>
      {percentage === null ? (
        <ActivityIndicator color={Palette.primary} size="small" />
      ) : (
        <View style={styles.track}>
          <View style={[styles.fill, {width: `${percentage}%`}]} />
        </View>
      )}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    width: '100%',
    marginTop: Spacing.sm,
    alignItems: 'center',
    gap: Spacing.xs,
  },
  label: {
    ...Type.caption,
    ...textDirection,
    textAlign: 'center',
    color: Palette.textMuted,
  },
  track: {
    width: '100%',
    height: 3,
    borderRadius: 3,
    backgroundColor: Palette.line,
    overflow: 'hidden',
    alignItems: 'flex-end',
  },
  fill: {height: '100%', backgroundColor: Palette.primary},
});
