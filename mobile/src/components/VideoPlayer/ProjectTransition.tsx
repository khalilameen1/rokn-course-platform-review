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
import {ArrowRight} from '../../assets/SVG';
import {
  Accessibility,
  fixedIconSlot,
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
import type {ProjectResolution} from './courseLearning/projectRemote';
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
  onReviewResolution?: (resolution: ProjectResolution) => void;
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
          <Text allowFontScaling={false} style={[styles.statusSymbol, {color}]}>
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
      <Text
        allowFontScaling={false}
        accessibilityElementsHidden
        style={styles.briefToggleSymbol}>
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
  onReviewResolution,
}: ProjectTransitionProps) => {
  const navigation = useNavigation<RootNavigation>();
  const [briefExpanded, setBriefExpanded] = useState(false);
  const controller = useProjectTransitionController({
    active,
    project,
    onSubmit,
    onReviewResolution,
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
        draftRestoreError={controller.feedbackDraftRestoreError}
        onRetryDraftRestore={controller.retryFeedbackDraftRestore}
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
  const showSubmitAction =
    controller.journeyState === 'draft' &&
    !controller.submissionRevisionMessage;
  const showContinueAction =
    controller.journeyState === 'passed' && canContinue;
  const showEditAction = controller.journeyState === 'needs_changes';

  return (
    <KeyboardAvoidingView
      // Padding preserves the measured page height instead of KAV's initial frame.
      // Android starts edge-to-edge at y=0; safe-area spacing is inside this page.
      behavior="padding"
      keyboardVerticalOffset={Platform.OS === 'ios' ? topInset : 0}
      style={[styles.page, {width, height}]}>
      <View style={[styles.navigation, {paddingTop: topInset + 6}]}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="العودة"
          style={styles.backButton}
          onPress={() => goBackOrHome(navigation)}>
          <ArrowRight accessible={false} />
        </Pressable>
        <Text style={styles.navigationTitle}>المشروع</Text>
      </View>
      <ScrollView
        style={styles.scroll}
        keyboardDismissMode="on-drag"
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
        contentContainerStyle={[
          styles.content,
          {
            paddingTop: Spacing.md,
            paddingBottom:
              showSubmitAction || showContinueAction || showEditAction
                ? Spacing.section
                : bottomInset + Spacing.section,
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

              {project.outputEnabled && (
                <View style={styles.actionGroup}>
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
          ) : controller.journeyState === 'review_unavailable' ? (
            <>
              <StatusHeading
                description="تسليمك محفوظ ولم تكتمل مراجعته"
                title="تعذّرت مراجعة المشروع"
                tone="progress"
              />
              {!!controller.reviewRecoveryError && (
                <Text style={styles.reportError}>
                  {controller.reviewRecoveryError}
                </Text>
              )}
              {(controller.reviewRetryAvailable ||
                controller.reviewRecoveryRequired) && (
                <Pressable
                  accessibilityRole="button"
                  accessibilityState={{disabled: controller.reviewRetrying}}
                  disabled={controller.reviewRetrying}
                  style={[
                    styles.primaryButton,
                    controller.reviewRetrying && styles.disabledButton,
                  ]}
                  onPress={() => void controller.retryReview()}>
                  <Text style={styles.primaryButtonText}>
                    {controller.reviewRetrying
                      ? 'نحدّث المراجعة'
                      : controller.reviewRecoveryRequired
                      ? 'تحديث حالة المراجعة'
                      : 'إعادة المراجعة'}
                  </Text>
                </Pressable>
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
            </>
          ) : controller.journeyState === 'details' ? (
            <>
              <StatusHeading
                busy={!controller.submissionDraftRestoreError}
                description={
                  controller.submissionDraftRestoreError
                    ? 'حاول مرة أخرى لاستعادة النص والملفات'
                    : 'نجهّز بيانات التسليم'
                }
                title={
                  controller.submissionDraftRestoreError
                    ? 'تعذّر استعادة المسودة'
                    : 'نحمّل المشروع'
                }
                tone="progress"
              />
              {controller.submissionDraftRestoreError && (
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="إعادة استعادة مسودة المشروع"
                  style={styles.primaryButton}
                  onPress={controller.retrySubmissionDraftRestore}>
                  <Text style={styles.primaryButtonText}>إعادة المحاولة</Text>
                </Pressable>
              )}
            </>
          ) : (
            <ProjectSubmissionEditor
              showSubmitAction={false}
              draftSaveError={controller.submissionDraftSaveError}
              revisionMessage={controller.submissionRevisionMessage}
              revisionUpdating={controller.submissionRevisionUpdating}
              canReviewUpdatedProject={controller.canReviewUpdatedProject}
              revisionActionLabel={controller.revisionActionLabel}
              onReviewUpdatedProject={() =>
                void controller.reviewUpdatedProject()
              }
              draftCompatibilityMessage={controller.draftCompatibilityMessage}
              fileSubmissionEnabled={controller.fileSubmissionEnabled}
              filePickerDisabled={controller.filePickerDisabled}
              fileTypesLabel={controller.fileTypesLabel}
              maximumFiles={controller.submissionMaximumFiles}
              maximumFileSizeLabel={controller.submissionMaximumFileSizeLabel}
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
      {(showSubmitAction || showContinueAction || showEditAction) && (
        <View
          style={[styles.footer, {paddingBottom: Math.max(bottomInset, 12)}]}>
          <View style={styles.footerContent}>
            {showSubmitAction && (
              <Pressable
                accessibilityRole="button"
                accessibilityState={{
                  busy: controller.submissionSending,
                  disabled: controller.submitDisabled,
                }}
                disabled={controller.submitDisabled}
                onPress={() => void controller.submit()}
                style={[
                  styles.primaryButton,
                  controller.submitDisabled && styles.disabledButton,
                ]}>
                <Text style={styles.primaryButtonText}>
                  {controller.submissionSending
                    ? 'جارٍ التسليم'
                    : 'سلّم المشروع'}
                </Text>
              </Pressable>
            )}
            {showContinueAction && (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel="أكمل الكورس"
                style={styles.primaryButton}
                onPress={onContinue!}>
                <Text style={styles.primaryButtonText}>أكمل الكورس</Text>
              </Pressable>
            )}
            {showEditAction && (
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
            )}
          </View>
        </View>
      )}
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
  navigation: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: Spacing.xl,
    paddingBottom: 8,
    backgroundColor: Palette.canvas,
  },
  navigationTitle: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    flex: 1,
  },
  scroll: {flex: 1},
  footer: {
    paddingTop: 12,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.lineSoft,
    backgroundColor: Palette.canvas,
  },
  footerContent: {
    width: '100%',
    maxWidth: 700,
    alignSelf: 'center',
    paddingHorizontal: Spacing.xl,
  },
  backButton: {
    ...fixedIconSlot,
    borderRadius: Radius.md,
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
    color: Palette.textMuted,
  },
  moduleTitle: {
    ...textDirection,
    ...Type.caption,
    color: Palette.textMuted,
    marginTop: 2,
  },
  instructions: {
    marginTop: Spacing.lg,
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
    width: 48,
    height: 48,
    borderRadius: Radius.sm,
    alignItems: 'center',
    justifyContent: 'center',
  },
  successMark: {
    backgroundColor: 'rgba(72,185,138,.10)',
  },
  dangerMark: {
    backgroundColor: 'rgba(240,100,105,.10)',
  },
  progressMark: {
    backgroundColor: Palette.surface,
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
    ...Type.title,
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
    color: Palette.textMuted,
    marginTop: Spacing.sm,
  },
  reportSection: {
    width: '100%',
    alignSelf: 'stretch',
    marginTop: Spacing.xl,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.lineSoft,
    paddingTop: Spacing.lg,
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
    color: Palette.text,
  },
  reviewFeedback: {
    width: '100%',
    paddingVertical: Spacing.md,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.lineSoft,
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
    minHeight: 56,
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
    ...textDirection,
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
    backgroundColor: Palette.surface,
  },
  secondaryButtonText: {
    ...Type.bodyStrong,
    ...textDirection,
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
    flexShrink: 1,
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
