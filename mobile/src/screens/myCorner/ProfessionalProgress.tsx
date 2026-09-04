import React from 'react';
import {Pressable, ScrollView, Text, View} from 'react-native';
import {PremiumCard, SectionHeading} from '../../components/ui/PremiumUI';
import {CourseArtwork} from '../../components/ui/CourseArtwork';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import type {
  LearningPathLevel,
  LearningPathProgress,
} from '../../services/roknApi';
import type {LearningBadge} from './model';
import {styles} from './styles';

const juniorBadgeImage = require('../../assets/images/badges/junior.png');
const midLevelBadgeImage = require('../../assets/images/badges/mid-level.png');
const seniorBadgeImage = require('../../assets/images/badges/senior.png');

const localBadgeImage = (title: string) => {
  if (/senior/i.test(title)) return seniorBadgeImage;
  if (/mid/i.test(title)) return midLevelBadgeImage;
  return juniorBadgeImage;
};

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
  if (!visible) return null;

  return (
    <>
      <SectionHeading style={styles.section} title="شاراتك المهنية" />
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
                  numberOfLines={1}
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
      <View style={styles.badgeGrid}>
        {badges.map(badge => (
          <PremiumCard
            key={badge.id}
            style={[
              styles.badgeCard,
              largeText && styles.badgeCardLargeText,
              !earnedBadge && styles.badgeCardLocked,
            ]}>
            <CourseArtwork
              fallback={localBadgeImage(badge.title)}
              source={badge.imageUrl ? {uri: badge.imageUrl} : undefined}
              style={styles.badgeArtwork}
            />
            <Text style={styles.badgeTitle}>
              {formatArabicDisplayText(badge.title)}
            </Text>
            {!!badge.courseTitle && (
              <Text numberOfLines={2} style={styles.badgeCourse}>
                {formatArabicDisplayText(badge.courseTitle)}
              </Text>
            )}
            {!earnedBadge && (
              <Text style={styles.badgeLockedText}>اقتربت من الوصول</Text>
            )}
          </PremiumCard>
        ))}
      </View>
      {selectedPath && (
        <PremiumCard style={styles.pathCard}>
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
          {nextLevel && (
            <Text style={styles.pathHint}>
              {formatArabicDisplayText(
                `متبقي ${Math.round(
                  selectedPath.remainingToNextLevel || 0,
                )}% للوصول للهدف التالي`,
              )}
            </Text>
          )}
          {(selectedPath.currentLevel ||
            selectedPath.upcomingLevels.length) && (
            <View style={styles.levelList}>
              {selectedPath.currentLevel && (
                <PathLevelRow
                  current
                  level={selectedPath.currentLevel}
                  status="مستواك الحالي"
                />
              )}
              {selectedPath.upcomingLevels.map(level => (
                <PathLevelRow
                  key={level.id}
                  level={level}
                  status={level.id === nextLevel?.id ? 'الهدف التالي' : 'بعده'}
                />
              ))}
            </View>
          )}
        </PremiumCard>
      )}
    </>
  );
};

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
    <CourseArtwork
      fallback={localBadgeImage(level.name)}
      source={level.imageUrl ? {uri: level.imageUrl} : undefined}
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
