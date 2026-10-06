import React, {memo} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import type {Course} from '../../types/Course';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';
import {Fonts} from '../../constants/styleConstants';
import {
  Accessibility,
  Palette,
  Spacing,
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
    featuredGutter,
    largeText,
    featuredCardWidth,
    featuredCardMinHeight,
  } = useResponsiveLayout();

  return (
    <View
      style={[
        styles.feature,
        {maxWidth: contentWidth, paddingHorizontal: featuredGutter},
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
        <View
          style={[
            styles.copy,
            featuredCardWidth <= 328 && styles.copyCompact,
          ]}>
          <Text
            accessibilityRole="header"
            accessibilityLabel={formatAuthoredDisplayText(course.title)}
            numberOfLines={largeText ? 4 : 2}
            ellipsizeMode="tail"
            textBreakStrategy="balanced"
            style={[
              styles.title,
              featuredCardWidth <= 328 && styles.titleCompact,
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
    borderRadius: 16,
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
    paddingHorizontal: 20,
    paddingBottom: 22,
    direction: 'rtl',
    alignItems: 'stretch',
  },
  copyCompact: {paddingHorizontal: 16},
  title: {
    fontFamily: Fonts.extraBold,
    fontSize: 27,
    lineHeight: 40.5,
    ...textDirection,
    textAlign: 'center',
    color: Palette.text,
  },
  titleCompact: {
    fontSize: 24,
    lineHeight: 36,
  },
  details: {
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 18,
    width: '100%',
    backgroundColor: Palette.action,
    borderRadius: 8,
    minHeight: Accessibility.minTouchTarget,
    maxWidth: '100%',
    paddingHorizontal: 16,
    paddingVertical: 10,
  },
  detailsText: {
    fontFamily: Fonts.bold,
    fontSize: 15,
    lineHeight: 22.5,
    ...textDirection,
    textAlign: 'center',
    color: Palette.text,
    flexShrink: 1,
  },
  pressed: {opacity: 0.86},
});

export default memo(CarouselItem);
