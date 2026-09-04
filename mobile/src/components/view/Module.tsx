import {useNavigation} from '@react-navigation/native';
import React, {useEffect, useMemo, useState} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {Fonts} from '../../constants/styleConstants';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';
import {CourseLearningModule, CourseProject} from '../VideoPlayer/types';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import type {RootNavigation} from '../../navigation/types';
import {
  courseLearningGateState,
  learningGateTextForStep,
  learningGateText,
  moduleStepIsComplete,
  orderedModuleSteps,
} from '../VideoPlayer/courseLearning/sequence';

interface ModuleProps {
  courseId: string;
  module: CourseLearningModule;
  initiallyExpanded?: boolean;
}

const Chevron = ({open}: {open: boolean}) => (
  <Svg width={18} height={18} viewBox="0 0 20 20">
    <Path
      d={open ? 'm4 12 6-6 6 6' : 'm4 8 6 6 6-6'}
      fill="none"
      stroke="rgba(255,255,255,.72)"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const MapProjectCard = ({
  project,
  locked = false,
  onOpen,
}: {
  project: CourseProject;
  locked?: boolean;
  onOpen: () => void;
}) => (
  <View style={[styles.projectCard, locked && styles.lockedProjectPreview]}>
    <View style={styles.projectTopRow}>
      <View style={styles.projectBadge}>
        <Text style={styles.projectBadgeText}>
          {project.isGraduationProject ? 'مشروع التخرج' : 'مشروع العبور'}
        </Text>
      </View>
      {project.status === 'passed' ? (
        <Text style={styles.passedText}>تم العبور ✓</Text>
      ) : locked ? (
        <View style={styles.lockPill}>
          <Text style={styles.lockPillText}>مغلق</Text>
        </View>
      ) : null}
    </View>
    <Text style={styles.projectTitle}>{project.title}</Text>
    {!!project.requirements && (
      <Text style={styles.projectRequirements} numberOfLines={3}>
        {formatArabicDisplayText(project.requirements)}
      </Text>
    )}
    <Text style={styles.projectPassedCopy}>
      {locked
        ? 'أكمل الخطوة السابقة'
        : project.status === 'evaluating'
        ? 'نراجع تسليمك الآن'
        : project.status === 'needs_changes'
        ? 'يحتاج إلى تعديل'
        : project.status === 'passed'
        ? project.reportEnabled && project.reportStatus === 'queued'
          ? 'نجهّز تقرير مشروعك'
          : project.reportEnabled
          ? 'افتح النتيجة والتقرير'
          : 'تم اعتماد المشروع'
        : 'افتح تفاصيل المشروع'}
    </Text>
    <Pressable
      accessibilityRole="button"
      accessibilityState={{disabled: locked}}
      disabled={locked}
      onPress={onOpen}
      style={[styles.submitProject, locked && styles.disabledButton]}>
      <Text style={styles.submitProjectText}>
        {project.status === 'needs_changes' ? 'راجع النتيجة' : 'فتح المشروع'}
      </Text>
    </Pressable>
  </View>
);

const Module = ({courseId, module, initiallyExpanded = false}: ModuleProps) => {
  const navigation = useNavigation<RootNavigation>();
  const [expanded, setExpanded] = useState(initiallyExpanded);
  const orderedSteps = useMemo(() => orderedModuleSteps(module), [module]);
  const completed = orderedSteps.filter(moduleStepIsComplete).length;
  const percentage = Math.round(
    (completed / Math.max(1, orderedSteps.length)) * 100,
  );

  useEffect(() => {
    if (initiallyExpanded) setExpanded(true);
  }, [initiallyExpanded]);

  return (
    <View style={[styles.container, module.isLocked && styles.lockedContainer]}>
      <Pressable
        accessibilityRole="button"
        accessibilityState={{expanded}}
        style={styles.header}
        onPress={() => setExpanded(value => !value)}>
        <View style={styles.moduleOrder}>
          <Text style={styles.moduleOrderText}>
            {formatArabicDisplayText(module.order)}
          </Text>
        </View>
        <View style={styles.headerCopy}>
          <Text style={styles.title}>
            {formatArabicDisplayText(module.title)}
          </Text>
          <Text style={styles.meta}>
            {formatArabicDisplayText(
              `${module.reels.length} مقطع · ${percentage}% مكتمل`,
            )}
          </Text>
        </View>
        <View style={styles.headerActions}>
          {module.isLocked && (
            <View style={styles.lockPill}>
              <Text style={styles.lockPillText}>مغلق</Text>
            </View>
          )}
          <Chevron open={expanded} />
        </View>
      </Pressable>

      <View style={styles.progressTrack}>
        <View style={[styles.progressFill, {width: `${percentage}%`}]} />
      </View>

      {expanded && (
        <View style={styles.content}>
          {module.isLocked && (
            <Text style={styles.lockedHint}>
              {learningGateText(module.lockReason)}
            </Text>
          )}

          <View style={styles.reelsSection}>
            <Text style={styles.sectionLabel}>محتوى الوحدة</Text>
            {orderedSteps.map((step, stepIndex) => {
              const gateState = courseLearningGateState(
                module,
                orderedSteps,
                stepIndex,
              );
              const unavailable =
                gateState === 'locked_purchase' ||
                gateState === 'locked_project';
              if (step.type === 'project') {
                return unavailable ? (
                  <View
                    key={`ordered-project-${step.project.id}`}
                    style={[styles.projectCard, styles.lockedProjectPreview]}>
                    <View style={styles.projectTopRow}>
                      <View style={styles.projectBadge}>
                        <Text style={styles.projectBadgeText}>
                          {step.project.isGraduationProject
                            ? 'مشروع التخرج'
                            : 'مشروع العبور'}
                        </Text>
                      </View>
                      <View style={styles.lockPill}>
                        <Text style={styles.lockPillText}>مغلق</Text>
                      </View>
                    </View>
                    <Text style={styles.projectTitle}>
                      {step.project.title}
                    </Text>
                    <Text style={styles.lockedProjectHint}>
                      {learningGateTextForStep(
                        module,
                        orderedSteps,
                        stepIndex,
                      )}
                    </Text>
                  </View>
                ) : (
                  <MapProjectCard
                    key={`ordered-project-${step.project.id}`}
                    project={step.project}
                    locked={false}
                    onOpen={() =>
                      navigation.navigate('Reels', {
                        courseId,
                        reelId: undefined,
                        lessonId: undefined,
                        projectId: step.project.id,
                        preview: false,
                        previewCount: undefined,
                      })
                    }
                  />
                );
              }
              return (
                <Pressable
                  key={`ordered-reel-${step.reel.id}`}
                  accessibilityRole="button"
                  accessibilityState={{disabled: unavailable}}
                  disabled={unavailable}
                  style={[styles.reelRow, unavailable && styles.lockedReelRow]}
                  onPress={() =>
                    navigation.navigate('Reels', {
                      courseId,
                      reelId: step.reel.id,
                      lessonId: undefined,
                      projectId: undefined,
                      preview: false,
                      previewCount: undefined,
                    })
                  }>
                  <View
                    style={[
                      styles.reelNumber,
                      step.reel.isCompleted && styles.completedReelNumber,
                    ]}>
                    <Text style={styles.reelNumberText}>
                      {formatArabicDisplayText(step.reel.reelNumber)}
                    </Text>
                  </View>
                  <View style={styles.reelCopy}>
                    <Text style={styles.reelTitle} numberOfLines={2}>
                      {formatArabicDisplayText(step.reel.title)}
                    </Text>
                    <Text style={styles.reelMeta}>
                      {unavailable
                        ? learningGateTextForStep(
                            module,
                            orderedSteps,
                            stepIndex,
                          )
                        : step.reel.isCompleted
                        ? 'شوهدت'
                        : 'مقطع قصير'}
                    </Text>
                  </View>
                  {unavailable ? (
                    <View style={styles.lockedStepPill}>
                      <Text style={styles.lockedStepText}>مغلق</Text>
                    </View>
                  ) : (
                    <View style={styles.playButton}>
                      <Text style={styles.playText}>▶</Text>
                    </View>
                  )}
                </Pressable>
              );
            })}
          </View>
        </View>
      )}
    </View>
  );
};

export default React.memo(Module);
export {MapProjectCard};

const styles = StyleSheet.create({
  container: {
    direction: 'rtl',
    width: '100%',
    maxWidth: 760,
    alignSelf: 'center',
    borderRadius: 22,
    marginBottom: 13,
    backgroundColor: '#101720',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.075)',
    overflow: 'hidden',
  },
  lockedContainer: {
    opacity: 0.78,
  },
  header: {
    minHeight: 82,
    paddingHorizontal: 15,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 12,
  },
  moduleOrder: {
    width: 42,
    height: 42,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(75,142,247,.14)',
    borderWidth: 1,
    borderColor: 'rgba(91,153,251,.22)',
  },
  moduleOrderText: {
    color: '#8BB6FA',
    fontFamily: Fonts.bold,
    fontSize: 15,
  },
  headerCopy: {
    flex: 1,
    minWidth: 0,
  },
  title: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 15,
    lineHeight: 23,
    ...textDirection,
  },
  meta: {
    color: 'rgba(255,255,255,.47)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    marginTop: 2,
    ...textDirection,
  },
  lockPill: {
    minHeight: 27,
    paddingHorizontal: 10,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  lockPillText: {
    color: 'rgba(255,255,255,.57)',
    fontFamily: Fonts.medium,
    fontSize: 10,
  },
  headerActions: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 9,
    flexShrink: 0,
  },
  progressTrack: {
    height: 2,
    backgroundColor: 'rgba(255,255,255,.07)',
  },
  progressFill: {
    height: '100%',
    backgroundColor: '#4B8EF7',
  },
  lockedHint: {
    color: 'rgba(255,255,255,.48)',
    fontFamily: Fonts.regular,
    fontSize: 11,
    lineHeight: 19,
    marginBottom: 12,
    ...textDirection,
  },
  content: {
    padding: 14,
  },
  sectionLabel: {
    color: 'rgba(255,255,255,.48)',
    fontFamily: Fonts.medium,
    fontSize: 10,
    marginBottom: 8,
    ...textDirection,
  },
  reelsSection: {
    gap: 5,
  },
  reelRow: {
    minHeight: 60,
    borderRadius: 15,
    paddingHorizontal: 9,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
    backgroundColor: 'rgba(255,255,255,.025)',
  },
  lockedReelRow: {
    backgroundColor: 'rgba(255,255,255,.018)',
  },
  reelNumber: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  completedReelNumber: {
    backgroundColor: 'rgba(65,192,132,.13)',
  },
  reelNumberText: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  reelCopy: {
    flex: 1,
    minWidth: 0,
  },
  reelTitle: {
    color: 'rgba(255,255,255,.9)',
    fontFamily: Fonts.medium,
    fontSize: 12,
    ...textDirection,
  },
  reelMeta: {
    color: 'rgba(255,255,255,.38)',
    fontFamily: Fonts.regular,
    fontSize: 9,
    marginTop: 2,
    ...textDirection,
  },
  playButton: {
    width: 32,
    height: 32,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.07)',
  },
  playText: {
    color: '#FFFFFF',
    fontSize: 10,
    marginLeft: 2,
  },
  lockedStepPill: {
    minHeight: 28,
    paddingHorizontal: 9,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.055)',
  },
  lockedStepText: {
    color: 'rgba(255,255,255,.52)',
    fontFamily: Fonts.medium,
    fontSize: 9,
  },
  projectCard: {
    direction: 'rtl',
    marginTop: 18,
    borderRadius: 19,
    padding: 16,
    backgroundColor: '#151E29',
    borderWidth: 1,
    borderColor: 'rgba(118,169,255,.16)',
  },
  lockedProjectPreview: {
    borderColor: 'rgba(255,255,255,.08)',
    backgroundColor: 'rgba(255,255,255,.025)',
  },
  lockedProjectHint: {
    ...textDirection,
    color: 'rgba(255,255,255,.42)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    marginTop: 4,
  },
  projectTopRow: {
    ...rtlRowStyle,
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  projectBadge: {
    minHeight: 25,
    paddingHorizontal: 9,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(75,142,247,.14)',
  },
  projectBadgeText: {
    color: '#8BB6FA',
    fontFamily: Fonts.semiBold,
    fontSize: 10,
  },
  passedText: {
    color: '#67D39B',
    fontFamily: Fonts.semiBold,
    fontSize: 10,
  },
  projectTitle: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 17,
    lineHeight: 26,
    marginTop: 10,
    ...textDirection,
  },
  projectRequirements: {
    color: 'rgba(255,255,255,.62)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    marginTop: 5,
    ...textDirection,
  },
  submitProject: {
    minHeight: 48,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
    marginTop: 10,
  },
  disabledButton: {
    opacity: 0.38,
  },
  submitProjectText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 12,
  },
  projectPassedCopy: {
    color: 'rgba(255,255,255,.45)',
    fontFamily: Fonts.regular,
    fontSize: 9,
    marginTop: 2,
    ...textDirection,
  },
});
