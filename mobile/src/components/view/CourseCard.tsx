import React, {memo} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import {Fonts} from '../../constants/styleConstants';
import {
  formatArabicDisplayText,
  formatAuthoredDisplayText,
} from '../../constants/arabicFormatting';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {MetaPill} from '../ui/PremiumUI';
import {CourseArtwork} from '../ui/CourseArtwork';
import type {Course} from '../../types/Course';

export type {Course};

interface CourseCardProps {
  item: Course;
  onPress: (course: Course) => void;
  width?: number;
  sectionTitle?: string;
}

const CourseCard = memo<CourseCardProps>(
  ({item, onPress, width, sectionTitle}) => {
    const {largeText, railCardWidth} = useResponsiveLayout();
    const isAvailable = item.published !== false;
    const label = formatArabicDisplayText(item.label);
    const showLabel =
      !!label && label !== formatArabicDisplayText(sectionTitle);
    const progress = Math.max(0, Math.min(100, Number(item.progress || 0)));
    const accessibilitySummary = [
      formatAuthoredDisplayText(item.title),
      item.owned && item.started === true
        ? formatArabicDisplayText(`اكتمل ${Math.round(progress)}٪`)
        : undefined,
    ]
      .filter(Boolean)
      .join('، ');

    return (
      <Pressable
        accessibilityHint={
          isAvailable
            ? 'يفتح تفاصيل الكورس'
            : item.label === 'قريبًا'
            ? 'بطاقة معاينة لكورس سيتوفر قريبًا'
            : 'بطاقة معاينة للكورس'
        }
        accessibilityLabel={accessibilitySummary}
        accessibilityRole="button"
        onPress={() => onPress(item)}
        style={({pressed}) => [
          styles.courseItem,
          {width: width ?? railCardWidth},
          pressed && styles.pressed,
        ]}>
        <View style={styles.imageWrap}>
          <CourseArtwork
            fallback={require('../../assets/images/courseSlider.jpg')}
            source={item.image}
            style={styles.courseImage}
          />
          {showLabel && (
            <MetaPill
              label={label}
              onArtwork
              tone={item.labelTone}
              style={styles.labelContainer}
            />
          )}
          {typeof item.progress === 'number' && item.progress > 0 && (
            <View style={styles.progressTrack}>
              <View
                style={[
                  styles.progressFill,
                  {width: `${Math.min(100, item.progress)}%`},
                ]}
              />
            </View>
          )}
        </View>
        <Text numberOfLines={largeText ? 4 : 2} style={styles.courseTitle}>
          {formatAuthoredDisplayText(item.title)}
        </Text>
        {!!item.instructor && (
          <Text numberOfLines={largeText ? 2 : 1} style={styles.instructor}>
            {formatAuthoredDisplayText(item.instructor)}
          </Text>
        )}
        {isAvailable && (item.owned || item.coinPrice === 0) && (
          <View style={styles.metaRow}>
            {item.owned ? (
              <Text style={styles.ownedLabel}>
                {progress >= 100
                  ? 'راجع الكورس'
                  : item.started === true
                  ? 'قيد التعلّم'
                  : 'ضمن كورساتك'}
              </Text>
            ) : (
              <Text style={styles.ownedLabel}>مجاني</Text>
            )}
          </View>
        )}
      </Pressable>
    );
  },
  (previous, next) =>
    previous.item === next.item &&
    previous.onPress === next.onPress &&
    previous.width === next.width &&
    previous.sectionTitle === next.sectionTitle,
);

const styles = StyleSheet.create({
  courseItem: {
    minWidth: 154,
    direction: 'rtl',
    alignItems: 'stretch',
    paddingBottom: Spacing.xs,
  },
  imageWrap: {
    width: '100%',
    aspectRatio: 1.42,
    borderRadius: Radius.lg,
    overflow: 'hidden',
    backgroundColor: Palette.surfaceRaised,
  },
  courseImage: {width: '100%', height: '100%', resizeMode: 'cover'},
  courseTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    width: '100%',
    alignSelf: 'stretch',
    marginTop: Spacing.xs,
  },
  labelContainer: {
    position: 'absolute',
    top: Spacing.xs,
    right: Spacing.xs,
  },
  instructor: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    width: '100%',
    alignSelf: 'stretch',
  },
  metaRow: {
    width: '100%',
    minHeight: 22,
    alignItems: 'stretch',
    justifyContent: 'center',
    marginTop: Spacing.xxs,
  },
  ownedLabel: {
    ...Type.caption,
    ...textDirection,
    color: '#8BB5FF',
    fontFamily: Fonts.semiBold,
  },
  progressTrack: {
    position: 'absolute',
    start: 0,
    end: 0,
    bottom: 0,
    height: 3,
    backgroundColor: 'rgba(255,255,255,0.16)',
  },
  progressFill: {height: '100%', backgroundColor: Palette.primary},
  pressed: {opacity: 0.84},
});

CourseCard.displayName = 'CourseCard';
export default CourseCard;
