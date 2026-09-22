import {useNavigation} from '@react-navigation/native';
import React, {useEffect, useMemo, useState} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import Svg, {Path} from 'react-native-svg';
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

const StepIcon = ({
  kind,
}: {
  kind: 'lock' | 'check' | 'play' | 'project' | 'open' | 'closed';
}) => (
  <Svg width={20} height={20} viewBox="0 0 24 24" accessibilityElementsHidden>
    <Path
      d={
        {
          lock: 'M7 10V7a5 5 0 0 1 10 0v3M5 10h14v11H5zM12 14v3',
          check: 'm5 12 4 4L19 6',
          play: 'm9 5 10 7-10 7z',
          project: 'M4 6h6l2 2h8v12H4zM8 13h8M8 16h5',
          open: 'm6 15 6-6 6 6',
          closed: 'm6 9 6 6 6-6',
        }[kind]
      }
      fill="none"
      stroke={kind === 'check' ? Palette.success : Palette.textMuted}
      strokeWidth={1.6}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const projectStatus = (project: CourseProject) => {
  if (project.status === 'evaluating') return 'قيد التقييم';
  if (project.status === 'needs_changes') return 'يحتاج تعديل';
  if (project.status === 'passed') {
    return project.reportEnabled && project.reportStatus === 'queued'
      ? 'التقرير قيد التجهيز'
      : 'تم الاجتياز';
  }
  return project.isGraduationProject ? 'مشروع التخرج' : 'مشروع عبور';
};

const MapProjectCard = ({
  project,
  locked = false,
  lockHint,
  onOpen,
}: {
  project: CourseProject;
  locked?: boolean;
  lockHint?: string;
  onOpen: () => void;
}) => (
  <Pressable
    accessibilityRole="button"
    accessibilityLabel={
      formatAuthoredDisplayText(project.title) +
      ' ' +
      (locked ? 'مغلق' : projectStatus(project))
    }
    accessibilityHint={locked ? lockHint : 'فتح المشروع'}
    accessibilityState={{disabled: locked}}
    disabled={locked}
    onPress={onOpen}
    style={({pressed}) => [
      styles.row,
      styles.projectRow,
      pressed && styles.pressed,
    ]}>
    <View style={styles.leading}>
      <StepIcon kind="project" />
    </View>
    <View style={styles.copy}>
      <Text style={styles.rowTitle}>
        {formatAuthoredDisplayText(project.title)}
      </Text>
      <Text style={styles.meta}>
        {locked
          ? project.isGraduationProject
            ? 'مشروع التخرج'
            : 'مشروع عبور'
          : projectStatus(project)}
      </Text>
    </View>
    <StepIcon
      kind={locked ? 'lock' : project.status === 'passed' ? 'check' : 'play'}
    />
  </Pressable>
);

const Module = ({courseId, module, initiallyExpanded = false}: ModuleProps) => {
  const navigation = useNavigation<RootNavigation>();
  const [expanded, setExpanded] = useState(initiallyExpanded);
  const orderedSteps = useMemo(() => orderedModuleSteps(module), [module]);
  const completed = orderedSteps.filter(moduleStepIsComplete).length;
  const percentage = Math.round(
    (completed / Math.max(1, orderedSteps.length)) * 100,
  );
  const firstLocked = orderedSteps.findIndex((_, index) => {
    const gate = courseLearningGateState(module, orderedSteps, index);
    return gate === 'locked_purchase' || gate === 'locked_project';
  });
  useEffect(() => {
    if (initiallyExpanded) setExpanded(true);
  }, [initiallyExpanded]);

  return (
    <View style={styles.container}>
      <Pressable
        accessibilityRole="button"
        accessibilityState={{expanded}}
        style={styles.header}
        onPress={() => setExpanded(value => !value)}>
        <View style={styles.leading}>
          <Text style={styles.order}>
            {formatArabicDisplayText(module.order)}
          </Text>
        </View>
        <View style={styles.copy}>
          <Text style={styles.title}>
            {formatAuthoredDisplayText(module.title)}
          </Text>
          <Text style={styles.meta}>
            {formatArabicDisplayText(
              completed + ' من ' + orderedSteps.length + ' مكتمل',
            )}
          </Text>
        </View>
        {module.isLocked && <StepIcon kind="lock" />}
        <StepIcon kind={expanded ? 'open' : 'closed'} />
      </Pressable>
      <View
        accessibilityRole="progressbar"
        accessibilityValue={{min: 0, max: 100, now: percentage}}
        accessibilityLabel="تقدم الوحدة"
        style={styles.progressTrack}>
        <View style={[styles.progressFill, {width: `${percentage}%`}]} />
      </View>
      {expanded && (
        <View style={styles.content}>
          {module.isLocked && (
            <Text style={styles.lockedHint}>
              {learningGateText(module.lockReason)}
            </Text>
          )}
          {orderedSteps.map((step, stepIndex) => {
            const gateState = courseLearningGateState(
              module,
              orderedSteps,
              stepIndex,
            );
            const unavailable =
              gateState === 'locked_purchase' || gateState === 'locked_project';
            const lockHint = unavailable
              ? learningGateTextForStep(module, orderedSteps, stepIndex)
              : undefined;
            return (
              <React.Fragment
                key={
                  step.type +
                  '-' +
                  (step.type === 'project' ? step.project.id : step.reel.id)
                }>
                {!module.isLocked && stepIndex === firstLocked && (
                  <Text style={styles.lockedHint}>{lockHint}</Text>
                )}
                {step.type === 'project' ? (
                  <MapProjectCard
                    project={step.project}
                    locked={unavailable}
                    lockHint={lockHint}
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
                ) : (
                  <Pressable
                    accessibilityRole="button"
                    accessibilityState={{disabled: unavailable}}
                    accessibilityLabel={
                      formatAuthoredDisplayText(step.reel.title) +
                      (unavailable
                        ? ' مغلق'
                        : step.reel.isCompleted
                        ? ' مكتمل'
                        : '')
                    }
                    accessibilityHint={lockHint}
                    disabled={unavailable}
                    style={({pressed}) => [
                      styles.row,
                      pressed && styles.pressed,
                    ]}
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
                    <View style={styles.leading}>
                      <Text style={styles.order}>
                        {formatArabicDisplayText(step.reel.reelNumber)}
                      </Text>
                    </View>
                    <Text style={[styles.rowTitle, styles.copy]}>
                      {formatAuthoredDisplayText(step.reel.title)}
                    </Text>
                    <StepIcon
                      kind={
                        unavailable
                          ? 'lock'
                          : step.reel.isCompleted
                          ? 'check'
                          : 'play'
                      }
                    />
                  </Pressable>
                )}
              </React.Fragment>
            );
          })}
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
  },
  header: {
    ...rtlRowStyle,
    minHeight: 76,
    paddingVertical: 14,
    alignItems: 'center',
    gap: 12,
  },
  leading: {
    width: 32,
    minHeight: 36,
    alignItems: 'center',
    justifyContent: 'center',
    flexShrink: 0,
  },
  order: {...Type.caption, color: Palette.textMuted},
  copy: {flex: 1, minWidth: 0},
  title: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  rowTitle: {...Type.body, ...textDirection, color: Palette.text},
  meta: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 3,
  },
  progressTrack: {height: 2, backgroundColor: Palette.lineSoft},
  progressFill: {height: '100%', backgroundColor: Palette.primary},
  content: {paddingTop: 6, paddingBottom: 12},
  row: {
    ...rtlRowStyle,
    minHeight: 60,
    paddingVertical: 12,
    alignItems: 'center',
    gap: 10,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
  },
  projectRow: {minHeight: 72},
  lockedHint: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    paddingVertical: 12,
  },
  pressed: {opacity: 0.75},
});
