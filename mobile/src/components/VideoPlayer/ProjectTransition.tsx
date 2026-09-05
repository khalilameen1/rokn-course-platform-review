import React, {useEffect, useState} from 'react';
import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {Fonts} from '../../constants/styleConstants';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';
import type {ProjectSubmissionOutcome} from './courseLearningApi';
import type {CourseProject, SelectedProjectFile} from './types';
import ProjectFeedbackPanel from './projectTransition/ProjectFeedbackPanel';
import ProjectSubmissionEditor from './projectTransition/ProjectSubmissionEditor';
import {useProjectTransitionController} from './projectTransition/useProjectTransitionController';

interface ProjectTransitionProps {
  active: boolean;
  project: CourseProject;
  moduleTitle: string;
  width: number;
  height: number;
  topInset?: number;
  bottomInset?: number;
  onSubmit: (
    files: SelectedProjectFile[],
    note?: string,
  ) => Promise<ProjectSubmissionOutcome>;
  onContinue?: () => void;
}

type StatusTone = 'progress' | 'success' | 'danger';

const StatusHeading = ({
  busy = false,
  description,
  title,
  tone,
}: {
  busy?: boolean;
  description: string;
  title: string;
  tone: StatusTone;
}) => {
  const color =
    tone === 'success'
      ? Palette.success
      : tone === 'danger'
      ? Palette.danger
      : Palette.primary;

  return (
    <View style={styles.statusHeading}>
      <View
        style={[
          styles.statusMark,
          tone === 'success' && styles.successMark,
          tone === 'danger' && styles.dangerMark,
          tone === 'progress' && styles.progressMark,
        ]}>
        {busy ? (
          <ActivityIndicator color={color} size="small" />
        ) : (
          <Text style={[styles.statusSymbol, {color}]}>
            {tone === 'success' ? '✓' : '!'}
          </Text>
        )}
      </View>
      <View style={styles.statusCopy}>
        <Text accessibilityRole="header" style={styles.statusTitle}>
          {title}
        </Text>
        <Text style={styles.statusDescription}>{description}</Text>
      </View>
    </View>
  );
};

const ProjectBrief = ({
  expanded,
  onToggle,
  project,
}: {
  expanded: boolean;
  onToggle: () => void;
  project: CourseProject;
}) => (
  <View style={styles.brief}>
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={
        expanded ? 'إخفاء تفاصيل المشروع' : 'عرض تفاصيل المشروع'
      }
      accessibilityState={{expanded}}
      onPress={onToggle}
      style={styles.briefToggle}>
      <Text style={styles.briefToggleText}>تفاصيل المشروع</Text>
      <Text accessibilityElementsHidden style={styles.briefToggleSymbol}>
        {expanded ? '−' : '+'}
      </Text>
    </Pressable>
    {expanded && (
      <View style={styles.briefBody}>
        <Text style={styles.briefTitle}>
          {formatAuthoredDisplayText(project.title)}
        </Text>
        <Text style={styles.briefRequirements}>
          {formatAuthoredDisplayText(project.requirements)}
        </Text>
      </View>
    )}
  </View>
);

const ProjectTransition = ({
  active,
  project,
  moduleTitle,
  width,
  height,
  topInset = 0,
  bottomInset = 0,
  onSubmit,
  onContinue,
}: ProjectTransitionProps) => {
  const navigation = useNavigation<RootNavigation>();
  const [briefExpanded, setBriefExpanded] = useState(false);
  const controller = useProjectTransitionController({
    active,
    project,
    onSubmit,
  });

  useEffect(() => {
    setBriefExpanded(false);
  }, [project.id]);

  const hasInterruptedReport =
    ['failed', 'failed_retryable'].includes(controller.reportViewState) &&
    controller.feedbackThread?.messages.some(
      message => message.role === 'assistant' && Boolean(message.text?.trim()),
    );
  const feedbackPanel =
    (controller.reportViewState === 'ready' || hasInterruptedReport) &&
    controller.feedbackThread ? (
      <ProjectFeedbackPanel
        attachments={controller.feedbackAttachments}
        canReply={controller.canReplyToFeedback}
        draft={controller.feedbackDraft}
        error={controller.feedbackError}
        feedbackLevel={controller.feedbackLevel}
        normalizedDraft={controller.normalizedFeedbackDraft}
        pending={controller.feedbackPending}
        projectId={project.id}
        sending={controller.feedbackSending}
        thread={controller.feedbackThread}
        onChangeDraft={controller.changeFeedbackDraft}
        onPickAttachments={() => void controller.pickFeedbackAttachments()}
        onRemoveAttachment={controller.removeFeedbackAttachment}
        onRetryMessage={message =>
          void controller.retryFeedbackMessage(message)
        }
        onSend={() => void controller.sendFeedback()}
      />
    ) : null;
  const canContinue = controller.canContinue && Boolean(onContinue);
  const showProjectBrief = controller.journeyState !== 'draft';

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
      keyboardVerticalOffset={topInset}
      style={[styles.page, {width, height}]}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="العودة"
        hitSlop={8}
        style={[styles.backButton, {top: topInset + Spacing.xs}]}
        onPress={() => goBackOrHome(navigation)}>
        <Text style={styles.backSymbol}>›</Text>
      </Pressable>
      <ScrollView
        keyboardDismissMode="on-drag"
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
        contentContainerStyle={[
          styles.content,
          {
            paddingTop: topInset + 72,
            paddingBottom: bottomInset + Spacing.section,
          },
        ]}>
        <View style={styles.context}>
          <Text style={styles.projectKind}>
            {project.isGraduationProject ? 'مشروع التخرج' : 'مشروع العبور'}
          </Text>
          {!!moduleTitle && (
            <Text style={styles.moduleTitle}>
              {formatAuthoredDisplayText(moduleTitle)}
            </Text>
          )}
        </View>

        {controller.journeyState === 'draft' && (
          <View style={styles.instructions}>
            <Text accessibilityRole="header" style={styles.projectTitle}>
              {formatAuthoredDisplayText(project.title)}
            </Text>
            <Text style={styles.projectRequirements}>
              {formatAuthoredDisplayText(project.requirements)}
            </Text>
          </View>
        )}

        <View
          style={[
            styles.lifecycle,
            controller.journeyState === 'draft' && styles.draftLifecycle,
          ]}>
          {controller.journeyState === 'passed' ? (
            <>
              <StatusHeading
                description={
                  canContinue
                    ? 'فتحنا لك المقطع التالي'
                    : 'تم اعتماد النتيجة وحفظ تقدمك'
                }
                title="تم اعتماد مشروعك"
                tone="success"
              />
              {!!controller.syncNote && (
                <Text style={styles.syncNote}>{controller.syncNote}</Text>
              )}

              {(canContinue || project.outputEnabled) && (
                <View style={styles.actionGroup}>
                  {canContinue && (
                    <Pressable
                      accessibilityRole="button"
                      accessibilityLabel="أكمل الكورس"
                      style={styles.primaryButton}
                      onPress={onContinue!}>
                      <Text style={styles.primaryButtonText}>أكمل الكورس</Text>
                    </Pressable>
                  )}
                  {project.outputEnabled && (
                    <Pressable
                      accessibilityRole="button"
                      accessibilityLabel="أضف مشروعك إلى البورتفوليو"
                      style={styles.secondaryButton}
                      onPress={() =>
                        navigation.navigate('Profile', {tab: 'portfolio'})
                      }>
                      <Text style={styles.secondaryButtonText}>
                        أضف مشروعك إلى البورتفوليو
                      </Text>
                    </Pressable>
                  )}
                </View>
              )}

              {(controller.reportViewState === 'preparing' ||
                controller.reportViewState === 'loading') && (
                <View style={styles.reportLoading}>
                  <ActivityIndicator color={Palette.primary} size="small" />
                  <Text style={styles.reportState}>
                    {controller.reportViewState === 'preparing'
                      ? 'نجهّز تقرير مشروعك'
                      : 'نحمّل تقرير مشروعك'}
                  </Text>
                </View>
              )}
              {controller.reportViewState === 'failed_retryable' && (
                <Pressable
                  accessibilityRole="button"
                  accessibilityState={{disabled: controller.reportRetrying}}
                  disabled={controller.reportRetrying}
                  onPress={() => void controller.retryReport()}
                  style={styles.reportRetry}>
                  <Text style={styles.reportRetryText}>
                    {controller.reportRetrying
                      ? 'نحاول الآن'
                      : 'تعذّر تجهيز التقرير  حاول مرة أخرى'}
                  </Text>
                </Pressable>
              )}
              {controller.reportViewState === 'failed' && (
                <Text style={styles.reportError}>تعذّر تجهيز التقرير</Text>
              )}
              {feedbackPanel && (
                <View style={styles.reportSection}>{feedbackPanel}</View>
              )}
            </>
          ) : controller.journeyState === 'submitting' ? (
            <StatusHeading
              busy
              description="نحفظ الملفات الآن"
              title="نسلّم مشروعك"
              tone="progress"
            />
          ) : controller.journeyState === 'reviewing' ? (
            <>
              <StatusHeading
                busy
                description="سنحدّث النتيجة هنا"
                title="مشروعك محفوظ"
                tone="progress"
              />
              {!!controller.syncNote && (
                <Text style={styles.syncNote}>{controller.syncNote}</Text>
              )}
            </>
          ) : controller.journeyState === 'needs_changes' ? (
            <>
              <StatusHeading
                description="راجع الملاحظات ثم أرسل من جديد"
                title="يحتاج المشروع إلى تعديل"
                tone="danger"
              />
              {!!controller.reviewFeedback && (
                <View style={styles.reviewFeedback}>
                  <Text style={styles.reviewFeedbackText}>
                    {formatAuthoredDisplayText(controller.reviewFeedback)}
                  </Text>
                </View>
              )}
              {feedbackPanel && (
                <View style={styles.reportSection}>{feedbackPanel}</View>
              )}
              <View style={styles.actionGroup}>
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="عدّل التسليم"
                  accessibilityState={{disabled: !controller.submissionAllowed}}
                  disabled={!controller.submissionAllowed}
                  onPress={controller.editRetry}
                  style={[
                    styles.primaryButton,
                    !controller.submissionAllowed && styles.disabledButton,
                  ]}>
                  <Text style={styles.primaryButtonText}>عدّل التسليم</Text>
                </Pressable>
              </View>
            </>
          ) : controller.journeyState === 'details' ? (
            <StatusHeading
              busy
              description="نجهّز بيانات التسليم"
              title="نحمّل المشروع"
              tone="progress"
            />
          ) : (
            <ProjectSubmissionEditor
              draftSaveError={controller.submissionDraftSaveError}
              fileSubmissionEnabled={controller.fileSubmissionEnabled}
              filePickerDisabled={controller.filePickerDisabled}
              fileTypesLabel={controller.fileTypesLabel}
              maximumFiles={controller.submissionMaximumFiles}
              note={controller.submissionNote}
              selectedFiles={controller.selectedFiles}
              sending={controller.submissionSending}
              submitDisabled={controller.submitDisabled}
              textSubmissionEnabled={controller.textSubmissionEnabled}
              onChangeNote={controller.changeSubmissionNote}
              onChooseFile={() => void controller.chooseProjectFile()}
              onRemoveFile={controller.removeSubmissionFile}
              onSubmit={() => void controller.submit()}
            />
          )}
        </View>

        {showProjectBrief && (
          <ProjectBrief
            expanded={briefExpanded}
            project={project}
            onToggle={() => setBriefExpanded(value => !value)}
          />
        )}
      </ScrollView>
    </KeyboardAvoidingView>
  );
};

export default ProjectTransition;
export {
  pickMedia,
  pickProjectFiles,
  pickProjectFilesOwned,
} from './projectTransition/pickers';

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: Palette.canvas,
  },
  backButton: {
    position: 'absolute',
    start: Spacing.md,
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    borderRadius: Radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.canvasSoft,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    zIndex: 20,
  },
  backSymbol: {
    color: Palette.text,
    fontFamily: Fonts.regular,
    fontSize: 35,
    lineHeight: 38,
    marginBottom: 3,
  },
  content: {
    direction: 'rtl',
    flexGrow: 1,
    width: '100%',
    maxWidth: 700,
    alignSelf: 'center',
    paddingHorizontal: Spacing.xl,
  },
  context: {
    alignItems: 'flex-start',
  },
  projectKind: {
    ...textDirection,
    ...Type.bodyStrong,
    color: Palette.primary,
  },
  moduleTitle: {
    ...textDirection,
    ...Type.caption,
    color: Palette.textMuted,
    marginTop: 2,
  },
  instructions: {
    marginTop: Spacing.xl,
  },
  projectTitle: {
    ...textDirection,
    ...Type.title,
    color: Palette.text,
  },
  projectRequirements: {
    ...textDirection,
    ...Type.body,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  lifecycle: {
    width: '100%',
    marginTop: Spacing.xl,
  },
  draftLifecycle: {
    marginTop: 0,
  },
  statusHeading: {
    ...rtlRowStyle,
    width: '100%',
    alignItems: 'flex-start',
    gap: Spacing.sm,
  },
  statusMark: {
    width: 38,
    height: 38,
    borderRadius: Radius.sm,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
  },
  successMark: {
    backgroundColor: 'rgba(72,185,138,.10)',
    borderColor: 'rgba(72,185,138,.28)',
  },
  dangerMark: {
    backgroundColor: 'rgba(240,100,105,.10)',
    borderColor: 'rgba(240,100,105,.28)',
  },
  progressMark: {
    backgroundColor: Palette.primarySoft,
    borderColor: 'rgba(52,120,246,.28)',
  },
  statusSymbol: {
    fontFamily: Fonts.bold,
    fontSize: 19,
  },
  statusCopy: {
    flex: 1,
    minWidth: 0,
    alignItems: 'flex-start',
  },
  statusTitle: {
    ...textDirection,
    ...Type.section,
    color: Palette.text,
  },
  statusDescription: {
    ...textDirection,
    ...Type.body,
    color: Palette.textMuted,
    marginTop: 2,
  },
  syncNote: {
    ...textDirection,
    ...Type.caption,
    color: Palette.primary,
    marginTop: Spacing.sm,
  },
  reportSection: {
    width: '100%',
    alignSelf: 'stretch',
    marginTop: Spacing.lg,
  },
  reportLoading: {
    ...rtlRowStyle,
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    gap: Spacing.sm,
    marginTop: Spacing.md,
  },
  reportState: {
    flexShrink: 1,
    ...textDirection,
    ...Type.body,
    color: Palette.textMuted,
  },
  reportError: {
    ...textDirection,
    ...Type.body,
    color: Palette.danger,
    marginTop: Spacing.md,
  },
  reportRetry: {
    minHeight: Accessibility.minTouchTarget,
    alignSelf: 'flex-start',
    justifyContent: 'center',
    marginTop: Spacing.sm,
  },
  reportRetryText: {
    ...textDirection,
    ...Type.bodyStrong,
    color: Palette.primary,
  },
  reviewFeedback: {
    width: '100%',
    padding: Spacing.md,
    borderRadius: Radius.md,
    backgroundColor: Palette.surface,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    marginTop: Spacing.lg,
  },
  reviewFeedbackText: {
    ...textDirection,
    ...Type.body,
    color: Palette.text,
  },
  actionGroup: {
    width: '100%',
    gap: Spacing.sm,
    marginTop: Spacing.xl,
  },
  primaryButton: {
    width: '100%',
    minHeight: 52,
    borderRadius: Radius.md,
    paddingHorizontal: Spacing.lg,
    paddingVertical: Spacing.sm,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.primary,
  },
  disabledButton: {
    opacity: 0.38,
  },
  primaryButtonText: {
    ...Type.button,
    color: Palette.text,
    textAlign: 'center',
  },
  secondaryButton: {
    width: '100%',
    minHeight: Accessibility.minTouchTarget,
    borderRadius: Radius.md,
    paddingHorizontal: Spacing.lg,
    paddingVertical: Spacing.sm,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: Palette.line,
  },
  secondaryButtonText: {
    ...Type.bodyStrong,
    color: Palette.text,
    textAlign: 'center',
  },
  brief: {
    width: '100%',
    marginTop: Spacing.xl,
    borderTopWidth: 1,
    borderTopColor: Palette.lineSoft,
  },
  briefToggle: {
    ...rtlRowStyle,
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  briefToggleText: {
    ...textDirection,
    ...Type.bodyStrong,
    color: Palette.textMuted,
  },
  briefToggleSymbol: {
    color: Palette.textMuted,
    fontFamily: Fonts.medium,
    fontSize: 22,
  },
  briefBody: {
    paddingBottom: Spacing.sm,
  },
  briefTitle: {
    ...textDirection,
    ...Type.section,
    color: Palette.text,
  },
  briefRequirements: {
    ...textDirection,
    ...Type.body,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
});
