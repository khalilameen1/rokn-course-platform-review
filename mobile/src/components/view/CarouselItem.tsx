import React, {memo} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import type {Course} from '../../types/Course';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {ArrowRight} from '../../assets/SVG';
import {CourseArtwork} from '../ui/CourseArtwork';
import {courseCatalogueLabel} from './courseCatalogueLabel';

const CarouselItem = ({
  course,
  onButtonPress,
}: {
  course: Course;
  onButtonPress: () => void;
}) => {
  const {gutter, featuredHorizontal, featuredImageWidth, featuredImageHeight} =
    useResponsiveLayout();
  const label = courseCatalogueLabel(course);

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={[
        formatAuthoredDisplayText(course.title),
        course.instructor,
        label,
      ]
        .filter(Boolean)
        .join(' — ')}
      accessibilityHint="يفتح تفاصيل الكورس"
      onPress={onButtonPress}
      style={({pressed}) => [
        styles.feature,
        featuredHorizontal && styles.featureWide,
        {paddingHorizontal: gutter},
        pressed && styles.pressed,
      ]}>
      <View style={[styles.artworkFrame, {width: featuredImageWidth}]}>
        <CourseArtwork
          source={course.image}
          fallback={require('../../assets/images/courseSlider.jpg')}
          style={[
            styles.artwork,
            {
              height: featuredImageHeight,
            },
          ]}
        />
      </View>
      <View style={[styles.copy, featuredHorizontal && styles.copyWide]}>
        {!!label && (
          <Text numberOfLines={1} style={styles.eyebrow}>
            {label}
          </Text>
        )}
        <Text
          accessibilityRole="header"
          accessibilityLabel={formatAuthoredDisplayText(course.title)}
          numberOfLines={2}
          ellipsizeMode="tail"
          style={[styles.title, featuredHorizontal && styles.titleWide]}>
          {formatAuthoredDisplayText(course.title)}
        </Text>
        {!!course.instructor && (
          <Text
            numberOfLines={1}
            ellipsizeMode="tail"
            style={styles.instructor}>
            {formatAuthoredDisplayText(course.instructor)}
          </Text>
        )}
        <View style={styles.footer}>
          <View style={styles.details}>
            <Text style={styles.detailsText}>عرض الكورس</Text>
            <View style={styles.detailsArrow}>
              <ArrowRight width={18} height={18} />
            </View>
          </View>
        </View>
      </View>
    </Pressable>
  );
};

const styles = StyleSheet.create({
  feature: {width: '100%', marginBottom: Spacing.md},
  featureWide: {...rtlRowStyle, alignItems: 'center', gap: Spacing.xl},
  artworkFrame: {
    borderRadius: Radius.lg,
    overflow: 'hidden',
    backgroundColor: Palette.surface,
  },
  artwork: {width: '100%', resizeMode: 'cover'},
  copy: {paddingTop: Spacing.sm, direction: 'rtl', alignItems: 'stretch'},
  copyWide: {flex: 1, minWidth: 0, paddingTop: 0},
  eyebrow: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginBottom: Spacing.xxs,
  },
  title: {
    ...Type.section,
    ...textDirection,
    color: Palette.text,
    maxWidth: 660,
  },
  titleWide: {...Type.title},
  instructor: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xxs,
  },
  footer: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'flex-start',
    marginTop: Spacing.xs,
  },
  details: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'center',
    gap: Spacing.xs,
    backgroundColor: Palette.action,
    borderRadius: Radius.sm,
    minHeight: Accessibility.minTouchTarget,
    minWidth: Accessibility.minTouchTarget * 3,
    maxWidth: '100%',
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.xs,
    flexShrink: 1,
  },
  detailsText: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    flexShrink: 1,
  },
  detailsArrow: {transform: [{rotate: '180deg'}]},
  pressed: {opacity: 0.86},
});

export default memo(CarouselItem);
