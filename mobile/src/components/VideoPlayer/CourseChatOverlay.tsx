import React, {useEffect, useRef} from 'react';
import {useNavigation} from '@react-navigation/native';
import Clipboard from '@react-native-clipboard/clipboard';
import {
  AccessibilityInfo,
  Alert,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  Text,
  ToastAndroid,
  useWindowDimensions,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {useCourseChat} from './courseChat/useCourseChat';
import type {CourseLearningData, CourseReel} from './types';
import type {AssistantPresence} from './courseChat/useCourseChat';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {cleanUnicodeText, truncateGraphemes} from '../../utils/unicodeText';
import {removeLearnerDraftFile} from '../../services/learnerDraftFiles';
import {openCourseAssistantAttachment} from './courseLearningApi';
import {useCourseChatAttachments} from './courseChat/useCourseChatAttachments';
import {courseChatStyles as styles} from './courseChat/styles';
import {CourseChatGate} from './courseChat/CourseChatGate';
import {CourseChatConversation} from './courseChat/CourseChatConversation';

interface CourseChatOverlayProps {
  visible: boolean;
  course: CourseLearningData;
  reel?: CourseReel;
  onClose: () => void;
}

type CourseChatNavigation = {
  navigate: (
    screen: 'Wallet',
    params?: {returnTo?: import('../../navigation/types').LoginReturnTo},
  ) => void;
};

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
}: CourseChatOverlayProps) => {
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const {height: windowHeight, fontScale} = useWindowDimensions();
  const navigation = useNavigation<CourseChatNavigation>();
  const previousVisibleRef = useRef(false);
  const previousAssistantIncludedRef = useRef(true);
  const previousCourseIdRef = useRef(String(course.id));
  const {
    answerPending,
    assistantPresence,
    assistantIncluded,
    attachments,
    chatAccessUnavailable,
    confirmUpgrade,
    input,
    loadUpgradeQuote,
    messages,
    planLimitReached,
    retry,
    scholarshipAccess,
    scrollRef,
    send,
    isSendInFlight,
    sending,
    stop,
    setInput,
    setAttachments,
    upgradeError,
    upgradeLoading,
    upgradeQuote,
  } = useCourseChat({
    visible,
    course,
    reel,
    onOpenWallet: () => {
      // A native Modal belongs to this screen even after another route is
      // pushed. Close it before navigation so its backdrop/keyboard cannot
      // remain above Wallet and consume taps meant for the new screen.
      onClose();
      navigation.navigate('Wallet', {
        returnTo: {
          name: 'Reels',
          params: {
            courseId: String(course.id),
            reelId: reel?.id ? String(reel.id) : undefined,
            lessonId: reel?.lessonId ? String(reel.lessonId) : undefined,
            openCourseChatUpgrade: true,
          },
        },
      });
    },
  });
  const hasSendableInput =
    cleanUnicodeText(input).length > 0 || attachments.length > 0;
  const turnBusy = sending || isSendInFlight();
  const attachmentLimit = Math.max(0, course.chatAttachmentMaxFiles || 0);
  const {pickAttachments, pickerIsActive} = useCourseChatAttachments({
    attachments,
    courseId: String(course.id),
    enabled: Boolean(course.chatAttachmentsEnabled),
    isSendInFlight,
    limit: attachmentLimit,
    sending,
    setAttachments,
    visible,
  });

  useEffect(() => {
    const courseChanged = previousCourseIdRef.current !== String(course.id);
    const opened = visible && !previousVisibleRef.current;
    const becameGated =
      visible && previousAssistantIncludedRef.current && !assistantIncluded;
    previousCourseIdRef.current = String(course.id);
    previousVisibleRef.current = visible;
    previousAssistantIncludedRef.current = assistantIncluded;

    // A quote contains a point-in-time wallet balance. Re-opening after the
    // Wallet must refresh it even when the old quote is still rendered;
    // otherwise its old deficit sends the learner back to Wallet forever.
    if (
      (opened || becameGated || (visible && courseChanged)) &&
      !assistantIncluded &&
      !chatAccessUnavailable &&
      !upgradeLoading
    ) {
      void loadUpgradeQuote();
    }
  }, [
    assistantIncluded,
    chatAccessUnavailable,
    course.id,
    loadUpgradeQuote,
    upgradeLoading,
    visible,
  ]);

  const sendCurrentMessage = () => {
    // The picker returns before its selected files are copied into our durable
    // draft registry. Sending during that window would submit the previous
    // attachment set and leave the newly picked files on the next message.
    if (pickerIsActive()) return;
    send();
  };

  const retryMessage = (clientRequestId: string) => {
    if (pickerIsActive()) return;
    retry(clientRequestId);
  };

  const copyMessage = (text: string) => {
    Clipboard.setString(text);
    // Native feedback avoids re-rendering and re-laying out the transparent
    // Modal above an Android video surface. That layout churn was visible as a
    // shaking reel and could expose touches to the screen underneath.
    if (Platform.OS === 'android') {
      ToastAndroid.show('تم النسخ', ToastAndroid.SHORT);
    }
    void AccessibilityInfo.announceForAccessibility('تم النسخ');
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
      <KeyboardAvoidingView
        style={styles.modal}
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
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
              height: fontScale > 1.25 ? '88%' : '78%',
              maxHeight: Math.max(380, windowHeight - insets.top - 8),
            },
          ]}>
          <View style={styles.handle} />
          <View style={styles.header}>
            <View style={styles.headerCopy}>
              <Text style={styles.title}>استفسارات</Text>
              <Text style={styles.presenceText}>
                مساعد تعليمي بالذكاء الاصطناعي
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

          {!assistantIncluded ? (
            <CourseChatGate
              accessUnavailable={chatAccessUnavailable}
              error={upgradeError}
              loading={upgradeLoading}
              onConfirm={() => void confirmUpgrade()}
              onLoadQuote={() => void loadUpgradeQuote()}
              planLimitReached={planLimitReached}
              quote={upgradeQuote}
              scholarshipAccess={scholarshipAccess}
            />
          ) : (
            <CourseChatConversation
              answerPending={answerPending}
              assistantPresence={assistantPresence}
              attachmentLimit={attachmentLimit}
              attachments={attachments}
              attachmentsEnabled={Boolean(course.chatAttachmentsEnabled)}
              bottomInset={insets.bottom}
              hasSendableInput={hasSendableInput}
              input={input}
              messages={messages}
              onCopy={copyMessage}
              onInputChange={value => setInput(truncateGraphemes(value, 1600))}
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
              onRetry={retryMessage}
              onSend={sendCurrentMessage}
              onStop={() => void stop()}
              scrollRef={scrollRef}
              sending={turnBusy}
            />
          )}
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
};

export default CourseChatOverlay;
