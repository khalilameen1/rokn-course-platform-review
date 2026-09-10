import {useNavigation} from '@react-navigation/native';
import React, {useEffect, useMemo, useState} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {Fonts} from '../../constants/styleConstants';
import {
  Palette,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {CourseLearningModule, CourseProject} from '../VideoPlayer/types';
import {
  formatArabicDisplayText,
  formatAuthoredDisplayText,
} from '../../constants/arabicFormatting';
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
        {formatAuthoredDisplayText(project.requirements)}
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
            {formatAuthoredDisplayText(module.title)}
          </Text>
          <Text style={styles.meta}>
            {formatArabicDisplayText(
              `${module.reels.length} مقطع${
                module.projects?.length
                  ? ` · ${module.projects.length} مشروع`
                  : ''
              } · ${percentage}% مكتمل`,
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
                      {learningGateTextForStep(module, orderedSteps, stepIndex)}
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
                    <Text style={styles.reelTitle}>
                      {formatAuthoredDisplayText(step.reel.title)}
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
    marginBottom: 18,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
  },
  lockedContainer: {
    opacity: 1,
  },
  header: {
    minHeight: 82,
    paddingVertical: 18,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 12,
  },
  moduleOrder: {
    minWidth: 32,
    minHeight: 40,
    alignItems: 'center',
    justifyContent: 'center',
  },
  moduleOrderText: {
    color: Palette.textMuted,
    fontFamily: Fonts.bold,
    fontSize: 18,
  },
  headerCopy: {
    flex: 1,
    minWidth: 0,
  },
  title: {
    ...Type.bodyStrong,
    color: Palette.text,
    ...textDirection,
  },
  meta: {
    ...Type.caption,
    color: Palette.textMuted,
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
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
  },
  headerActions: {
    alignItems: 'center',
    gap: 8,
    flexShrink: 0,
    maxWidth: '30%',
  },
  progressTrack: {
    height: 2,
    marginBottom: 6,
    backgroundColor: 'rgba(255,255,255,.07)',
  },
  progressFill: {
    height: '100%',
    backgroundColor: Palette.textMuted,
  },
  lockedHint: {
    ...Type.caption,
    color: Palette.textMuted,
    marginBottom: 12,
    ...textDirection,
  },
  content: {
    paddingTop: 14,
    paddingBottom: 18,
  },
  sectionLabel: {
    ...Type.caption,
    color: Palette.textMuted,
    marginBottom: 8,
    ...textDirection,
  },
  reelsSection: {
    gap: 0,
  },
  reelRow: {
    minHeight: 72,
    paddingVertical: 14,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
  },
  lockedReelRow: {
    backgroundColor: 'transparent',
  },
  reelNumber: {
    minWidth: 32,
    minHeight: 36,
    paddingHorizontal: 3,
    alignItems: 'center',
    justifyContent: 'center',
  },
  completedReelNumber: {
    borderRadius: 10,
    backgroundColor: Palette.surface,
  },
  reelNumberText: {
    ...Type.caption,
    color: Palette.textMuted,
  },
  reelCopy: {
    flex: 1,
    minWidth: 0,
  },
  reelTitle: {
    ...Type.bodyStrong,
    color: Palette.text,
    ...textDirection,
  },
  reelMeta: {
    ...Type.caption,
    color: Palette.textMuted,
    marginTop: 4,
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
    maxWidth: '30%',
    flexShrink: 1,
    paddingVertical: 4,
  },
  lockedStepText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
  },
  projectCard: {
    direction: 'rtl',
    marginVertical: 14,
    borderRadius: 16,
    padding: 16,
    backgroundColor: Palette.surface,
  },
  lockedProjectPreview: {
    backgroundColor: Palette.surface,
  },
  lockedProjectHint: {
    ...textDirection,
    ...Type.caption,
    color: Palette.textMuted,
    marginTop: 4,
  },
  projectTopRow: {
    ...rtlRowStyle,
    justifyContent: 'space-between',
    alignItems: 'center',
    flexWrap: 'wrap',
    gap: 8,
  },
  projectBadge: {
    minHeight: 25,
    flexShrink: 1,
  },
  projectBadgeText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
  },
  passedText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.success,
  },
  projectTitle: {
    ...Type.section,
    color: Palette.text,
    marginTop: 10,
    ...textDirection,
  },
  projectRequirements: {
    ...Type.body,
    color: Palette.textMuted,
    marginTop: 5,
    ...textDirection,
  },
  submitProject: {
    minHeight: 48,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.surfacePressed,
    marginTop: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  disabledButton: {
    opacity: 0.38,
  },
  submitProjectText: {
    ...Type.bodyStrong,
    ...textDirection,
    textAlign: 'center',
    color: Palette.text,
  },
  projectPassedCopy: {
    ...Type.caption,
    color: Palette.textMuted,
    marginTop: 2,
    ...textDirection,
  },
});
