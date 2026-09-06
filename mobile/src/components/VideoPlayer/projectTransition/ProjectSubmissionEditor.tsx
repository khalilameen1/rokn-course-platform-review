import React from 'react';
import {
  Image,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {
  Palette,
  rtlRowStyle,
  textDirection,
} from '../../../constants/designSystem';
import {Fonts} from '../../../constants/styleConstants';
import type {SelectedProjectFile} from '../types';

type Props = {
  draftSaveError: boolean;
  fileSubmissionEnabled: boolean;
  filePickerDisabled: boolean;
  fileTypesLabel: string;
  maximumFiles: number;
  maximumFileSizeLabel?: string;
  note: string;
  selectedFiles: SelectedProjectFile[];
  sending: boolean;
  submitDisabled: boolean;
  textSubmissionEnabled: boolean;
  onChangeNote: (value: string) => void;
  onChooseFile: () => void;
  onRemoveFile: (file: SelectedProjectFile) => void;
  onSubmit: () => void;
};

const AttachmentIcon = () => (
  <Svg width={22} height={22} viewBox="0 0 24 24">
    <Path
      d="m8 12 6-6a3.5 3.5 0 0 1 5 5l-8 8a5 5 0 0 1-7-7l8-8m-6 10 7-7"
      fill="none"
      stroke={Palette.textMuted}
      strokeWidth={1.7}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const ProjectSubmissionEditor = ({
  draftSaveError,
  fileSubmissionEnabled,
  filePickerDisabled,
  fileTypesLabel,
  maximumFiles,
  maximumFileSizeLabel,
  note,
  selectedFiles,
  sending,
  submitDisabled,
  textSubmissionEnabled,
  onChangeNote,
  onChooseFile,
  onRemoveFile,
  onSubmit,
}: Props) => (
  <View style={styles.uploadBlock}>
    <Text accessibilityRole="header" style={styles.sectionTitle}>
      تسليمك
    </Text>
    {textSubmissionEnabled && (
      <View style={styles.noteField}>
        <Text style={styles.fieldLabel}>ما نفذته</Text>
        <TextInput
          accessibilityLabel="ما نفذته"
          multiline
          editable={!sending}
          value={note}
          onChangeText={onChangeNote}
          placeholder={
            fileSubmissionEnabled ? 'اكتب مشروعك أو وصفه' : 'اكتب مشروعك هنا'
          }
          placeholderTextColor={Palette.textFaint}
          textAlignVertical="top"
          style={styles.submissionNoteInput}
        />
      </View>
    )}
    {fileSubmissionEnabled && (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="إضافة ملف"
        accessibilityState={{disabled: filePickerDisabled}}
        disabled={filePickerDisabled}
        style={[
          styles.uploadTarget,
          filePickerDisabled && styles.disabledButton,
        ]}
        onPress={onChooseFile}>
        <View style={styles.attachmentIcon}>
          <AttachmentIcon />
        </View>
        <View style={styles.uploadCopy}>
          <Text style={styles.uploadTitle}>إضافة ملف</Text>
          <Text style={styles.uploadHint}>
            {selectedFiles.length
              ? selectedFiles.length >= maximumFiles
                ? 'اكتمل عدد الملفات'
                : `يمكنك إضافة ${maximumFiles - selectedFiles.length}`
              : fileTypesLabel}
          </Text>
          {!!maximumFileSizeLabel && (
            <Text style={styles.uploadHint}>
              الحد الأقصى للملف {maximumFileSizeLabel}
            </Text>
          )}
        </View>
      </Pressable>
    )}
    {fileSubmissionEnabled && selectedFiles.length > 0 && (
      <View style={styles.attachmentList}>
        {selectedFiles.map(file => (
          <View key={`${file.uri}:${file.name}`} style={styles.attachmentChip}>
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
              accessibilityState={{disabled: sending}}
              disabled={sending}
              style={styles.removeButton}
              onPress={() => onRemoveFile(file)}>
              <Text style={styles.attachmentRemove}>×</Text>
            </Pressable>
          </View>
        ))}
      </View>
    )}
    {draftSaveError && (
      <Text accessibilityRole="alert" style={styles.draftSaveError}>
        تعذّر حفظ المسودة على الجهاز
        {'\n'}اترك الصفحة مفتوحة حتى تسلّم المشروع
      </Text>
    )}
    <Pressable
      accessibilityRole="button"
      accessibilityState={{busy: sending, disabled: submitDisabled}}
      disabled={submitDisabled}
      style={[styles.primaryButton, submitDisabled && styles.disabledButton]}
      onPress={onSubmit}>
      <Text style={styles.primaryButtonText}>
        {sending ? 'جارٍ التسليم' : 'سلّم المشروع'}
      </Text>
    </Pressable>
  </View>
);

const styles = StyleSheet.create({
  uploadBlock: {marginTop: 24, gap: 14},
  sectionTitle: {
    ...textDirection,
    color: Palette.text,
    fontFamily: Fonts.semiBold,
    fontSize: 16,
    lineHeight: 24,
  },
  noteField: {gap: 8},
  fieldLabel: {
    ...textDirection,
    color: Palette.textMuted,
    fontFamily: Fonts.medium,
    fontSize: 14,
    lineHeight: 22,
  },
  uploadTarget: {
    minHeight: 48,
    paddingVertical: 4,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
  },
  submissionNoteInput: {
    ...textDirection,
    minHeight: 104,
    maxHeight: 176,
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    color: Palette.text,
    fontFamily: Fonts.regular,
    fontSize: 15,
    lineHeight: 24,
    backgroundColor: Palette.surface,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
  },
  attachmentIcon: {
    width: 28,
    height: 32,
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
  },
  uploadCopy: {flex: 1, minWidth: 0},
  uploadTitle: {
    ...textDirection,
    color: Palette.text,
    fontFamily: Fonts.semiBold,
    fontSize: 14,
    lineHeight: 22,
  },
  uploadHint: {
    ...textDirection,
    color: Palette.textMuted,
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    marginTop: 2,
  },
  primaryButton: {
    width: '100%',
    minHeight: 52,
    borderRadius: 12,
    paddingHorizontal: 18,
    paddingVertical: 13,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.primary,
  },
  disabledButton: {opacity: 0.38},
  primaryButtonText: {
    color: Palette.text,
    fontFamily: Fonts.bold,
    fontSize: 15,
    lineHeight: 24,
    textAlign: 'center',
  },
  draftSaveError: {
    ...textDirection,
    color: Palette.danger,
    fontFamily: Fonts.medium,
    fontSize: 12,
    lineHeight: 19,
  },
  attachmentList: {gap: 8},
  attachmentChip: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 8,
    borderRadius: 11,
    paddingHorizontal: 8,
    paddingVertical: 4,
    backgroundColor: Palette.surface,
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
  removeButton: {
    width: 48,
    minHeight: 48,
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
  },
  attachmentRemove: {color: Palette.textMuted, fontSize: 24, lineHeight: 28},
  attachmentPreview: {width: 34, height: 34, borderRadius: 8},
});

export default ProjectSubmissionEditor;
