import React from 'react';
import {
  ActivityIndicator,
  Alert,
  Image,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import {
  Palette,
  rtlRowStyle,
  textDirection,
} from '../../../constants/designSystem';
import {Fonts} from '../../../constants/styleConstants';
import {openProjectInputAttachment} from '../courseLearningApi';
import {
  projectFeedbackFailureText,
  projectFeedbackMessageCanRetry,
  projectFeedbackMessageRequiresFreshAttachments,
} from '../projectFeedback/policy';
import type {
  ChatAttachmentDraft,
  ProjectFeedbackMessage,
  ProjectFeedbackThread,
} from '../types';
import {formatAuthoredDisplayText} from '../../../constants/arabicFormatting';

type Props = {
  attachments: ChatAttachmentDraft[];
  canReply: boolean;
  draft: string;
  error: string;
  feedbackLevel?: 'pass_only' | 'report' | 'enhanced';
  normalizedDraft: string;
  pending: boolean;
  projectId: string;
  sending: boolean;
  thread: ProjectFeedbackThread;
  onChangeDraft: (value: string) => void;
  onPickAttachments: () => void;
  onRemoveAttachment: (file: ChatAttachmentDraft) => void;
  onRetryMessage: (message: ProjectFeedbackMessage) => void;
  onSend: () => void;
};

const MessageAttachments = ({
  message,
  projectId,
  threadId,
}: {
  message: ProjectFeedbackMessage;
  projectId: string;
  threadId: string;
}) =>
  message.attachments?.length ? (
    <View style={styles.messageAttachments}>
      {message.attachments.map(file => (
        <Pressable
          accessibilityLabel={`فتح ${file.name}`}
          accessibilityRole="button"
          key={file.serverId || file.uploadId}
          style={styles.messageAttachment}
          onPress={() =>
            void openProjectInputAttachment({
              projectId,
              threadId,
              file,
            }).catch(() => Alert.alert('تعذّر فتح الملف', 'حاول مرة أخرى'))
          }>
          <Text numberOfLines={1} style={styles.messageAttachmentName}>
            {formatAuthoredDisplayText(file.name)}
          </Text>
        </Pressable>
      ))}
    </View>
  ) : null;

const FeedbackMessage = ({
  message,
  projectId,
  sending,
  threadId,
  onRetry,
  report = false,
}: {
  message: ProjectFeedbackMessage;
  projectId: string;
  sending: boolean;
  threadId: string;
  onRetry: (message: ProjectFeedbackMessage) => void;
  report?: boolean;
}) => (
  <View
    style={[
      styles.messageBlock,
      !report && message.role === 'user' && styles.bubbleUser,
    ]}>
    {!!message.text && (
      <Text style={styles.message}>
        {formatAuthoredDisplayText(message.text)}
      </Text>
    )}
    <MessageAttachments
      message={message}
      projectId={projectId}
      threadId={threadId}
    />
    {message.role === 'assistant' &&
      ['queued', 'sent', 'streaming'].includes(message.status) && (
        <View accessibilityLiveRegion="polite" style={styles.pendingState}>
          <ActivityIndicator color={Palette.textMuted} size="small" />
          <Text style={styles.state}>
            {message.status === 'streaming'
              ? 'يكتب الآن'
              : report
              ? 'جارٍ تجهيز التقرير'
              : 'جارٍ تجهيز الرد'}
          </Text>
        </View>
      )}
    {message.role === 'assistant' && message.status === 'failed' && (
      <Text style={styles.state}>
        {message.text?.trim()
          ? 'لم يكتمل الرد'
          : projectFeedbackFailureText(message.errorCode, message.canRetry)}
      </Text>
    )}
    {message.role === 'user' && message.status === 'queued' && (
      <Text style={styles.state}>جارٍ الإرسال</Text>
    )}
    {message.status === 'failed' &&
      message.role === 'user' &&
      projectFeedbackMessageCanRetry(message) && (
        <Pressable
          accessibilityRole="button"
          disabled={sending}
          style={styles.retryAction}
          onPress={() => onRetry(message)}>
          <Text style={styles.retry}>إرسال مرة أخرى</Text>
        </Pressable>
      )}
    {message.status === 'failed' &&
      message.role === 'user' &&
      projectFeedbackMessageRequiresFreshAttachments(message) && (
        <Text style={styles.state}>أضف الملف مرة أخرى ثم أرسل الرسالة</Text>
      )}
  </View>
);

const ProjectFeedbackPanel = ({
  attachments,
  canReply,
  draft,
  error,
  feedbackLevel,
  normalizedDraft,
  pending,
  projectId,
  sending,
  thread,
  onChangeDraft,
  onPickAttachments,
  onRemoveAttachment,
  onRetryMessage,
  onSend,
}: Props) => {
  // The server retains the initial report at the head of the ordered thread,
  // even when older follow-ups fall outside its history window.
  const report =
    thread.messages[0]?.role === 'assistant' ? thread.messages[0] : undefined;
  const conversation = report ? thread.messages.slice(1) : thread.messages;
  const hasPendingAssistant = thread.messages.some(
    message =>
      message.role === 'assistant' &&
      ['queued', 'sent', 'streaming'].includes(message.status),
  );
  return (
    <View style={styles.thread}>
      <View style={styles.report}>
        <Text accessibilityRole="header" style={styles.title}>
          تقرير المشروع
        </Text>
        {report && (
          <FeedbackMessage
            report
            message={report}
            projectId={projectId}
            sending={sending}
            threadId={thread.id}
            onRetry={onRetryMessage}
          />
        )}
      </View>

      {(canReply || conversation.length > 0) && (
        <View style={styles.conversation}>
          <Text accessibilityRole="header" style={styles.conversationTitle}>
            استفسارات عن التقرير
          </Text>
          {conversation.map(message => (
            <FeedbackMessage
              key={message.id}
              message={message}
              projectId={projectId}
              sending={sending}
              threadId={thread.id}
              onRetry={onRetryMessage}
            />
          ))}
        </View>
      )}

      {pending && !hasPendingAssistant && (
        <View accessibilityLiveRegion="polite" style={styles.pendingState}>
          <ActivityIndicator color={Palette.textMuted} size="small" />
          <Text style={styles.state}>جارٍ تجهيز الرد</Text>
        </View>
      )}

      {attachments.length > 0 && (
        <View style={styles.attachmentList}>
          {attachments.map(file => (
            <View key={file.uploadId} style={styles.attachmentChip}>
              {file.type.startsWith('image/') && !!file.uri && (
                <Image
                  progressiveRenderingEnabled
                  resizeMethod="resize"
                  source={{uri: file.uri}}
                  style={styles.attachmentPreview}
                />
              )}
              <Text numberOfLines={1} style={styles.attachmentName}>
                {file.name}
              </Text>
              <Pressable
                accessibilityLabel={`إزالة ${file.name}`}
                accessibilityRole="button"
                disabled={sending}
                style={styles.removeAction}
                onPress={() => onRemoveAttachment(file)}>
                <Text style={styles.attachmentRemove}>×</Text>
              </Pressable>
            </View>
          ))}
        </View>
      )}

      {canReply && thread.remainingMessages > 0 && !pending && (
        <View style={styles.composer}>
          <TextInput
            multiline
            editable={!sending}
            value={draft}
            onChangeText={onChangeDraft}
            placeholder="اسأل عن مشروعك"
            placeholderTextColor="rgba(255,255,255,.38)"
            style={styles.input}
          />
          <View style={styles.composerActions}>
            {thread.attachmentsEnabled && (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel="إضافة مرفق"
                disabled={
                  sending ||
                  attachments.length >= (thread.attachmentMaxFiles || 0)
                }
                style={styles.attach}
                onPress={onPickAttachments}>
                <Text style={styles.attachText}>＋</Text>
              </Pressable>
            )}
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="إرسال الاستفسار"
              accessibilityState={{busy: sending}}
              disabled={
                (!normalizedDraft && attachments.length === 0) || sending
              }
              onPress={onSend}
              style={[
                styles.send,
                ((!normalizedDraft && attachments.length === 0) || sending) &&
                  styles.disabled,
              ]}>
              {sending ? (
                <ActivityIndicator color={Palette.text} size="small" />
              ) : (
                <Text style={styles.sendText}>إرسال</Text>
              )}
            </Pressable>
          </View>
        </View>
      )}

      {!canReply && feedbackLevel === 'report' && (
        <View style={styles.reportGate}>
          <Text style={styles.state}>
            فئتك تشمل التقرير فقط والردود متاحة في فئة المتابعة
          </Text>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="اعرف فئة الرد على التقرير"
            onPress={() =>
              Alert.alert('الرد غير مشمول', 'الردود متاحة في فئة المتابعة')
            }
            style={styles.gateAction}>
            <Text style={styles.gateActionText}>الرد على التقرير</Text>
          </Pressable>
        </View>
      )}

      {canReply && thread.remainingMessages <= 0 && (
        <Text style={styles.state}>اكتملت رسائل الفئة</Text>
      )}
      {!!error && (
        <Text accessibilityRole="alert" style={styles.error}>
          {error}
        </Text>
      )}
    </View>
  );
};

const styles = StyleSheet.create({
  thread: {
    direction: 'rtl',
    width: '100%',
    marginTop: 24,
    gap: 24,
  },
  report: {gap: 12},
  conversation: {gap: 16},
  title: {
    ...textDirection,
    color: Palette.text,
    fontFamily: Fonts.bold,
    fontSize: 18,
    lineHeight: 28,
  },
  conversationTitle: {
    ...textDirection,
    color: Palette.text,
    fontFamily: Fonts.semiBold,
    fontSize: 15,
    lineHeight: 24,
  },
  messageBlock: {alignSelf: 'stretch', gap: 8},
  bubbleUser: {
    alignSelf: 'flex-start',
    maxWidth: '100%',
    backgroundColor: Palette.surfaceRaised,
    borderRadius: 14,
    padding: 12,
  },
  message: {
    ...textDirection,
    color: Palette.text,
    fontFamily: Fonts.regular,
    fontSize: 15,
    lineHeight: 24,
  },
  state: {
    ...textDirection,
    color: Palette.textMuted,
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    flexShrink: 1,
  },
  pendingState: {...rtlRowStyle, gap: 8, alignItems: 'center'},
  retryAction: {
    minHeight: 48,
    minWidth: 48,
    justifyContent: 'center',
    alignSelf: 'flex-start',
  },
  retry: {
    ...textDirection,
    color: Palette.text,
    fontFamily: Fonts.semiBold,
    fontSize: 12,
    lineHeight: 20,
  },
  attachmentList: {gap: 8},
  attachmentChip: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 8,
    borderRadius: 11,
    paddingHorizontal: 10,
    paddingVertical: 7,
    backgroundColor: Palette.surfaceRaised,
  },
  attachmentName: {
    ...textDirection,
    flex: 1,
    minWidth: 0,
    color: Palette.text,
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
  },
  removeAction: {
    minWidth: 48,
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
  },
  attachmentRemove: {color: Palette.text, fontSize: 22, lineHeight: 26},
  attachmentPreview: {width: 34, height: 34, borderRadius: 8},
  attach: {
    minWidth: 48,
    minHeight: 48,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.surfaceRaised,
  },
  attachText: {color: Palette.text, fontSize: 22, lineHeight: 26},
  messageAttachments: {gap: 8},
  messageAttachment: {
    minHeight: 48,
    minWidth: 48,
    justifyContent: 'center',
    borderRadius: 10,
    paddingHorizontal: 12,
    paddingVertical: 8,
    backgroundColor: Palette.surfaceRaised,
  },
  messageAttachmentName: {
    ...textDirection,
    color: Palette.text,
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
  },
  composer: {gap: 12},
  composerActions: {
    ...rtlRowStyle,
    gap: 12,
    alignItems: 'center',
    justifyContent: 'flex-end',
    flexWrap: 'wrap',
  },
  input: {
    ...textDirection,
    width: '100%',
    minHeight: 72,
    maxHeight: 160,
    borderRadius: 14,
    paddingHorizontal: 12,
    paddingVertical: 10,
    color: Palette.text,
    fontFamily: Fonts.regular,
    fontSize: 15,
    lineHeight: 24,
    textAlignVertical: 'top',
    backgroundColor: Palette.surface,
  },
  send: {
    minWidth: 88,
    minHeight: 48,
    paddingHorizontal: 20,
    paddingVertical: 12,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.action,
  },
  sendText: {
    color: Palette.text,
    fontFamily: Fonts.semiBold,
    fontSize: 14,
    lineHeight: 24,
  },
  reportGate: {gap: 8},
  gateAction: {
    minHeight: 48,
    minWidth: 48,
    justifyContent: 'center',
    alignSelf: 'flex-start',
  },
  gateActionText: {
    ...textDirection,
    color: Palette.text,
    fontFamily: Fonts.semiBold,
    fontSize: 14,
    lineHeight: 24,
  },
  disabled: {opacity: 0.38},
  error: {
    ...textDirection,
    color: Palette.danger,
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
  },
});

export default ProjectFeedbackPanel;
