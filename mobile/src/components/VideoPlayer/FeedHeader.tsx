import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import React, {useEffect, useState} from 'react';
import {
  BackHandler,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  useWindowDimensions,
  View,
} from 'react-native';
import Svg, {Circle, Path} from 'react-native-svg';
import {
  formatArabicNumber,
  toArabicDigits,
} from '../../constants/arabicFormatting';
import {
  Palette,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {Fonts} from '../../constants/styleConstants';
import {VideoQuality} from './types';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';

const SPEED_OPTIONS = [0.75, 1, 1.25, 1.5, 2];

interface FeedHeaderProps {
  playbackSpeed: number;
  onPlaybackSpeedChange: (speed: number) => void;
  selectedQuality: VideoQuality;
  qualityOptions: VideoQuality[];
  onQualityChange: (quality: VideoQuality) => void;
  onOpenChange?: (open: boolean) => void;
  topInset?: number;
}

const BackIcon = () => (
  <Svg width={22} height={22} viewBox="0 0 24 24">
    <Path
      d="M8.5 5 15.5 12l-7 7"
      fill="none"
      stroke="#fff"
      strokeWidth={2.1}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const DotsIcon = () => (
  <Svg width={24} height={24} viewBox="0 0 24 24">
    {[6, 12, 18].map(y => (
      <Circle key={y} cx={12} cy={y} r={1.7} fill="#fff" />
    ))}
  </Svg>
);

const CheckIcon = () => (
  <Svg width={17} height={17} viewBox="0 0 20 20">
    <Path
      d="m4 10.4 3.7 3.5L16 5.8"
      fill="none"
      stroke={Palette.text}
      strokeWidth={2.2}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const qualityLabel = (quality: VideoQuality) =>
  quality === 'auto' ? 'تلقائي · موصى به' : toArabicDigits(quality);

const FeedHeader = ({
  playbackSpeed,
  onPlaybackSpeedChange,
  selectedQuality,
  qualityOptions,
  onQualityChange,
  onOpenChange,
  topInset = 0,
}: FeedHeaderProps) => {
  const navigation = useNavigation<RootNavigation>();
  const {height} = useWindowDimensions();
  const [open, setOpen] = useState(false);

  useEffect(() => {
    onOpenChange?.(open);
    return () => {
      if (open) onOpenChange?.(false);
    };
  }, [onOpenChange, open]);

  useEffect(() => {
    if (!qualityOptions.includes(selectedQuality)) {
      onQualityChange('auto');
    }
  }, [onQualityChange, qualityOptions, selectedQuality]);

  useEffect(() => {
    if (!open) return undefined;
    const subscription = BackHandler.addEventListener(
      'hardwareBackPress',
      () => {
        setOpen(false);
        return true;
      },
    );
    return () => subscription.remove();
  }, [open]);

  return (
    <View pointerEvents="box-none" style={StyleSheet.absoluteFill}>
      <View style={[styles.header, {top: topInset + 8}]}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="العودة"
          hitSlop={10}
          style={styles.iconButton}
          onPress={() => goBackOrHome(navigation)}>
          <BackIcon />
        </Pressable>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="إعدادات المشاهدة"
          hitSlop={10}
          style={styles.iconButton}
          onPress={() => setOpen(value => !value)}>
          <DotsIcon />
        </Pressable>
      </View>

      {open && (
        <>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="إغلاق إعدادات المشاهدة"
            style={styles.backdrop}
            onPress={() => setOpen(false)}
          />
          <View
            accessibilityViewIsModal
            style={[
              styles.panel,
              {
                maxHeight: Math.max(180, height - topInset - 76),
                top: topInset + 58,
              },
            ]}>
            <ScrollView
              bounces={false}
              contentContainerStyle={styles.panelContent}
              showsVerticalScrollIndicator={false}>
              <Text accessibilityRole="header" style={styles.panelTitle}>
                إعدادات المشاهدة
              </Text>

              <Text style={styles.sectionLabel}>السرعة</Text>
              <View accessibilityRole="radiogroup" style={styles.speedRow}>
                {SPEED_OPTIONS.map(speed => {
                  const selected = playbackSpeed === speed;
                  return (
                    <Pressable
                      key={speed}
                      accessibilityRole="radio"
                      accessibilityState={{checked: selected}}
                      style={[
                        styles.speedChip,
                        selected && styles.selectedChip,
                      ]}
                      onPress={() => onPlaybackSpeedChange(speed)}>
                      <Text
                        style={[
                          styles.speedText,
                          selected && styles.selectedText,
                        ]}>
                        {formatArabicNumber(speed, {maximumFractionDigits: 2})}×
                      </Text>
                    </Pressable>
                  );
                })}
              </View>

              <View style={styles.divider} />
              <Text style={styles.sectionLabel}>الجودة</Text>
              <View accessibilityRole="radiogroup" style={styles.qualityGrid}>
                {qualityOptions.map(quality => {
                  const selected = selectedQuality === quality;
                  return (
                    <Pressable
                      key={quality}
                      accessibilityRole="radio"
                      accessibilityState={{checked: selected}}
                      style={[
                        styles.qualityChip,
                        selected && styles.selectedChip,
                      ]}
                      onPress={() => onQualityChange(quality)}>
                      <Text
                        style={[
                          styles.qualityText,
                          selected && styles.selectedText,
                        ]}>
                        {qualityLabel(quality)}
                      </Text>
                      {selected && <CheckIcon />}
                    </Pressable>
                  );
                })}
              </View>
            </ScrollView>
          </View>
        </>
      )}
    </View>
  );
};

export default FeedHeader;

const styles = StyleSheet.create({
  header: {
    position: 'absolute',
    left: 12,
    right: 12,
    ...rtlRowStyle,
    justifyContent: 'space-between',
    zIndex: 70,
  },
  iconButton: {
    width: 48,
    height: 48,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(7,11,18,.86)',
  },
  backdrop: {
    ...StyleSheet.absoluteFillObject,
    zIndex: 72,
    backgroundColor: 'rgba(0,0,0,.22)',
  },
  panel: {
    position: 'absolute',
    left: 12,
    right: 12,
    zIndex: 73,
    alignSelf: 'center',
    maxWidth: 430,
    padding: 18,
    borderRadius: 20,
    backgroundColor: Palette.surface,
    shadowColor: '#000',
    shadowOpacity: 0.35,
    shadowRadius: 22,
    shadowOffset: {width: 0, height: 12},
    elevation: 18,
  },
  panelContent: {
    flexGrow: 1,
  },
  panelTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 17,
    marginBottom: 17,
  },
  sectionLabel: {
    ...textDirection,
    color: Palette.textMuted,
    fontFamily: Fonts.medium,
    fontSize: 12,
    marginBottom: 10,
  },
  speedRow: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    gap: 8,
  },
  speedChip: {
    minWidth: 52,
    minHeight: 48,
    paddingHorizontal: 12,
    paddingVertical: 10,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.surfaceRaised,
    borderWidth: 1,
    borderColor: 'transparent',
  },
  selectedChip: {
    backgroundColor: Palette.surfacePressed,
    borderColor: Palette.textMuted,
  },
  speedText: {
    color: 'rgba(255,255,255,.74)',
    fontFamily: Fonts.medium,
    fontSize: 13,
    fontVariant: ['tabular-nums'],
  },
  selectedText: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
  },
  divider: {
    height: 1,
    backgroundColor: 'rgba(255,255,255,.1)',
    marginVertical: 17,
  },
  qualityGrid: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    gap: 8,
  },
  qualityChip: {
    minHeight: 48,
    minWidth: 86,
    paddingHorizontal: 11,
    paddingVertical: 10,
    maxWidth: '100%',
    borderRadius: 12,
    ...rtlRowStyle,
    justifyContent: 'center',
    alignItems: 'center',
    gap: 5,
    backgroundColor: Palette.surfaceRaised,
    borderWidth: 1,
    borderColor: 'transparent',
  },
  qualityText: {
    flexShrink: 1,
    ...textDirection,
    color: 'rgba(255,255,255,.75)',
    fontFamily: Fonts.regular,
    fontSize: 14,
  },
});
