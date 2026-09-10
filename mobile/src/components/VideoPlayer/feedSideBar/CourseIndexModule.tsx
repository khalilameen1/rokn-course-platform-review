import React, {useEffect, useMemo, useState} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {
  formatArabicNumber,
  formatAuthoredDisplayText,
} from '../../../constants/arabicFormatting';
import {
  Palette,
  Radius,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../../constants/designSystem';
import {Fonts} from '../../../constants/styleConstants';
import {
  courseLearningGateState,
  learningGateTextForStep,
  orderedModuleSteps,
} from '../courseLearning/sequence';
import type {CourseLearningModule} from '../types';

const LockIcon = () => (
  <Svg width={15} height={15} viewBox="0 0 18 18">
    <Path
      d="M5.2 8V5.8a3.8 3.8 0 0 1 7.6 0V8m-8.5 0h9.4c.7 0 1.3.6 1.3 1.3v5c0 .8-.6 1.4-1.3 1.4H4.3c-.7 0-1.3-.6-1.3-1.3v-5C3 8.6 3.6 8 4.3 8Z"
      fill="none"
      stroke="rgba(255,255,255,.52)"
      strokeWidth={1.4}
      strokeLinecap="round"
    />
  </Svg>
);

const IndexChevron = ({open}: {open: boolean}) => (
  <Svg width={16} height={16} viewBox="0 0 20 20">
    <Path
      d={open ? 'm4 12 6-6 6 6' : 'm4 8 6 6 6-6'}
      fill="none"
      stroke="rgba(255,255,255,.62)"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const CourseIndexModule = ({
  module,
  currentFeedKey,
  onSelect,
}: {
  module: CourseLearningModule;
  currentFeedKey: string;
  onSelect: (key: string) => void;
}) => {
  const [expanded, setExpanded] = useState(!module.isLocked);
  const orderedSteps = useMemo(() => orderedModuleSteps(module), [module]);
  const containsCurrentItem = orderedSteps.some(step =>
    step.type === 'reel'
      ? currentFeedKey === `reel-${step.reel.id}`
      : currentFeedKey === `project-${step.project.id}`,
  );

  useEffect(() => {
    if (containsCurrentItem) setExpanded(true);
  }, [containsCurrentItem]);

  return (
    <View style={[styles.moduleCard, module.isLocked && styles.lockedModule]}>
      <Pressable
        accessibilityRole="button"
        accessibilityState={{expanded}}
        onPress={() => setExpanded(value => !value)}
        style={styles.moduleHeader}>
        <View style={styles.moduleHeading}>
          <Text style={styles.moduleTitle}>
            {formatAuthoredDisplayText(module.title)}
          </Text>
          <Text style={styles.moduleMeta}>
            {formatArabicNumber(module.reels.length)} مقطع
            {module.projects?.length
              ? ` · ${formatArabicNumber(module.projects.length)} مشروع`
              : ''}
          </Text>
        </View>
        <View style={styles.moduleHeaderActions}>
          {module.isLocked && <LockIcon />}
          <IndexChevron open={expanded} />
        </View>
      </Pressable>
      {expanded && (
        <View style={styles.reelsList}>
          {orderedSteps.map((step, stepIndex) => {
            const gateState = courseLearningGateState(
              module,
              orderedSteps,
              stepIndex,
            );
            const unavailable =
              gateState === 'locked_purchase' || gateState === 'locked_project';
            if (step.type === 'reel') {
              const reel = step.reel;
              const key = `reel-${reel.id}`;
              const active = !unavailable && currentFeedKey === key;
              return (
                <Pressable
                  key={key}
                  accessibilityRole="button"
                  accessibilityState={{disabled: unavailable}}
                  disabled={unavailable}
                  style={[
                    styles.reelRow,
                    unavailable && styles.lockedReelRow,
                    active && styles.activeReelRow,
                  ]}
                  onPress={() => onSelect(key)}>
                  <View
                    style={[
                      styles.reelNumber,
                      active && styles.activeReelNumber,
                    ]}>
                    <Text style={styles.reelNumberText}>
                      {formatArabicNumber(reel.reelNumber)}
                    </Text>
                  </View>
                  <View style={styles.reelCopy}>
                    <Text style={styles.reelTitle}>
                      {formatAuthoredDisplayText(reel.title)}
                    </Text>
                    {unavailable && (
                      <Text style={styles.projectStatus}>
                        {learningGateTextForStep(
                          module,
                          orderedSteps,
                          stepIndex,
                        )}
                      </Text>
                    )}
                  </View>
                  {reel.isCompleted && (
                    <Text allowFontScaling={false} style={styles.completedMark}>
                      ✓
                    </Text>
                  )}
                  {unavailable && <LockIcon />}
                </Pressable>
              );
            }
            const project = step.project;
            return (
              <Pressable
                key={project.id}
                accessibilityRole="button"
                accessibilityState={{disabled: unavailable}}
                disabled={unavailable}
                style={[
                  styles.projectRow,
                  unavailable && styles.projectRowDisabled,
                  currentFeedKey === `project-${project.id}` &&
                    styles.activeReelRow,
                ]}
                onPress={() => onSelect(`project-${project.id}`)}>
                <View style={styles.projectGlyph}>
                  <Text
                    allowFontScaling={false}
                    style={styles.projectGlyphText}>
                    ◆
                  </Text>
                </View>
                <View style={styles.projectCopy}>
                  <Text style={styles.projectTitle}>
                    {formatAuthoredDisplayText(project.title)}
                  </Text>
                  <Text style={styles.projectStatus}>
                    {unavailable
                      ? learningGateTextForStep(module, orderedSteps, stepIndex)
                      : project.status === 'passed'
                      ? 'تم العبور'
                      : project.status === 'evaluating'
                      ? 'قيد المراجعة'
                      : project.status === 'needs_changes'
                      ? 'يحتاج إلى تعديل'
                      : 'افتح المشروع'}
                  </Text>
                </View>
                {unavailable && <LockIcon />}
              </Pressable>
            );
          })}
        </View>
      )}
    </View>
  );
};

export default CourseIndexModule;

const styles = StyleSheet.create({
  moduleCard: {
    paddingBottom: 14,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
  },
  lockedModule: {opacity: 1},
  moduleHeader: {
    minHeight: 76,
    paddingVertical: 18,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
  },
  moduleHeading: {flex: 1, minWidth: 0},
  moduleHeaderActions: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 9,
    flexShrink: 0,
  },
  moduleTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
  },
  moduleMeta: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 3,
  },
  reelsList: {gap: 4},
  reelRow: {
    minHeight: 72,
    borderRadius: Radius.md,
    paddingHorizontal: 10,
    paddingVertical: 14,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
  },
  lockedReelRow: {opacity: 1},
  activeReelRow: {backgroundColor: Palette.surfacePressed},
  reelNumber: {
    minWidth: 36,
    minHeight: 36,
    borderRadius: Radius.sm,
    paddingHorizontal: 6,
    paddingVertical: 4,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.surface,
  },
  activeReelNumber: {backgroundColor: Palette.primary},
  reelNumberText: {
    ...Type.caption,
    fontFamily: Fonts.semiBold,
    color: Palette.text,
    fontVariant: ['tabular-nums'],
  },
  reelTitle: {
    ...Type.body,
    ...textDirection,
    color: Palette.text,
  },
  reelCopy: {flex: 1, minWidth: 0},
  completedMark: {color: '#67D39B', fontFamily: Fonts.bold, fontSize: 15},
  projectRow: {
    minHeight: 76,
    borderRadius: Radius.md,
    paddingHorizontal: 10,
    paddingVertical: 14,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
    marginTop: 8,
    backgroundColor: Palette.surface,
  },
  projectRowDisabled: {opacity: 1},
  projectGlyph: {
    width: 34,
    height: 34,
    borderRadius: 11,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.surfacePressed,
  },
  projectGlyphText: {color: Palette.textMuted, fontSize: 13},
  projectCopy: {flex: 1, minWidth: 0},
  projectTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
  },
  projectStatus: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 4,
  },
});
