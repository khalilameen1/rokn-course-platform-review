import React, {memo} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import {Fonts, PixelPerfect} from '../../constants/styleConstants';
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
import {CoinAmount} from '../ui/RoknCoin';
import type {Course} from '../../types/Course';

export type {Course};

interface CourseCardProps {
  item: Course;
  onPress: (course: Course) => void;
  width?: number;
}

const CourseCard = memo<CourseCardProps>(
  ({item, onPress, width}) => {
    const {largeText, railCardWidth} = useResponsiveLayout();
    const isAvailable = item.published !== false;
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
          {!!item.label && (
            <MetaPill
              label={formatArabicDisplayText(item.label)}
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
        {(item.published === false ||
          item.owned ||
          item.coinPrice !== undefined) && (
          <View style={styles.metaRow}>
            {item.published === false ? (
              <Text style={styles.upcomingLabel}>قريبًا</Text>
            ) : item.owned ? (
              <Text style={styles.ownedLabel}>
                {progress >= 100
                  ? 'راجع الكورس'
                  : item.started === true
                  ? 'استكمل من مكانك'
                  : 'ابدأ التعلّم الآن'}
              </Text>
            ) : item.coinPrice === 0 ? (
              <Text style={styles.ownedLabel}>مجاني</Text>
            ) : (
              <CoinAmount
                size={15}
                value={item.coinPrice!}
                style={styles.price}
                textStyle={styles.priceText}
              />
            )}
          </View>
        )}
      </Pressable>
    );
  },
  (previous, next) =>
    previous.item === next.item &&
    previous.onPress === next.onPress &&
    previous.width === next.width,
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
    borderWidth: 1,
    borderColor: Palette.lineSoft,
  },
  courseImage: {width: '100%', height: '100%', resizeMode: 'cover'},
  courseTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    width: '100%',
    alignSelf: 'stretch',
    marginTop: Spacing.sm,
    minHeight: PixelPerfect(48),
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
  price: {alignSelf: 'flex-end'},
  priceText: {
    ...Type.caption,
    color: Palette.textMuted,
    fontFamily: Fonts.semiBold,
  },
  upcomingLabel: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
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
  pressed: {opacity: 0.8, transform: [{scale: 0.985}]},
});

CourseCard.displayName = 'CourseCard';
export default CourseCard;
