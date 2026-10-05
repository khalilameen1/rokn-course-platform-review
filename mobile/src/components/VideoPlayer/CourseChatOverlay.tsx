import React, {useEffect, useRef, useState} from 'react';
import {
  Alert,
  Dimensions,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  Text,
  useWindowDimensions,
  View,
} from 'react-native';
import {
  SafeAreaListener,
  useSafeAreaInsets,
} from 'react-native-safe-area-context';
import {useCourseChat} from './courseChat/useCourseChat';
import type {CourseLearningData, CourseReel} from './types';
import type {AssistantPresence} from './courseChat/useCourseChat';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {cleanUnicodeText, truncateGraphemes} from '../../utils/unicodeText';
import {removeLearnerDraftFile} from '../../services/learnerDraftFiles';
import {openCourseAssistantAttachment} from './courseLearningApi';
import {courseChatStyles as styles} from './courseChat/styles';
import {CourseChatGate} from './courseChat/CourseChatGate';
import FullTrackUpgradeSheet from '../FullTrackUpgradeSheet';
import {CourseChatConversation} from './courseChat/CourseChatConversation';
import {courseAssistantEntryMode} from './courseEntitlements';
import {courseChatSheetLayout} from './courseChat/layout';
import {StatusView} from '../ui/PremiumUI';

interface CourseChatOverlayProps {
  visible: boolean;
  course: CourseLearningData;
  reel?: CourseReel;
  onClose: () => void;
  onEntitlementChanged: () => void | Promise<void>;
  onOpenCourseAccess: () => void;
}

const presenceLabel = (presence: AssistantPresence): string => {
  switch (presence) {
    case 'working':
      return 'يكتب الآن';
    case 'connected':
      return 'جاهز لسؤالك';
    case 'submitting':
      return 'نرسل سؤالك';
    case 'checking':
      return 'نتحقق من الرد';
    case 'recoverable':
      return 'الرد محفوظ';
    default:
      return 'اسأل عن الكورس';
  }
};

const CourseChatOverlay = ({
  visible,
  course,
  reel,
  onClose,
  onEntitlementChanged,
  onOpenCourseAccess,
}: CourseChatOverlayProps) => {
  const parentInsets = useSafeAreaInsets();
  const [insets, setInsets] = useState(parentInsets);
  const reducedMotion = useReducedMotion();
  const {height: windowHeight, fontScale} = useWindowDimensions();
  const [viewportHeight, setViewportHeight] = useState(windowHeight);
  const [choosingUpgrade, setChoosingUpgrade] = useState(false);
  const sheetLayout = courseChatSheetLayout(
    Dimensions.get('screen').height,
    viewportHeight,
    insets.top,
    fontScale,
  );
  const previousVisibleRef = useRef(false);
  const previousAssistantIncludedRef = useRef(true);
  const previousCourseIdRef = useRef(String(course.id));
  const {
    answerPending,
    assistantPresence,
    assistantIncluded,
    consentPending,
    attachments,
    chatAccessUnavailable,
    input,
    hydrated,
    hydrationError,
    retryHydration,
    messages,
    pickAttachments,
    planLimitReached,
    upgradeStatus,
    retryUpgradeQuote,
    retry,
    scrollRef,
    send,
    isSendInFlight,
    sending,
    stop,
    setInput,
    setAttachments,
  } = useCourseChat({
    visible,
    course,
    reel,
  });
  const entryMode = courseAssistantEntryMode(course);
  const courseAccessRequired = entryMode === 'course_access';
  const courseChatUnavailable = entryMode === 'unavailable';
  const hasSendableInput =
    cleanUnicodeText(input).length > 0 || attachments.length > 0;
  const turnBusy = consentPending || sending || isSendInFlight();
  const attachmentLimit = Math.max(0, course.chatAttachmentMaxFiles || 0);

  useEffect(() => {
    const courseChanged = previousCourseIdRef.current !== String(course.id);
    const opened = visible && !previousVisibleRef.current;
    const becameGated =
      visible && previousAssistantIncludedRef.current && !assistantIncluded;
    previousCourseIdRef.current = String(course.id);
    previousVisibleRef.current = visible;
    previousAssistantIncludedRef.current = assistantIncluded;

    // The existing checkout loads a fresh quote after choosing to upgrade.
    if (opened || becameGated || courseChanged || !visible)
      setChoosingUpgrade(false);
  }, [
    assistantIncluded,
    chatAccessUnavailable,
    courseAccessRequired,
    courseChatUnavailable,
    course.id,
    visible,
  ]);

  const sendCurrentMessage = () => {
    if (!hasSendableInput) return;
    send();
  };

  return (
    <Modal
      visible={visible}
      transparent
      animationType={reducedMotion ? 'none' : 'slide'}
      presentationStyle="overFullScreen"
      hardwareAccelerated={Platform.OS === 'android'}
      statusBarTranslucent
      onRequestClose={onClose}>
      <KeyboardAvoidingView style={styles.modal} behavior="padding">
        {/* Measure this Dialog, not the Activity behind it. Insets inside the
            avoided viewport exclude a keyboard that already covers the bars. */}
        <SafeAreaListener
          style={[
            styles.modal,
            {paddingLeft: insets.left, paddingRight: insets.right},
          ]}
          onChange={metrics => setInsets(metrics.insets)}>
          <View
            style={styles.modal}
            onLayout={event =>
              setViewportHeight(event.nativeEvent.layout.height)
            }>
            <Pressable
              accessible={false}
              importantForAccessibility="no-hide-descendants"
              style={styles.backdrop}
              onPress={onClose}
            />
            <View
              accessibilityViewIsModal
              style={[
                styles.sheet,
                {
                  height: sheetLayout.height,
                },
              ]}>
              {!sheetLayout.compact && <View style={styles.handle} />}
              <View
                style={[
                  styles.header,
                  sheetLayout.compact && styles.compactHeader,
                ]}>
                <View style={styles.headerCopy}>
                  <Text style={styles.title} numberOfLines={1}>
                    استفسارات الكورس
                  </Text>
                  <View style={styles.presenceRow}>
                    <View
                      style={[
                        styles.presenceDot,
                        assistantPresence === 'connected' &&
                          styles.presenceDotConnected,
                        ['working', 'submitting', 'checking'].includes(
                          assistantPresence,
                        ) && styles.presenceDotWorking,
                      ]}
                    />
                    <Text
                      accessibilityLiveRegion="polite"
                      numberOfLines={1}
                      style={styles.presenceText}>
                      {presenceLabel(assistantPresence)}
                    </Text>
                  </View>
                </View>
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="إغلاق"
                  hitSlop={10}
                  style={styles.closeButton}
                  onPress={onClose}>
                  <Text style={styles.closeText} maxFontSizeMultiplier={1.1}>
                    ×
                  </Text>
                </Pressable>
              </View>

              {!assistantIncluded &&
              choosingUpgrade &&
              !chatAccessUnavailable &&
              !courseAccessRequired &&
              !courseChatUnavailable ? (
                <FullTrackUpgradeSheet
                  requiredFeature="chat"
                  quotaExhausted={planLimitReached}
                  visible={visible}
                  courseId={String(course.id)}
                  courseTitle={course.title}
                  onClose={onClose}
                  onUpgraded={onEntitlementChanged}
                  embedded
                />
              ) : !assistantIncluded ? (
                <CourseChatGate
                  accessUnavailable={chatAccessUnavailable}
                  courseAccessRequired={courseAccessRequired}
                  courseChatUnavailable={courseChatUnavailable}
                  onUpgrade={() => setChoosingUpgrade(true)}
                  onOpenCourseAccess={() => {
                    onClose();
                    onOpenCourseAccess();
                  }}
                  planLimitReached={planLimitReached}
                  upgradeStatus={upgradeStatus}
                  onRetryUpgrade={retryUpgradeQuote}
                />
              ) : !hydrated ? (
                <StatusView
                  state={hydrationError ? 'error' : 'loading'}
                  title={
                    hydrationError
                      ? 'تعذّر استعادة المحادثة'
                      : 'جارٍ استعادة المحادثة'
                  }
                  actionLabel={hydrationError ? 'إعادة المحاولة' : undefined}
                  onAction={hydrationError ? retryHydration : undefined}
                />
              ) : (
                <CourseChatConversation
                  courseId={String(course.id)}
                  answerPending={answerPending}
                  assistantPresence={assistantPresence}
                  attachmentLimit={attachmentLimit}
                  attachments={attachments}
                  attachmentsEnabled={Boolean(course.chatAttachmentsEnabled)}
                  bottomInset={insets.bottom}
                  inputMaxHeight={sheetLayout.inputMaxHeight}
                  hasSendableInput={hasSendableInput}
                  input={input}
                  composerLocked={consentPending}
                  messages={messages}
                  onInputChange={value =>
                    setInput(truncateGraphemes(value, 1600))
                  }
                  onOpenAttachment={file => {
                    void openCourseAssistantAttachment(file).catch(() =>
                      Alert.alert('تعذّر فتح الملف', 'حاول مرة أخرى'),
                    );
                  }}
                  onPickAttachments={() => void pickAttachments()}
                  onRemoveAttachment={file => {
                    if (isSendInFlight()) return;
                    setAttachments(current =>
                      current.filter(item => item.uploadId !== file.uploadId),
                    );
                    void removeLearnerDraftFile(file);
                  }}
                  onRetry={retry}
                  onSend={sendCurrentMessage}
                  onStop={() => void stop()}
                  scrollRef={scrollRef}
                  sending={turnBusy}
                />
              )}
            </View>
          </View>
        </SafeAreaListener>
      </KeyboardAvoidingView>
    </Modal>
  );
};

export default CourseChatOverlay;
