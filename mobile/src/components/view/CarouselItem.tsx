import React, {memo} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import type {Course} from '../../types/Course';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {CourseArtwork} from '../ui/CourseArtwork';

const CarouselItem = ({
  course,
  onButtonPress,
}: {
  course: Course;
  onButtonPress: () => void;
}) => {
  const {
    contentWidth,
    gutter,
    largeText,
    featuredCardWidth,
    featuredCardMinHeight,
  } = useResponsiveLayout();

  return (
    <View
      style={[
        styles.feature,
        {maxWidth: contentWidth, paddingHorizontal: gutter},
      ]}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={formatAuthoredDisplayText(course.title)}
        accessibilityHint="يفتح تفاصيل الكورس"
        onPress={onButtonPress}
        style={({pressed}) => [
          styles.card,
          {width: featuredCardWidth, minHeight: featuredCardMinHeight},
          pressed && styles.pressed,
        ]}>
        <CourseArtwork
          source={course.image}
          fallback={require('../../assets/images/courseSlider.jpg')}
          style={styles.artwork}
        />
        <LinearGradient
          pointerEvents="none"
          // Large titles grow upward into the artwork. Keep every possible
          // text position readable instead of relying on the bottom fade.
          colors={
            largeText
              ? [
                  'rgba(7,10,16,0.65)',
                  'rgba(7,10,16,0.82)',
                  'rgba(7,10,16,0.92)',
                  Palette.canvas,
                ]
              : [
                  'rgba(7,10,16,0)',
                  'rgba(7,10,16,0.12)',
                  'rgba(7,10,16,0.86)',
                  Palette.canvas,
                ]
          }
          locations={[0.25, 0.42, 0.68, 0.96]}
          style={StyleSheet.absoluteFill}
        />
        <View style={styles.copy}>
          <Text
            accessibilityRole="header"
            accessibilityLabel={formatAuthoredDisplayText(course.title)}
            numberOfLines={largeText ? 4 : 2}
            ellipsizeMode="tail"
            style={[
              styles.title,
              featuredCardWidth <= 324 && styles.titleCompact,
            ]}>
            {formatAuthoredDisplayText(course.title)}
          </Text>
          <View style={styles.details}>
            <Text style={styles.detailsText}>عرض الكورس</Text>
          </View>
        </View>
      </Pressable>
    </View>
  );
};

const styles = StyleSheet.create({
  feature: {width: '100%', alignSelf: 'center', marginBottom: Spacing.md},
  card: {
    justifyContent: 'flex-end',
    borderRadius: Radius.lg,
    overflow: 'hidden',
    backgroundColor: Palette.surface,
  },
  artwork: {
    ...StyleSheet.absoluteFillObject,
    width: '100%',
    height: '100%',
    resizeMode: 'cover',
  },
  // Content remains in normal flow so large Arabic text can grow the card.
  copy: {
    paddingTop: 142,
    paddingHorizontal: Spacing.lg,
    paddingBottom: Spacing.xl,
    direction: 'rtl',
    alignItems: 'stretch',
  },
  title: {
    ...Type.display,
    fontSize: Type.display.fontSize * 0.9,
    lineHeight: Type.display.lineHeight * 0.96,
    ...textDirection,
    textAlign: 'center',
    color: Palette.text,
  },
  titleCompact: {
    fontSize: Type.display.fontSize * 0.8,
    lineHeight: Type.display.lineHeight * 0.86,
  },
  details: {
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.md,
    width: '100%',
    backgroundColor: Palette.action,
    borderRadius: Radius.sm,
    minHeight: Accessibility.minTouchTarget,
    maxWidth: '100%',
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.xs,
  },
  detailsText: {
    ...Type.button,
    ...textDirection,
    textAlign: 'center',
    color: Palette.text,
    flexShrink: 1,
  },
  pressed: {opacity: 0.86},
});

export default memo(CarouselItem);
