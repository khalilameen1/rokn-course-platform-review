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
import {rtlRowStyle, textDirection} from '../../../constants/designSystem';
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
import {formatArabicDisplayText} from '../../../constants/arabicFormatting';

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
            {formatArabicDisplayText(file.name)}
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
}: {
  message: ProjectFeedbackMessage;
  projectId: string;
  sending: boolean;
  threadId: string;
  onRetry: (message: ProjectFeedbackMessage) => void;
}) => (
  <View
    style={[
      styles.bubble,
      message.role === 'user' ? styles.bubbleUser : styles.bubbleAssistant,
    ]}>
    {!!message.text && (
      <Text style={styles.message}>
        {formatArabicDisplayText(message.text)}
      </Text>
    )}
    <MessageAttachments
      message={message}
      projectId={projectId}
      threadId={threadId}
    />
    {message.role === 'assistant' && message.status === 'streaming' && (
      <Text style={styles.state}>يكتب الآن</Text>
    )}
    {message.role === 'assistant' && message.status === 'failed' && (
      <Text style={styles.state}>
        {projectFeedbackFailureText(message.errorCode, message.canRetry)}
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
  return (
    <View style={styles.thread}>
      <View style={styles.header}>
        <Text style={styles.title}>شات ركن</Text>
        <Text style={styles.availability}>
          {canReply ? 'متصل الآن' : 'تقرير مشروعك'}
        </Text>
      </View>

      {thread.messages.map(message => (
        <FeedbackMessage
          key={message.id}
          message={message}
          projectId={projectId}
          sending={sending}
          threadId={thread.id}
          onRetry={onRetryMessage}
        />
      ))}

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
            accessibilityState={{busy: sending}}
            disabled={(!normalizedDraft && attachments.length === 0) || sending}
            onPress={onSend}
            style={[
              styles.send,
              ((!normalizedDraft && attachments.length === 0) || sending) &&
                styles.disabled,
            ]}>
            {sending ? (
              <ActivityIndicator color="#FFFFFF" size="small" />
            ) : (
              <Text style={styles.sendText}>إرسال</Text>
            )}
          </Pressable>
        </View>
      )}

      {!canReply && feedbackLevel === 'report' && (
        <View style={styles.composer}>
          <TextInput
            editable={false}
            placeholder="الرد متاح في فئة المتابعة"
            placeholderTextColor="rgba(255,255,255,.38)"
            style={styles.input}
          />
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="اعرف فئة الرد على التقرير"
            onPress={() =>
              Alert.alert('الرد غير مشمول', 'الردود متاحة في فئة المتابعة')
            }
            style={[styles.send, styles.disabled]}>
            <Text style={styles.sendText}>رد</Text>
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
    marginTop: 18,
    padding: 12,
    borderRadius: 18,
    gap: 8,
    backgroundColor: '#0B111A',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  header: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 2,
  },
  title: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 14,
  },
  availability: {
    ...textDirection,
    color: '#67D39B',
    fontFamily: Fonts.regular,
    fontSize: 10,
  },
  bubble: {
    maxWidth: '90%',
    borderRadius: 15,
    paddingHorizontal: 12,
    paddingVertical: 9,
  },
  bubbleAssistant: {
    alignSelf: 'flex-end',
    backgroundColor: '#17202C',
    borderTopLeftRadius: 5,
  },
  bubbleUser: {
    alignSelf: 'flex-start',
    backgroundColor: '#236FE8',
    borderTopRightRadius: 5,
  },
  message: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
  },
  state: {
    ...textDirection,
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.regular,
    fontSize: 10,
  },
  retry: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 10,
    marginTop: 4,
  },
  attachmentList: {gap: 6, marginTop: 3},
  attachmentChip: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 8,
    borderRadius: 11,
    paddingHorizontal: 10,
    paddingVertical: 7,
    backgroundColor: 'rgba(255,255,255,.07)',
  },
  attachmentName: {
    ...textDirection,
    flex: 1,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 11,
  },
  attachmentRemove: {color: '#FFFFFF', fontSize: 20, lineHeight: 20},
  attachmentPreview: {width: 34, height: 34, borderRadius: 8},
  attach: {
    width: 44,
    height: 44,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.08)',
  },
  attachText: {color: '#FFFFFF', fontSize: 22, lineHeight: 24},
  messageAttachments: {gap: 5, marginTop: 6},
  messageAttachment: {
    borderRadius: 9,
    paddingHorizontal: 8,
    paddingVertical: 5,
    backgroundColor: 'rgba(255,255,255,.1)',
  },
  messageAttachmentName: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 10,
  },
  composer: {
    ...rtlRowStyle,
    alignItems: 'flex-end',
    gap: 7,
    marginTop: 3,
  },
  input: {
    ...textDirection,
    flex: 1,
    minHeight: 44,
    maxHeight: 110,
    borderRadius: 14,
    paddingHorizontal: 12,
    paddingVertical: 10,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 12,
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  send: {
    minWidth: 64,
    height: 44,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
  },
  sendText: {color: '#FFFFFF', fontFamily: Fonts.semiBold, fontSize: 11},
  disabled: {opacity: 0.38},
  error: {
    ...textDirection,
    color: '#FF9A9A',
    fontFamily: Fonts.regular,
    fontSize: 10,
    lineHeight: 16,
  },
});

export default ProjectFeedbackPanel;
