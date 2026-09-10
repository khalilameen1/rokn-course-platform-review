import React, {useEffect, useRef} from 'react';
import {
  AccessibilityInfo,
  Animated,
  StyleProp,
  StyleSheet,
  View,
  ViewStyle,
} from 'react-native';

import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  rtlRowStyle,
  useResponsiveLayout,
} from '../../constants/designSystem';

type SkeletonBlockProps = {
  height: number;
  style?: StyleProp<ViewStyle>;
  width?: ViewStyle['width'];
  radius?: number;
};

/**
 * Lightweight opacity-only placeholder with no shimmer dependency or per-card
 * gradients. Animation stops when reduced motion is enabled.
 */
export const SkeletonBlock = ({
  height,
  radius = Radius.sm,
  style,
  width = '100%',
}: SkeletonBlockProps) => {
  const opacity = useRef(new Animated.Value(0.42)).current;

  useEffect(() => {
    let animation: Animated.CompositeAnimation | null = null;
    let active = true;

    AccessibilityInfo.isReduceMotionEnabled()
      .then(reducedMotion => {
        if (!active || reducedMotion) return;
        animation = Animated.loop(
          Animated.sequence([
            Animated.timing(opacity, {
              duration: 720,
              toValue: 0.78,
              useNativeDriver: true,
            }),
            Animated.timing(opacity, {
              duration: 720,
              toValue: 0.42,
              useNativeDriver: true,
            }),
          ]),
        );
        animation.start();
      })
      .catch(() => undefined);

    return () => {
      active = false;
      animation?.stop();
    };
  }, [opacity]);

  return (
    <Animated.View
      accessible={false}
      importantForAccessibility="no"
      style={[
        styles.block,
        {borderRadius: radius, height, opacity, width},
        style,
      ]}
    />
  );
};

export const CatalogueSkeleton = () => {
  const {
    gutter,
    railCardWidth,
    featuredHorizontal,
    featuredImageWidth,
    featuredImageHeight,
  } = useResponsiveLayout();
  return (
    <View
      accessible
      accessibilityLabel="جارٍ تجهيز الكورسات"
      accessibilityLiveRegion="polite"
      accessibilityRole="progressbar"
      style={{paddingTop: Spacing.md}}>
      <View
        style={[
          {paddingHorizontal: gutter},
          featuredHorizontal && styles.featureWide,
        ]}>
        <SkeletonBlock
          height={featuredImageHeight}
          width={featuredImageWidth}
          radius={Radius.lg}
        />
        <View style={featuredHorizontal && styles.featureCopyWide}>
          <SkeletonBlock height={16} width="28%" style={styles.cardTitle} />
          <SkeletonBlock height={28} width="88%" style={styles.cardMeta} />
          <SkeletonBlock height={20} width="44%" style={styles.cardMeta} />
          <View style={styles.featureActions}>
            <SkeletonBlock height={Accessibility.minTouchTarget} width={152} />
          </View>
        </View>
      </View>
      {[0, 1].map(section => (
        <View key={section} style={styles.section}>
          <View style={{paddingHorizontal: gutter}}>
            <SkeletonBlock height={28} width="46%" />
          </View>
          <View style={[styles.rail, {paddingHorizontal: gutter}]}>
            {[0, 1, 2].map(card => (
              <View key={card} style={{width: railCardWidth}}>
                <SkeletonBlock
                  height={railCardWidth / 1.42}
                  radius={Radius.lg}
                />
                <SkeletonBlock
                  height={22}
                  style={styles.cardTitle}
                  width="88%"
                />
                <SkeletonBlock
                  height={16}
                  style={styles.cardMeta}
                  width="55%"
                />
              </View>
            ))}
          </View>
        </View>
      ))}
    </View>
  );
};

export const CourseDetailsSkeleton = () => (
  <View
    accessible
    accessibilityLabel="جارٍ تجهيز تفاصيل الكورس"
    accessibilityLiveRegion="polite"
    accessibilityRole="progressbar"
    style={styles.details}>
    <SkeletonBlock height={28} width="92%" />
    <SkeletonBlock height={20} style={styles.detailLine} width="76%" />
    <SkeletonBlock height={20} style={styles.detailLine} width="58%" />
    <View style={styles.detailTabs}>
      <SkeletonBlock height={48} width="48%" />
      <SkeletonBlock height={48} width="48%" />
    </View>
    {[0, 1, 2].map(row => (
      <SkeletonBlock
        height={74}
        key={row}
        radius={Radius.md}
        style={styles.detailRow}
      />
    ))}
  </View>
);

export const LearningDashboardSkeleton = () => (
  <View
    accessible
    accessibilityLabel="جارٍ ترتيب ركنك"
    accessibilityLiveRegion="polite"
    accessibilityRole="progressbar"
    style={styles.dashboard}>
    <SkeletonBlock height={148} radius={Radius.lg} />
    <SkeletonBlock height={28} width="84%" style={styles.cardTitle} />
    <SkeletonBlock height={20} width="56%" style={styles.cardMeta} />
    <SkeletonBlock height={48} style={styles.detailAction} />
    {[0, 1, 2].map(row => (
      <View key={row} style={styles.learningRow}>
        <SkeletonBlock height={88} radius={Radius.sm} width={104} />
        <View style={styles.learningCopy}>
          <SkeletonBlock height={24} width="88%" />
          <SkeletonBlock height={17} style={styles.detailLine} width="62%" />
          <SkeletonBlock
            height={7}
            radius={Radius.pill}
            style={styles.learningProgress}
          />
        </View>
      </View>
    ))}
  </View>
);

export const SavedLibrarySkeleton = () => (
  <View
    accessible
    accessibilityLabel="جارٍ تجهيز محفوظاتك"
    accessibilityLiveRegion="polite"
    accessibilityRole="progressbar"
    style={styles.dashboard}>
    <View style={styles.savedFilters}>
      <SkeletonBlock height={38} radius={Radius.pill} width={96} />
      <SkeletonBlock height={38} radius={Radius.pill} width={112} />
      <SkeletonBlock height={38} radius={Radius.pill} width={82} />
    </View>
    {[0, 1, 2, 3].map(row => (
      <View key={row} style={styles.savedRow}>
        <SkeletonBlock height={82} radius={Radius.md} width={76} />
        <View style={styles.learningCopy}>
          <SkeletonBlock height={21} width="86%" />
          <SkeletonBlock height={16} style={styles.detailLine} width="58%" />
        </View>
      </View>
    ))}
  </View>
);

const styles = StyleSheet.create({
  block: {backgroundColor: Palette.surfaceRaised},
  section: {marginTop: Spacing.xl},
  featureWide: {...rtlRowStyle, alignItems: 'center', gap: Spacing.xl},
  featureCopyWide: {flex: 1, minWidth: 0},
  featureActions: {
    ...rtlRowStyle,
    justifyContent: 'flex-start',
    marginTop: Spacing.sm,
  },
  rail: {
    flexDirection: 'row',
    gap: Spacing.sm,
    marginTop: Spacing.xs,
    overflow: 'hidden',
  },
  cardTitle: {marginTop: Spacing.sm},
  cardMeta: {marginTop: Spacing.xs},
  details: {paddingVertical: Spacing.lg},
  detailLine: {marginTop: Spacing.xs},
  detailAction: {marginTop: Spacing.xl},
  detailTabs: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginTop: Spacing.xl,
  },
  detailRow: {marginTop: Spacing.sm},
  dashboard: {paddingVertical: Spacing.md},
  learningRow: {
    ...rtlRowStyle,
    gap: Spacing.md,
    paddingVertical: Spacing.lg,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
  },
  learningCopy: {flex: 1, minWidth: 0, justifyContent: 'center'},
  learningProgress: {marginTop: Spacing.md},
  savedFilters: {
    flexDirection: 'row',
    gap: Spacing.xs,
    marginBottom: Spacing.lg,
  },
  savedRow: {
    ...rtlRowStyle,
    gap: Spacing.md,
    alignItems: 'center',
    paddingVertical: Spacing.sm,
    marginBottom: Spacing.sm,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
  },
});
