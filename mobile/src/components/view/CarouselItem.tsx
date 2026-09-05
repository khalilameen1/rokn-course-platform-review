import React, {memo} from 'react';
import {ImageBackground, StyleSheet, Text, View} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import type {Course} from '../../types/Course';
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
import Button from '../touchables/Button';
import {MetaPill} from '../ui/PremiumUI';
import {CoinAmount} from '../ui/RoknCoin';

const CarouselItem = ({
  course,
  onButtonPress,
}: {
  course: Course;
  onButtonPress: () => void;
}) => {
  const {isTablet} = useResponsiveLayout();
  const owned = course.owned === true;

  return (
    <View style={styles.outer}>
      <ImageBackground source={course.image} style={styles.imageBackground}>
        <LinearGradient
          colors={[
            'rgba(7,10,16,0.03)',
            'rgba(7,10,16,0.55)',
            Palette.canvas,
          ]}
          locations={[0.15, 0.58, 1]}
          style={styles.gradient}>
          <View style={styles.copy}>
            {!!course.label && (
              <MetaPill
                label={formatArabicDisplayText(course.label)}
                style={styles.weekPill}
                tone={course.labelTone}
              />
            )}
            <Text numberOfLines={2} style={styles.title}>
              {formatAuthoredDisplayText(course.title)}
            </Text>
            {course.published === false ? (
              <Text style={styles.courseState}>قريبًا</Text>
            ) : owned ? (
              <Text style={styles.courseState}>
                {course.started === true
                  ? 'استكمل من مكانك'
                  : 'ابدأ التعلّم الآن'}
              </Text>
            ) : course.coinPrice === 0 ? (
              <Text style={styles.courseState}>مجاني</Text>
            ) : typeof course.coinPrice === 'number' ? (
              <CoinAmount
                size={16}
                value={course.coinPrice}
                style={styles.price}
                textStyle={styles.priceText}
              />
            ) : null}
            {isTablet && (
              <Text numberOfLines={2} style={styles.description}>
                {formatAuthoredDisplayText(course.description)}
              </Text>
            )}
            <View style={styles.ctaRow}>
              <Button
                accessibilityLabel={`عرض ${formatAuthoredDisplayText(course.title)}`}
                onPress={onButtonPress}
                style={styles.button}
                title="عرض الكورس"
              />
            </View>
          </View>
        </LinearGradient>
      </ImageBackground>
    </View>
  );
};

const styles = StyleSheet.create({
  outer: {flex: 1, paddingHorizontal: Spacing.md},
  imageBackground: {
    flex: 1,
    justifyContent: 'flex-end',
    borderRadius: Radius.xl,
    overflow: 'hidden',
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  gradient: {flex: 1, justifyContent: 'flex-end'},
  copy: {
    width: '100%',
    maxWidth: 620,
    direction: 'rtl',
    alignSelf: 'stretch',
    alignItems: 'stretch',
    paddingHorizontal: Spacing.lg,
    paddingBottom: Spacing.lg,
    paddingTop: Spacing.sm,
  },
  title: {
    ...Type.title,
    ...textDirection,
    color: Palette.text,
    width: '92%',
    marginLeft: 'auto',
    marginTop: Spacing.xs,
  },
  description: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    width: '92%',
    marginLeft: 'auto',
    marginTop: Spacing.xs,
  },
  courseState: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  price: {alignSelf: 'flex-end', marginTop: Spacing.xs},
  priceText: {...Type.caption, color: Palette.textMuted},
  weekPill: {alignSelf: 'flex-start'},
  ctaRow: {width: '100%', alignItems: 'center', marginTop: Spacing.xs},
  button: {minWidth: 184, marginTop: 0},
});

export default memo(CarouselItem);
