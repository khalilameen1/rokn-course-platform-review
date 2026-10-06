import React from 'react';
import {Pressable, ScrollView, StyleSheet, Text, View} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {RasterImage as Image} from '../../../components/ui/RasterImage';
import {
  Palette,
  Spacing,
  Type,
  textDirection,
  rtlRowStyle,
} from '../../../constants/designSystem';
import {formatArabicNumber} from '../../../constants/arabicFormatting';
import type {PortfolioDraftAsset} from './usePortfolioDraftEditor';

// Reuses Rokn's project attachment preview/remove primitives. The visible
// per-item review/remove flow follows mobile attachment-composer previews;
// no Stream SDK code or state providers are copied into the portfolio.
export const PortfolioDraftMediaList = ({
  files,
  disabled,
  onRemove,
}: {
  files: PortfolioDraftAsset[];
  disabled: boolean;
  onRemove: (file: PortfolioDraftAsset) => void;
}) => {
  if (!files.length) return null;
  return (
    <ScrollView
      horizontal
      showsHorizontalScrollIndicator={false}
      contentContainerStyle={styles.list}>
      {files.map((file, index) => {
        const video = String(file.type || '').startsWith('video/');
        const name =
          file.fileName ||
          `${video ? 'فيديو' : 'صورة'} ${formatArabicNumber(index + 1)}`;
        return (
          <View key={file.uri} style={styles.item}>
            <View style={styles.preview}>
              {video ? (
                <Text style={styles.videoLabel}>فيديو</Text>
              ) : (
                <Image
                  accessibilityLabel={`معاينة ${name}`}
                  progressiveRenderingEnabled
                  resizeMethod="resize"
                  source={{uri: file.uri}}
                  style={styles.image}
                />
              )}
            </View>
            <Pressable
              accessibilityLabel={`إزالة ${name}`}
              accessibilityRole="button"
              accessibilityState={{disabled}}
              disabled={disabled}
              onPress={() => onRemove(file)}
              style={[styles.remove, disabled && styles.disabled]}>
              <Svg
                accessible={false}
                accessibilityElementsHidden
                importantForAccessibility="no-hide-descendants"
                width={18}
                height={18}
                viewBox="0 0 24 24">
                <Path
                  d="m6 6 12 12M6 18 18 6"
                  fill="none"
                  stroke={Palette.text}
                  strokeWidth={1.8}
                  strokeLinecap="round"
                />
              </Svg>
            </Pressable>
            <Text numberOfLines={1} style={styles.name}>
              {name}
            </Text>
          </View>
        );
      })}
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  list: {
    ...rtlRowStyle,
    gap: Spacing.sm,
    paddingVertical: Spacing.sm,
  },
  item: {width: 112},
  preview: {
    height: 112,
    borderRadius: 14,
    overflow: 'hidden',
    backgroundColor: Palette.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  image: {width: '100%', height: '100%', resizeMode: 'cover'},
  remove: {
    position: 'absolute',
    top: 0,
    left: 0,
    width: 48,
    height: 48,
    borderRadius: 24,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.overlay,
  },
  disabled: {opacity: 0.45},
  name: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  videoLabel: {...Type.bodyStrong, color: Palette.textMuted},
});
