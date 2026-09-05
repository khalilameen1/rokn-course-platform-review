import React, {useEffect, useRef} from 'react';
import type {RefObject} from 'react';
import {
  ActivityIndicator,
  Animated,
  Image,
  Pressable,
  ScrollView,
  Text,
  TextInput,
  View,
} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {useReducedMotion} from '../../../hooks/useReducedMotion';
import {cleanUnicodeText} from '../../../utils/unicodeText';
import type {ChatAttachmentDraft, ChatMessage} from '../types';
import {
  courseChatTurnHasRetryAction,
  courseChatTurnIsActuallyStreaming,
  courseChatTurnShowsActivity,
} from './policy';
import {courseChatStyles as styles} from './styles';
import type {AssistantPresence} from './conversation';

const SendIcon = () => (
  <Svg width={21} height={21} viewBox="0 0 24 24">
    <Path
      d="m20 4-8.1 16-2.3-6.5L4 10.1 20 4Z"
      fill="none"
      stroke="#fff"
      strokeWidth={1.9}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
    <Path
      d="m9.6 13.5 4.3-4.2"
      fill="none"
      stroke="#fff"
      strokeWidth={1.9}
      strokeLinecap="round"
    />
  </Svg>
);

const WorkingIndicator = () => {
  const dots = useRef([
    new Animated.Value(0.32),
    new Animated.Value(0.32),
    new Animated.Value(0.32),
  ]).current;
  const reduceMotion = useReducedMotion();

  useEffect(() => {
    if (reduceMotion) return undefined;
    const animation = Animated.loop(
      Animated.stagger(
        140,
        dots.map(dot =>
          Animated.sequence([
            Animated.timing(dot, {
              toValue: 1,
              duration: 280,
              useNativeDriver: true,
            }),
            Animated.timing(dot, {
              toValue: 0.32,
              duration: 280,
              useNativeDriver: true,
            }),
          ]),
        ),
      ),
    );
    animation.start();
    return () => animation.stop();
  }, [dots, reduceMotion]);

  return (
    <View
      accessible
      accessibilityLiveRegion="polite"
      accessibilityRole="text"
      accessibilityLabel="ركن يكتب الآن"
      style={styles.workingIndicator}>
      {dots.map((opacity, index) => (
        <Animated.View
          key={index}
          style={[styles.workingDot, {opacity: reduceMotion ? 0.72 : opacity}]}
        />
      ))}
    </View>
  );
};

export const CourseChatConversation = ({
  answerPending,
  assistantPresence,
  attachmentLimit,
  attachments,
  attachmentsEnabled,
  bottomInset,
  hasSendableInput,
  input,
  messages,
  onCopy,
  onInputChange,
  onOpenAttachment,
  onPickAttachments,
  onRemoveAttachment,
  onRetry,
  onSend,
  onStop,
  scrollRef,
  sending,
}: {
  answerPending: boolean;
  assistantPresence: AssistantPresence;
  attachmentLimit: number;
  attachments: ChatAttachmentDraft[];
  attachmentsEnabled: boolean;
  bottomInset: number;
  hasSendableInput: boolean;
  input: string;
  messages: ChatMessage[];
  onCopy: (text: string) => void;
  onInputChange: (text: string) => void;
  onOpenAttachment: (file: ChatAttachmentDraft) => void;
  onPickAttachments: () => void;
  onRemoveAttachment: (file: ChatAttachmentDraft) => void;
  onRetry: (clientRequestId: string) => void;
  onSend: () => void;
  onStop: () => void;
  scrollRef: RefObject<ScrollView | null>;
  sending: boolean;
}) => {
  const stickToEndRef = useRef(true);

  return (
    <>
      <ScrollView
        accessibilityLabel="محادثة استفسارات الكورس"
        ref={scrollRef}
        style={styles.messages}
        contentContainerStyle={styles.messagesContent}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
        scrollEventThrottle={32}
        onScroll={event => {
          const {contentOffset, contentSize, layoutMeasurement} =
            event.nativeEvent;
          stickToEndRef.current =
            contentOffset.y + layoutMeasurement.height >=
            contentSize.height - 48;
        }}
        onContentSizeChange={() => {
          if (stickToEndRef.current) {
            scrollRef.current?.scrollToEnd({animated: false});
          }
        }}>
        {messages.map(message => (
          <View
            key={message.id}
            style={[
              styles.bubble,
              message.role === 'user'
                ? styles.userBubble
                : styles.assistantBubble,
            ]}>
            {courseChatTurnShowsActivity(message.deliveryStatus) &&
            !cleanUnicodeText(message.text) ? (
              assistantPresence === 'working' ? (
                <WorkingIndicator />
              ) : (
                <ActivityIndicator color="#FFFFFF" size="small" />
              )
            ) : (
              <>
                <Text selectable={false} style={styles.bubbleText}>
                  {cleanUnicodeText(message.text)}
                </Text>
                {courseChatTurnIsActuallyStreaming(message.deliveryStatus) && (
                  <WorkingIndicator />
                )}
                {message.attachments?.map(file => (
                  <Pressable
                    key={file.serverId || file.uploadId}
                    disabled={!file.serverId && !file.downloadUrl}
                    onPress={() => onOpenAttachment(file)}>
                    <Text numberOfLines={1} style={styles.messageAttachment}>
                      {cleanUnicodeText(file.name, false)}
                    </Text>
                  </Pressable>
                ))}
                {!!cleanUnicodeText(message.text) && (
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel="نسخ الرسالة"
                    hitSlop={6}
                    onPress={() => onCopy(cleanUnicodeText(message.text))}>
                    <Text style={styles.copyText}>نسخ</Text>
                  </Pressable>
                )}
                {message.role === 'assistant' &&
                  message.clientRequestId &&
                  courseChatTurnHasRetryAction(
                    message.deliveryStatus,
                    message.errorCode,
                    message.canRetry,
                  ) && (
                    <Pressable
                      accessibilityRole="button"
                      disabled={sending}
                      onPress={() => onRetry(message.clientRequestId!)}>
                      <Text style={styles.retryText}>
                        {[
                          'chat_answer_in_progress',
                          'client_timeout',
                          'interrupted_turn',
                        ].includes(message.errorCode || '')
                          ? 'استعد الرد'
                          : 'حاول مرة أخرى'}
                      </Text>
                    </Pressable>
                  )}
              </>
            )}
          </View>
        ))}
      </ScrollView>

      {attachments.length > 0 && (
        <ScrollView
          horizontal
          style={styles.attachmentStrip}
          showsHorizontalScrollIndicator={false}>
          {attachments.map(file => (
            <View key={file.uploadId} style={styles.attachmentChip}>
              {file.type.startsWith('image/') && file.uri !== '' && (
                <Image
                  source={{uri: file.uri}}
                  style={styles.attachmentPreview}
                />
              )}
              <Text numberOfLines={1} style={styles.attachmentName}>
                {cleanUnicodeText(file.name, false)}
              </Text>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={`حذف ${file.name}`}
                onPress={() => onRemoveAttachment(file)}>
                <Text style={styles.attachmentRemove}>×</Text>
              </Pressable>
            </View>
          ))}
        </ScrollView>
      )}
      {answerPending && (
        <Pressable
          accessibilityRole="button"
          style={styles.stopButton}
          onPress={onStop}>
          <Text style={styles.stopButtonText}>إيقاف</Text>
        </Pressable>
      )}
      <View
        style={[
          styles.composer,
          {paddingBottom: Math.max(10, bottomInset + 6)},
        ]}>
        <TextInput
          accessibilityLabel="اكتب سؤالك عن الكورس"
          value={input}
          onChangeText={onInputChange}
          placeholder="اكتب سؤالك"
          placeholderTextColor="rgba(255,255,255,.42)"
          multiline
          style={styles.input}
          onSubmitEditing={onSend}
          blurOnSubmit={false}
        />
        {attachmentsEnabled && attachmentLimit > 0 && (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="إضافة صورة أو ملف"
            disabled={sending || attachments.length >= attachmentLimit}
            style={styles.attachButton}
            onPress={onPickAttachments}>
            <Text style={styles.attachButtonText}>＋</Text>
          </Pressable>
        )}
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="إرسال"
          accessibilityState={{
            busy: sending,
            disabled: !hasSendableInput || sending || answerPending,
          }}
          disabled={!hasSendableInput || sending || answerPending}
          style={[
            styles.sendButton,
            (!hasSendableInput || sending || answerPending) &&
              styles.sendButtonDisabled,
          ]}
          onPress={onSend}>
          <SendIcon />
        </Pressable>
      </View>
    </>
  );
};
