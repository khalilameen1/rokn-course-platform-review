import React from 'react';
import {Image, Modal, Pressable, Text, TextInput, View} from 'react-native';

import {StatusView} from '../../components/ui/PremiumUI';
import {Palette} from '../../constants/designSystem';
import type {
  FeedbackAttachment,
  ProductFeedbackArtifact,
  ProductFeedbackCase,
} from '../../services/productFeedback';
import {styles} from './styles';

const CASE_STATUS: Record<string, string> = {
  received: 'وصل إلى الدعم',
  in_progress: 'قيد المراجعة',
  waiting_for_you: 'بانتظار ردك',
  resolved: 'تم الحل',
  closed: 'مغلق',
};

type Props = {
  cases: ProductFeedbackCase[];
  casesBusy: boolean;
  casesError: string;
  onChooseReplyAttachment: () => void;
  onCloseArtifact: () => void;
  onArtifactLoadError: (artifactId: string) => void;
  onOpenArtifact: (
    artifact: ProductFeedbackArtifact,
    forceRefresh?: boolean,
  ) => void;
  onRefresh: () => void;
  onRemoveReplyAttachment: () => void;
  onReplyChange: (value: string) => void;
  onSelectCase: (id: string) => void;
  onSendReply: () => void;
  previewArtifact?: ProductFeedbackArtifact;
  previewLoadFailed: boolean;
  replyAttachment?: FeedbackAttachment;
  replyBusy: boolean;
  replyError: string;
  replyReady?: boolean;
  replyRestoreError?: boolean;
  onRetryReplyRestore?: () => void;
  replyMessage: string;
  selectedCase?: ProductFeedbackCase;
  selectedCaseId: string;
};

export const FeedbackConversation = ({
  cases,
  casesBusy,
  casesError,
  onChooseReplyAttachment,
  onCloseArtifact,
  onArtifactLoadError,
  onOpenArtifact,
  onRefresh,
  onRemoveReplyAttachment,
  onReplyChange,
  onSelectCase,
  onSendReply,
  previewArtifact,
  previewLoadFailed,
  replyAttachment,
  replyBusy,
  replyError,
  replyReady = true,
  replyRestoreError,
  onRetryReplyRestore,
  replyMessage,
  selectedCase,
  selectedCaseId,
}: Props) => (
  <>
    <View style={styles.casesCard}>
      <View style={styles.casesHeader}>
        <View style={styles.casesHeadingCopy}>
          <Text accessibilityRole="header" style={styles.caseHeading}>
            {selectedCase ? `الحالة ${selectedCase.caseNumber}` : 'طلباتك'}
          </Text>
          {!!selectedCase && (
            <Text style={styles.caseStatus}>
              {CASE_STATUS[selectedCase.status] || 'قيد المتابعة'}
            </Text>
          )}
        </View>
        {!!selectedCase && (
          <Pressable
            accessibilityRole="button"
            disabled={casesBusy || replyBusy}
            onPress={() => onSelectCase('')}
            style={styles.caseHeaderAction}>
            <Text style={styles.refreshCases}>كل الطلبات</Text>
          </Pressable>
        )}
        <Pressable
          accessibilityLabel="تحديث الحالات"
          accessibilityRole="button"
          disabled={casesBusy || replyBusy}
          onPress={onRefresh}
          style={styles.caseHeaderAction}>
          <Text style={styles.refreshCases}>
            {casesBusy ? 'جارٍ التحديث' : 'تحديث'}
          </Text>
        </Pressable>
      </View>
      {!selectedCase &&
        (casesBusy && cases.length === 0 ? (
          <Text style={styles.caseMuted}>جارٍ تحميل الحالات</Text>
        ) : !cases.length && !casesError ? (
          <Text style={styles.caseMuted}>لا توجد متابعات سابقة</Text>
        ) : (
          cases.map(item => {
            const selected = item.publicId === selectedCaseId;
            return (
              <Pressable
                accessibilityLabel={`الحالة ${item.caseNumber} ${
                  CASE_STATUS[item.status] || 'قيد المتابعة'
                }`}
                accessibilityRole="button"
                disabled={casesBusy || replyBusy}
                key={item.publicId}
                onPress={() => onSelectCase(selected ? '' : item.publicId)}
                style={({pressed}) => [
                  styles.caseRow,
                  selected && styles.caseRowSelected,
                  pressed && styles.pressed,
                ]}>
                <View style={styles.caseCopy}>
                  <Text numberOfLines={2} style={styles.caseSubject}>
                    {item.message}
                  </Text>
                  <Text style={styles.caseNumber}>
                    الحالة {item.caseNumber}
                  </Text>
                </View>
                <Text style={styles.caseStatus}>
                  {CASE_STATUS[item.status] || 'قيد المتابعة'}
                </Text>
              </Pressable>
            );
          })
        ))}
      {!!casesError && (
        <Text accessibilityRole="alert" style={styles.error}>
          {casesError}
        </Text>
      )}

      {!!selectedCase && (
        <View style={styles.timeline}>
          {selectedCase.messages.map(item => (
            <View
              key={item.publicId}
              style={[
                styles.timelineMessage,
                item.author === 'support' && styles.timelineSupport,
              ]}>
              <Text style={styles.timelineAuthor}>
                {item.author === 'support' ? 'فريق الدعم' : 'أنت'}
              </Text>
              <Text style={styles.timelineText}>{item.text}</Text>
              {item.attachments.map(file => (
                <Pressable
                  accessibilityLabel="فتح الصورة المرفقة"
                  accessibilityRole="button"
                  key={file.id}
                  onPress={() => onOpenArtifact(file)}
                  style={({pressed}) => [
                    styles.timelineAttachment,
                    pressed && styles.pressed,
                  ]}>
                  <Image
                    accessibilityIgnoresInvertColors
                    source={{uri: file.url}}
                    style={styles.timelineAttachmentImage}
                  />
                  <Text style={styles.timelineAttachmentLabel}>فتح الصورة</Text>
                </Pressable>
              ))}
            </View>
          ))}
          {!replyReady ? (
            <StatusView
              state={replyRestoreError ? 'error' : 'loading'}
              title={
                replyRestoreError
                  ? 'تعذّر استعادة مسودة الرد'
                  : 'جارٍ استعادة مسودة الرد'
              }
              actionLabel={replyRestoreError ? 'إعادة المحاولة' : undefined}
              onAction={replyRestoreError ? onRetryReplyRestore : undefined}
            />
          ) : (
            <>
              <Text style={styles.replyLabel}>ردك على الطلب</Text>
              <TextInput
                accessibilityLabel="ردك على الحالة"
                maxLength={2000}
                editable={!casesBusy && !replyBusy}
                multiline
                onChangeText={onReplyChange}
                placeholder="اكتب ردك"
                placeholderTextColor={Palette.textFaint}
                style={styles.replyInput}
                textAlignVertical="top"
                value={replyMessage}
              />
              {replyAttachment ? (
                <View style={styles.attachmentRow}>
                  <Image
                    accessibilityLabel="الصورة المرفقة بالرد"
                    source={{uri: replyAttachment.uri}}
                    style={styles.attachmentImage}
                  />
                  <Pressable
                    accessibilityLabel="حذف صورة الرد"
                    accessibilityRole="button"
                    disabled={casesBusy || replyBusy}
                    onPress={onRemoveReplyAttachment}
                    style={styles.removeAttachmentButton}>
                    <Text style={styles.removeAttachment}>حذف الصورة</Text>
                  </Pressable>
                </View>
              ) : (
                <Pressable
                  accessibilityLabel="إضافة صورة إلى الرد"
                  accessibilityRole="button"
                  disabled={casesBusy || replyBusy}
                  onPress={onChooseReplyAttachment}
                  style={({pressed}) => [
                    styles.replyAttachmentButton,
                    pressed && styles.pressed,
                  ]}>
                  <Text style={styles.attachmentButtonText}>أضف صورة</Text>
                </Pressable>
              )}
              {!!replyError && (
                <Text accessibilityRole="alert" style={styles.error}>
                  {replyError}
                </Text>
              )}
              <Pressable
                accessibilityRole="button"
                accessibilityState={{
                  busy: replyBusy,
                  disabled:
                    casesBusy || replyBusy || replyMessage.trim().length < 2,
                }}
                disabled={
                  casesBusy || replyBusy || replyMessage.trim().length < 2
                }
                onPress={onSendReply}
                style={({pressed}) => [
                  styles.replyButton,
                  (casesBusy || replyBusy || replyMessage.trim().length < 2) &&
                    styles.submitDisabled,
                  pressed && styles.pressed,
                ]}>
                <Text style={styles.submitText}>
                  {replyBusy ? 'جارٍ الإرسال' : 'إرسال الرد'}
                </Text>
              </Pressable>
            </>
          )}
        </View>
      )}
    </View>

    <Modal
      animationType="fade"
      onRequestClose={onCloseArtifact}
      transparent
      visible={Boolean(previewArtifact)}>
      <View
        accessibilityViewIsModal
        importantForAccessibility="yes"
        style={styles.previewBackdrop}>
        <Pressable
          accessibilityLabel="إغلاق الصورة"
          accessibilityRole="button"
          onPress={onCloseArtifact}
          style={styles.previewClose}>
          <Text style={styles.previewCloseText}>إغلاق</Text>
        </Pressable>
        {previewArtifact && (
          <Image
            accessibilityLabel={previewArtifact.name}
            accessibilityIgnoresInvertColors
            onError={() => onArtifactLoadError(previewArtifact.id)}
            resizeMode="contain"
            source={{uri: previewArtifact.url}}
            style={styles.previewImage}
          />
        )}
        {previewArtifact && previewLoadFailed && (
          <Pressable
            accessibilityRole="button"
            onPress={() => onOpenArtifact(previewArtifact, true)}
            style={styles.previewRetry}>
            <Text style={styles.previewRetryText}>حاول مرة أخرى</Text>
          </Pressable>
        )}
      </View>
    </Modal>
  </>
);
