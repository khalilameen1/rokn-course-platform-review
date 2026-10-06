import React, {memo} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';
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
import {courseCatalogueLabel} from './courseCatalogueLabel';

export type {Course};

interface CourseCardProps {
  item: Course;
  onPress: (course: Course) => void;
  width?: number;
  sectionTitle?: string;
}

const CourseCard = memo<CourseCardProps>(
  ({item, onPress, width, sectionTitle}) => {
    const {fontScale, largeText, railCardWidth} = useResponsiveLayout();
    const titleLines = largeText ? 4 : 2;
    // Reserve the same scaled text slots across the rail, not a height derived
    // from each course's name. Native text remains free to grow if necessary.
    const titleMinHeight =
      Math.ceil(Type.bodyStrong.lineHeight * fontScale) * titleLines;
    const isAvailable = item.published !== false;
    const label = courseCatalogueLabel(item, sectionTitle);
    const accessibilitySummary = [formatAuthoredDisplayText(item.title), label]
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
          {!!label && (
            <MetaPill
              label={label}
              onArtwork
              tone={item.labelTone}
              style={styles.labelContainer}
            />
          )}
        </View>
        <Text
          numberOfLines={titleLines}
          ellipsizeMode="tail"
          style={[styles.courseTitle, {minHeight: titleMinHeight}]}>
          {formatAuthoredDisplayText(item.title)}
        </Text>
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
    includeFontPadding: false,
    textAlignVertical: 'top',
  },
  labelContainer: {
    position: 'absolute',
    top: Spacing.xs,
    start: Spacing.xs,
  },
  pressed: {opacity: 0.84},
});

CourseCard.displayName = 'CourseCard';
export default CourseCard;
