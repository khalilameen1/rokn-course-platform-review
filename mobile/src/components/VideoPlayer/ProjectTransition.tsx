import React from 'react';
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
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';
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
  const controller = useProjectTransitionController({
    active,
    project,
    onSubmit,
  });

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

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      keyboardVerticalOffset={topInset}
      style={[styles.page, {width, height}]}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="العودة"
        hitSlop={10}
        style={[styles.backButton, {top: topInset + 8}]}
        onPress={() => goBackOrHome(navigation)}>
        <Text style={styles.backSymbol}>›</Text>
      </Pressable>
      <ScrollView
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
        contentContainerStyle={[
          styles.content,
          {paddingTop: topInset + 36, paddingBottom: bottomInset + 38},
        ]}>
        <View style={styles.eyebrowRow}>
          <View style={styles.eyebrowLine} />
          <Text style={styles.eyebrow}>حان وقت التطبيق</Text>
        </View>
        <Text style={styles.moduleTitle}>
          {formatArabicDisplayText(moduleTitle)}
        </Text>

        <View style={styles.card}>
          <View style={styles.projectBadge}>
            <Text style={styles.projectBadgeText}>
              {project.isGraduationProject ? 'مشروع التخرج' : 'مشروع العبور'}
            </Text>
          </View>
          <Text style={styles.title}>
            {formatArabicDisplayText(project.title)}
          </Text>
          <Text style={styles.requirements}>
            {formatArabicDisplayText(project.requirements)}
          </Text>

          {controller.journeyState === 'passed' ? (
            <View style={styles.successState}>
              <View style={styles.successIcon}>
                <Text style={styles.successCheck}>✓</Text>
              </View>
              <Text style={styles.successTitle}>تم اعتماد مشروعك</Text>
              <Text style={styles.successDescription}>
                {canContinue
                  ? 'فتحنا لك المقطع التالي'
                  : 'تم اعتماد النتيجة وحفظ تقدمك'}
              </Text>
              {!!controller.syncNote && (
                <Text style={styles.syncNote}>{controller.syncNote}</Text>
              )}
              {canContinue && (
                <Pressable
                  accessibilityRole="button"
                  style={styles.primaryButton}
                  onPress={onContinue!}>
                  <Text style={styles.primaryButtonText}>أكمل الكورس</Text>
                </Pressable>
              )}
              {project.outputEnabled && (
                <Pressable
                  accessibilityRole="button"
                  style={styles.portfolioButton}
                  onPress={() =>
                    navigation.navigate('Profile', {tab: 'portfolio'})
                  }>
                  <Text style={styles.portfolioButtonText}>
                    أضف مشروعك إلى البورتفوليو
                  </Text>
                </Pressable>
              )}
              {controller.reportViewState === 'preparing' && (
                <Text style={styles.syncNote}>نجهّز تقرير مشروعك</Text>
              )}
              {controller.reportViewState === 'loading' && (
                <Text style={styles.syncNote}>نحمّل تقرير مشروعك</Text>
              )}
              {controller.reportViewState === 'failed_retryable' && (
                <Pressable
                  accessibilityRole="button"
                  accessibilityState={{disabled: controller.reportRetrying}}
                  disabled={controller.reportRetrying}
                  onPress={() => void controller.retryReport()}>
                  <Text style={styles.feedbackRetry}>
                    {controller.reportRetrying
                      ? 'نحاول الآن'
                      : 'تعذّر تجهيز التقرير\nحاول مرة أخرى'}
                  </Text>
                </Pressable>
              )}
              {controller.reportViewState === 'failed' && (
                <Text style={styles.feedbackState}>تعذّر تجهيز التقرير</Text>
              )}
              {feedbackPanel}
            </View>
          ) : controller.journeyState === 'submitting' ? (
            <View style={styles.reviewState}>
              <View style={styles.reviewLoader}>
                <ActivityIndicator color="#76A9FF" size="large" />
              </View>
              <Text style={styles.reviewTitle}>نسلّم مشروعك</Text>
              <Text style={styles.reviewDescription}>نحفظ الملفات الآن</Text>
            </View>
          ) : controller.journeyState === 'reviewing' ? (
            <View style={styles.reviewState}>
              <View style={styles.reviewLoader}>
                <ActivityIndicator color="#76A9FF" size="large" />
              </View>
              <Text style={styles.reviewTitle}>مشروعك محفوظ</Text>
              <Text style={styles.reviewDescription}>سنحدّث النتيجة هنا</Text>
              {!!controller.syncNote && (
                <Text style={styles.syncNote}>{controller.syncNote}</Text>
              )}
            </View>
          ) : controller.journeyState === 'needs_changes' ? (
            <View style={styles.reviewState}>
              <Text style={styles.reviewTitle}>يحتاج المشروع إلى تعديل</Text>
              <Text style={styles.reviewDescription}>
                راجع الملاحظات ثم أرسل من جديد
              </Text>
              {!!controller.reviewFeedback && (
                <Text style={styles.reviewDescription}>
                  {formatArabicDisplayText(controller.reviewFeedback)}
                </Text>
              )}
              {feedbackPanel}
              <Pressable
                accessibilityRole="button"
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
          ) : controller.journeyState === 'details' ? (
            <View style={styles.reviewState}>
              <ActivityIndicator color="#76A9FF" size="small" />
              <Text style={styles.reviewDescription}>نحمّل مسودتك</Text>
            </View>
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
  page: {backgroundColor: '#070B11'},
  backButton: {
    position: 'absolute',
    start: 12,
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(5,9,14,.72)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.12)',
    zIndex: 20,
  },
  backSymbol: {
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 35,
    lineHeight: 37,
    marginBottom: 3,
  },
  content: {
    direction: 'rtl',
    flexGrow: 1,
    width: '100%',
    maxWidth: 700,
    alignSelf: 'center',
    paddingHorizontal: 18,
    justifyContent: 'center',
  },
  eyebrowRow: {...rtlRowStyle, alignItems: 'center', gap: 8},
  eyebrowLine: {
    width: 24,
    height: 2,
    borderRadius: 1,
    backgroundColor: '#4B8EF7',
  },
  eyebrow: {
    ...textDirection,
    color: '#76A9FF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  moduleTitle: {
    ...textDirection,
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.medium,
    fontSize: 13,
    marginTop: 7,
    marginBottom: 18,
  },
  card: {
    direction: 'rtl',
    borderRadius: 26,
    padding: 20,
    backgroundColor: '#111923',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  projectBadge: {
    alignSelf: 'flex-start',
    minHeight: 27,
    paddingHorizontal: 11,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(75,142,247,.14)',
    borderWidth: 1,
    borderColor: 'rgba(91,153,251,.25)',
  },
  projectBadgeText: {
    ...textDirection,
    color: '#8BB6FA',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  title: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 23,
    lineHeight: 35,
    marginTop: 13,
  },
  requirements: {
    ...textDirection,
    color: 'rgba(255,255,255,.72)',
    fontFamily: Fonts.regular,
    fontSize: 14,
    lineHeight: 24,
    marginTop: 7,
  },
  primaryButton: {
    width: '100%',
    minHeight: 50,
    borderRadius: 17,
    paddingHorizontal: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
  },
  disabledButton: {opacity: 0.38},
  primaryButtonText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 14,
  },
  portfolioButton: {
    width: '100%',
    minHeight: 48,
    borderRadius: 17,
    paddingHorizontal: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(118,169,255,.1)',
    borderWidth: 1,
    borderColor: 'rgba(118,169,255,.24)',
  },
  portfolioButtonText: {
    color: '#AFCBFF',
    fontFamily: Fonts.semiBold,
    fontSize: 13,
  },
  reviewState: {
    minHeight: 190,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 18,
  },
  reviewLoader: {
    width: 66,
    height: 66,
    borderRadius: 33,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(52,120,246,.10)',
    borderWidth: 1,
    borderColor: 'rgba(118,169,255,.24)',
  },
  reviewTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 17,
    marginTop: 15,
    textAlign: 'center',
  },
  reviewDescription: {
    direction: 'rtl',
    writingDirection: 'rtl',
    color: 'rgba(255,255,255,.55)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    marginTop: 4,
    textAlign: 'center',
  },
  successState: {
    minHeight: 215,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 18,
  },
  successIcon: {
    width: 54,
    height: 54,
    borderRadius: 27,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(70,196,135,.15)',
    borderWidth: 1,
    borderColor: 'rgba(90,218,156,.3)',
  },
  successCheck: {color: '#67D39B', fontFamily: Fonts.bold, fontSize: 25},
  successTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 18,
    marginTop: 12,
    textAlign: 'center',
  },
  successDescription: {
    direction: 'rtl',
    writingDirection: 'rtl',
    color: 'rgba(255,255,255,.56)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    marginTop: 3,
    textAlign: 'center',
  },
  syncNote: {
    direction: 'rtl',
    writingDirection: 'rtl',
    color: '#8BB6FA',
    fontFamily: Fonts.regular,
    fontSize: 10,
    lineHeight: 16,
    textAlign: 'center',
    marginTop: 8,
  },
  feedbackState: {
    ...textDirection,
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.regular,
    fontSize: 10,
  },
  feedbackRetry: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 10,
    marginTop: 4,
  },
});
