import React, {useEffect, useState} from 'react';
import {Pressable, ScrollView, Text, View} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {Palette} from '../../constants/designSystem';
import {SectionHeading} from '../../components/ui/PremiumUI';
import {AppArtwork, levelArtworkKey} from '../../components/ui/AppArtwork';
import {
  formatArabicDisplayText,
  formatAuthoredDisplayText,
} from '../../constants/arabicFormatting';
import type {
  LearningPathLevel,
  LearningPathProgress,
} from '../../services/roknApi';
import type {LearningBadge} from './model';
import {styles} from './styles';

type Props = {
  badges: LearningBadge[];
  earnedBadge: boolean;
  largeText: boolean;
  learningPaths: LearningPathProgress[];
  nextLevel?: LearningPathLevel;
  onSelectPath: (pathId: string) => void;
  pathProgress: number;
  selectedPath?: LearningPathProgress;
  visible: boolean;
};

export const ProfessionalProgress = ({
  badges,
  earnedBadge,
  largeText,
  learningPaths,
  nextLevel,
  onSelectPath,
  pathProgress,
  selectedPath,
  visible,
}: Props) => {
  const [showAllLevels, setShowAllLevels] = useState(false);
  const [showBadges, setShowBadges] = useState(false);
  useEffect(() => {
    setShowAllLevels(false);
    setShowBadges(false);
  }, [selectedPath?.id]);
  if (!visible || (!selectedPath && !earnedBadge)) return null;
  const laterLevels =
    selectedPath?.upcomingLevels.filter(level => level.id !== nextLevel?.id) ||
    [];

  return (
    <>
      <SectionHeading
        style={styles.section}
        title={selectedPath ? 'تقدمك المهني' : 'شاراتك المهنية'}
      />
      {learningPaths.length > 1 && (
        <ScrollView
          horizontal
          contentContainerStyle={styles.pathSelector}
          showsHorizontalScrollIndicator={false}>
          {learningPaths.map(path => {
            const active = path.id === selectedPath?.id;
            return (
              <Pressable
                accessibilityRole="button"
                accessibilityState={{selected: active}}
                key={path.id}
                onPress={() => onSelectPath(path.id)}
                style={[styles.pathChoice, active && styles.pathChoiceActive]}>
                <Text
                  numberOfLines={largeText ? 3 : 2}
                  style={[
                    styles.pathChoiceText,
                    active && styles.pathChoiceTextActive,
                  ]}>
                  {formatArabicDisplayText(path.title)}
                </Text>
              </Pressable>
            );
          })}
        </ScrollView>
      )}
      {selectedPath && (
        <View style={styles.pathCard}>
          <View style={styles.pathProgressRow}>
            <Text style={styles.pathTitle}>
              {formatArabicDisplayText(
                nextLevel
                  ? `تقدمك نحو ${nextLevel.name}`
                  : selectedPath.currentLevel
                  ? `مستواك ${selectedPath.currentLevel.name}`
                  : selectedPath.title || 'مسارك المهني',
              )}
            </Text>
            <Text style={styles.pathValue}>
              {formatArabicDisplayText(`${Math.round(pathProgress)}%`)}
            </Text>
          </View>
          <View style={styles.progressTrack}>
            <View style={[styles.progressFill, {width: `${pathProgress}%`}]} />
          </View>
          {Boolean(
            selectedPath.currentLevel || selectedPath.upcomingLevels.length,
          ) && (
            <View style={styles.levelList}>
              {selectedPath.currentLevel && (
                <PathLevelRow
                  current
                  level={selectedPath.currentLevel}
                  status="مستواك الحالي"
                />
              )}
              {nextLevel && (
                <PathLevelRow level={nextLevel} status="الهدف التالي" />
              )}
              {showAllLevels &&
                laterLevels.map(level => (
                  <PathLevelRow key={level.id} level={level} status="لاحقًا" />
                ))}
            </View>
          )}
          {laterLevels.length > 0 && (
            <Pressable
              accessibilityRole="button"
              accessibilityState={{expanded: showAllLevels}}
              onPress={() => setShowAllLevels(value => !value)}
              style={styles.levelsToggle}>
              <Text style={styles.levelStatus}>
                {showAllLevels ? 'إخفاء المستويات' : 'كل المستويات'}
              </Text>
              <DisclosureChevron expanded={showAllLevels} />
            </Pressable>
          )}
        </View>
      )}
      {earnedBadge && selectedPath && (
        <Pressable
          accessibilityRole="button"
          accessibilityState={{expanded: showBadges}}
          onPress={() => setShowBadges(value => !value)}
          style={styles.levelsToggle}>
          <Text style={styles.levelStatus}>
            {showBadges ? 'إخفاء الشارات' : 'شاراتك المكتسبة'}
          </Text>
          <DisclosureChevron expanded={showBadges} />
        </Pressable>
      )}
      {earnedBadge && (!selectedPath || showBadges) && (
        <View style={styles.badgeGrid}>
          {badges.map(badge => (
            <View
              key={badge.id}
              style={[
                styles.badgeCard,
                largeText && styles.badgeCardLargeText,
              ]}>
              <AppArtwork
                asset={levelArtworkKey(badge.order)}
                uri={badge.imageUrl}
                resizeMode="contain"
                style={styles.badgeArtwork}
              />
              <View style={styles.badgeCopy}>
                <Text style={styles.badgeTitle}>
                  {formatArabicDisplayText(badge.title)}
                </Text>
                {!!badge.courseTitle && (
                  <Text
                    numberOfLines={largeText ? 4 : 2}
                    style={styles.badgeCourse}>
                    {formatAuthoredDisplayText(badge.courseTitle)}
                  </Text>
                )}
              </View>
            </View>
          ))}
        </View>
      )}
    </>
  );
};

const DisclosureChevron = ({expanded}: {expanded: boolean}) => (
  <Svg width={18} height={18} viewBox="0 0 20 20" accessibilityElementsHidden>
    <Path
      d={expanded ? 'm5 12 5-5 5 5' : 'm5 8 5 5 5-5'}
      fill="none"
      stroke={Palette.textMuted}
      strokeWidth={1.6}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const PathLevelRow = ({
  current = false,
  level,
  status,
}: {
  current?: boolean;
  level: LearningPathLevel;
  status: string;
}) => (
  <View style={[styles.levelRow, current && styles.levelRowCurrent]}>
    <AppArtwork
      asset={levelArtworkKey(level.order)}
      uri={level.imageUrl}
      resizeMode="contain"
      style={styles.levelArtwork}
    />
    <View style={styles.levelCopy}>
      <Text style={styles.levelName}>
        {formatArabicDisplayText(level.name)}
      </Text>
      <Text style={styles.levelStatus}>{status}</Text>
    </View>
  </View>
);
