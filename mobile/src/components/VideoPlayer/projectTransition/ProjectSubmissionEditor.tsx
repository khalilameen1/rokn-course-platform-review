import React from 'react';
import {Image, Pressable, StyleSheet, Text, TextInput, View} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {rtlRowStyle, textDirection} from '../../../constants/designSystem';
import {Fonts} from '../../../constants/styleConstants';
import type {SelectedProjectFile} from '../types';

type Props = {
  draftSaveError: boolean;
  fileSubmissionEnabled: boolean;
  filePickerDisabled: boolean;
  fileTypesLabel: string;
  maximumFiles: number;
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

const UploadIcon = () => (
  <Svg width={27} height={27} viewBox="0 0 28 28">
    <Path
      d="M14 19V5m0 0L8.8 10.2M14 5l5.2 5.2M5.5 18.2v3.3c0 1.1.9 2 2 2h13c1.1 0 2-.9 2-2v-3.3"
      fill="none"
      stroke="#fff"
      strokeWidth={1.9}
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
    {fileSubmissionEnabled && (
      <Pressable
        accessibilityRole="button"
        accessibilityState={{disabled: filePickerDisabled}}
        disabled={filePickerDisabled}
        style={[
          styles.uploadTarget,
          filePickerDisabled && styles.disabledButton,
        ]}
        onPress={onChooseFile}>
        <View style={styles.uploadIcon}>
          <UploadIcon />
        </View>
        <View style={styles.uploadCopy}>
          <Text style={styles.uploadTitle}>
            {selectedFiles.length
              ? `${selectedFiles.length} ملفات`
              : 'أضف ملفات مشروعك'}
          </Text>
          <Text style={styles.uploadHint}>
            {selectedFiles.length
              ? selectedFiles.length >= maximumFiles
                ? 'اكتمل عدد الملفات'
                : `يمكنك إضافة ${maximumFiles - selectedFiles.length}`
              : fileTypesLabel}
          </Text>
        </View>
      </Pressable>
    )}
    {fileSubmissionEnabled && selectedFiles.length > 0 && (
      <View style={styles.attachmentList}>
        {selectedFiles.map(file => (
          <View
            key={`${file.uri}:${file.name}`}
            style={styles.attachmentChip}>
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
              onPress={() => onRemoveFile(file)}>
              <Text style={styles.attachmentRemove}>×</Text>
            </Pressable>
          </View>
        ))}
      </View>
    )}
    {textSubmissionEnabled && (
      <TextInput
        multiline
        editable={!sending}
        value={note}
        onChangeText={onChangeNote}
        placeholder={
          fileSubmissionEnabled ? 'اكتب ما نفذته أو أضف ملفًا' : 'اكتب ما نفذته'
        }
        placeholderTextColor="rgba(255,255,255,.38)"
        style={styles.submissionNoteInput}
      />
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
      style={[
        styles.primaryButton,
        submitDisabled && styles.disabledButton,
      ]}
      onPress={onSubmit}>
      <Text style={styles.primaryButtonText}>
        {sending ? 'جارٍ التسليم' : 'سلّم المشروع'}
      </Text>
    </Pressable>
  </View>
);

const styles = StyleSheet.create({
  uploadBlock: {marginTop: 22, gap: 12},
  uploadTarget: {
    minHeight: 78,
    borderRadius: 18,
    padding: 13,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 12,
    backgroundColor: 'rgba(255,255,255,.035)',
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: 'rgba(118,169,255,.4)',
  },
  submissionNoteInput: {
    ...textDirection,
    minHeight: 64,
    maxHeight: 120,
    borderRadius: 16,
    paddingHorizontal: 13,
    paddingVertical: 11,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    backgroundColor: 'rgba(255,255,255,.045)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.09)',
  },
  uploadIcon: {
    width: 48,
    height: 48,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(35,111,232,.2)',
  },
  uploadCopy: {flex: 1, minWidth: 0},
  uploadTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 13,
  },
  uploadHint: {
    ...textDirection,
    color: 'rgba(255,255,255,.48)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    lineHeight: 17,
    marginTop: 3,
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
  draftSaveError: {
    ...textDirection,
    color: '#F3A3A3',
    fontFamily: Fonts.medium,
    fontSize: 12,
    lineHeight: 19,
    marginTop: 10,
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
});

export default ProjectSubmissionEditor;
