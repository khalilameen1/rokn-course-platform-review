import React from 'react';
import {Image, Pressable, Text, TextInput, View} from 'react-native';

import {PremiumCard, SectionHeading} from '../../components/ui/PremiumUI';
import {toArabicDigits} from '../../constants/arabicFormatting';
import {Palette} from '../../constants/designSystem';
import type {
  FeedbackAttachment,
  ProductFeedbackCategory,
} from '../../services/productFeedback';
import {styles} from './styles';

const CATEGORIES: Array<{key: ProductFeedbackCategory; label: string}> = [
  {key: 'problem', label: 'مشكلة'},
  {key: 'idea', label: 'اقتراح'},
  {key: 'content', label: 'ملاحظة على المحتوى'},
  {key: 'playback', label: 'تشغيل الفيديو'},
];

type Props = {
  attachment?: FeedbackAttachment;
  busy: boolean;
  canSubmit: boolean;
  category: ProductFeedbackCategory;
  draftSaveError: boolean;
  error: string;
  includeDiagnostics: boolean;
  message: string;
  onChooseAttachment: () => void;
  onMessageChange: (value: string) => void;
  onRemoveAttachment: () => void;
  onSelectCategory: (value: ProductFeedbackCategory) => void;
  onToggleDiagnostics: (value: boolean) => void;
  onSubmit: () => void;
  ready: boolean;
};

export const FeedbackForm = ({
  attachment,
  busy,
  canSubmit,
  category,
  draftSaveError,
  error,
  includeDiagnostics,
  message,
  onChooseAttachment,
  onMessageChange,
  onRemoveAttachment,
  onSelectCategory,
  onToggleDiagnostics,
  onSubmit,
  ready,
}: Props) => (
  <>
    <SectionHeading style={styles.heading} title="ماذا حدث" />
    <Text style={styles.intro}>اكتب المشكلة أو الاقتراح بوضوح</Text>

    <View accessibilityRole="radiogroup" style={styles.categories}>
      {CATEGORIES.map(item => {
        const selected = item.key === category;
        return (
          <Pressable
            accessibilityLabel={item.label}
            accessibilityRole="radio"
            accessibilityState={{
              checked: selected,
              disabled: busy || !ready,
            }}
            disabled={busy || !ready}
            key={item.key}
            onPress={() => onSelectCategory(item.key)}
            style={({pressed}) => [
              styles.category,
              selected && styles.categorySelected,
              pressed && styles.pressed,
            ]}>
            <Text
              style={[
                styles.categoryText,
                selected && styles.categoryTextSelected,
              ]}>
              {item.label}
            </Text>
          </Pressable>
        );
      })}
    </View>

    <PremiumCard style={styles.form}>
      <Text style={styles.label}>اكتب التفاصيل</Text>
      <TextInput
        accessibilityHint="اكتب عشرة أحرف على الأقل"
        accessibilityLabel="تفاصيل الملاحظة"
        multiline
        maxLength={1600}
        editable={ready && !busy}
        onChangeText={onMessageChange}
        placeholder="أين كنت وما الذي ظهر لك"
        placeholderTextColor={Palette.textFaint}
        selectionColor={Palette.primary}
        style={styles.input}
        textAlignVertical="top"
        value={message}
      />
      <Text style={styles.counter}>
        {toArabicDigits(message.length)} من ١٦٠٠
      </Text>

      {attachment ? (
        <View style={styles.attachmentRow}>
          <Image
            accessibilityLabel="الصورة المرفقة"
            source={{uri: attachment.uri}}
            style={styles.attachmentImage}
          />
          <View style={styles.attachmentCopy}>
            <Text style={styles.attachmentTitle}>الصورة جاهزة للإرسال</Text>
            <Pressable
              accessibilityLabel="حذف الصورة المرفقة"
              accessibilityRole="button"
              disabled={busy || !ready}
              hitSlop={8}
              onPress={onRemoveAttachment}>
              <Text style={styles.removeAttachment}>حذف الصورة</Text>
            </Pressable>
          </View>
        </View>
      ) : (
        <Pressable
          accessibilityLabel="إضافة صورة توضح المشكلة"
          accessibilityRole="button"
          disabled={busy || !ready}
          onPress={onChooseAttachment}
          style={({pressed}) => [
            styles.attachmentButton,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.attachmentButtonText}>
            أضف صورة إذا كانت توضح المشكلة
          </Text>
        </Pressable>
      )}

      <Pressable
        accessibilityLabel="إرفاق معلومات التشغيل"
        accessibilityRole="checkbox"
        accessibilityState={{
          checked: includeDiagnostics,
          disabled: busy || !ready,
        }}
        disabled={busy || !ready}
        onPress={() => onToggleDiagnostics(!includeDiagnostics)}
        style={({pressed}) => [
          styles.diagnosticsRow,
          pressed && styles.pressed,
        ]}>
        <View
          style={[
            styles.diagnosticsCheck,
            includeDiagnostics && styles.diagnosticsCheckSelected,
          ]}>
          {includeDiagnostics && (
            <Text style={styles.diagnosticsCheckMark}>✓</Text>
          )}
        </View>
        <View style={styles.diagnosticsCopy}>
          <Text style={styles.diagnosticsTitle}>أرفق معلومات التشغيل</Text>
          <Text style={styles.diagnosticsHint}>
            تساعدنا في معرفة سبب المشكلة
          </Text>
        </View>
      </Pressable>
    </PremiumCard>

    {!!error && (
      <Text accessibilityRole="alert" style={styles.error}>
        {error}
      </Text>
    )}
    {draftSaveError && !error && (
      <Text accessibilityRole="alert" style={styles.error}>
        لم تُحفظ المسودة على الجهاز
        {'\n'}حرر مساحة ثم حاول مرة أخرى
      </Text>
    )}
    <Pressable
      accessibilityLabel="إرسال الملاحظة"
      accessibilityRole="button"
      accessibilityState={{busy, disabled: !canSubmit}}
      disabled={!canSubmit}
      onPress={onSubmit}
      style={({pressed}) => [
        styles.submit,
        !canSubmit && styles.submitDisabled,
        pressed && styles.pressed,
      ]}>
      <Text style={styles.submitText}>{busy ? 'جارٍ الإرسال' : 'إرسال'}</Text>
    </Pressable>
  </>
);
