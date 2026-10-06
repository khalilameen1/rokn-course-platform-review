import React from 'react';
import {Pressable, Text, TextInput, View} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import Button from '../../../components/touchables/Button';
import QRCode from '../../../components/ui/QRCode';
import {StatusView} from '../../../components/ui/PremiumUI';
import {
  Palette,
  Spacing,
  useResponsiveLayout,
} from '../../../constants/designSystem';
import {isolateBidirectionalText} from '../../../constants/arabicFormatting';
import {CertificateArtifactPreview} from './CertificateArtifactPreview';
import {certificateStyles as styles} from './styles';
import type {useCertificatesController} from './useCertificatesController';

type Controller = ReturnType<typeof useCertificatesController>;

type CertificateReadState = Pick<
  Controller,
  'loadError' | 'loading' | 'mutationReady' | 'loadCertificates'
>;

/** A failed status read is retried as a read, never as artifact generation. */
export const CertificateReadError = ({
  controller,
}: {
  controller: CertificateReadState;
}) => {
  if (!controller.loadError || controller.loading) return null;
  return (
    <StatusView
      state="error"
      title="تعذّر تحديث الشهادة"
      description={controller.loadError}
      actionLabel="إعادة المحاولة"
      onAction={
        controller.mutationReady
          ? () => void controller.loadCertificates()
          : undefined
      }
    />
  );
};

export const CertificateNameForm = ({
  controller,
  onCancel,
}: {
  controller: CertificateReadState &
    Pick<
      Controller,
      | 'issueName'
      | 'issueReady'
      | 'issuing'
      | 'setIssueName'
      | 'confirmIssueCertificate'
    >;
  onCancel: () => void;
}) => (
  <>
    <CertificateReadError controller={controller} />
    <Text style={styles.detailTitle}>الاسم على الشهادة</Text>
    <Text style={styles.issueHint}>
      راجعه قبل الإصدار{'\n'}لن يتغير بعد ذلك
    </Text>
    <TextInput
      accessibilityLabel="الاسم على الشهادة"
      autoCapitalize="words"
      editable={!controller.issuing}
      maxLength={120}
      onChangeText={controller.setIssueName}
      placeholder="اسمك الكامل"
      placeholderTextColor={Palette.textFaint}
      style={styles.issueInput}
      value={controller.issueName}
    />
    <Button
      disable={
        Array.from(controller.issueName.trim()).length < 2 ||
        !controller.issueReady
      }
      loader={controller.issuing}
      onPress={() => void controller.confirmIssueCertificate()}
      title="إصدار الشهادة"
    />
    <Button
      disable={controller.issuing}
      onPress={onCancel}
      title="إلغاء"
      useGradient={false}
    />
  </>
);

/** Profile and course entry display the same server artifact and native actions. */
export const CertificateDetailContent = ({
  controller,
}: {
  controller: Pick<
    Controller,
    | 'selectedCertificate'
    | 'activeCourseTitle'
    | 'activeCredential'
    | 'activeCertificateQrDestination'
    | 'shareCertificate'
    | 'saveCertificate'
    | 'cancelCertificateDownload'
    | 'openCertificate'
  >;
}) => {
  const insets = useSafeAreaInsets();
  const {largeText} = useResponsiveLayout();
  const {
    selectedCertificate,
    activeCourseTitle,
    activeCredential,
    activeCertificateQrDestination,
    shareCertificate,
    saveCertificate,
    cancelCertificateDownload,
    openCertificate,
  } = controller;
  return (
    <>
      <CertificateArtifactPreview
        certificateUrl={selectedCertificate?.certificateUrl}
        courseTitle={activeCourseTitle}
      />
      <View
        style={[
          styles.detailCopy,
          {
            paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
            paddingRight: Math.max(Spacing.xl, insets.right + Spacing.md),
          },
        ]}>
        <Text accessibilityRole="header" style={styles.detailTitle}>
          {activeCourseTitle}
        </Text>
        <Text style={styles.detailMeta}>
          رقم الشهادة{'\n'}
          {isolateBidirectionalText(activeCredential)}
        </Text>
        <View style={styles.detailActions}>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="مشاركة الشهادة"
            onPress={() => void shareCertificate()}
            style={({pressed}) => [
              styles.shareAction,
              pressed && styles.shareActionPressed,
            ]}>
            <Text style={styles.shareActionText}>مشاركة الشهادة</Text>
          </Pressable>
          <View
            style={[
              styles.secondaryActions,
              largeText && styles.secondaryActionsLargeText,
            ]}>
            {(selectedCertificate?.certificatePdfUrl ||
              selectedCertificate?.certificateUrl) && (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={
                  cancelCertificateDownload
                    ? `إلغاء تنزيل شهادة ${activeCourseTitle}`
                    : 'حفظ الشهادة'
                }
                onPress={cancelCertificateDownload || saveCertificate}
                style={({pressed}) => [
                  styles.secondaryAction,
                  largeText && styles.secondaryActionLargeText,
                  pressed && styles.detailActionPressed,
                ]}>
                <Text style={styles.secondaryActionText}>
                  {cancelCertificateDownload ? 'إلغاء التنزيل' : 'حفظ الشهادة'}
                </Text>
              </Pressable>
            )}
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="التحقق من الشهادة"
              onPress={() => void openCertificate()}
              style={({pressed}) => [
                styles.secondaryAction,
                largeText && styles.secondaryActionLargeText,
                pressed && styles.detailActionPressed,
              ]}>
              <Text style={styles.secondaryActionText}>التحقق من الشهادة</Text>
            </Pressable>
          </View>
        </View>
        {activeCertificateQrDestination && (
          <View
            style={[
              styles.qrDestination,
              largeText && styles.qrDestinationLargeText,
            ]}>
            <QRCode
              accessibilityLabel={
                activeCertificateQrDestination.type === 'portfolio'
                  ? 'رمز QR لعرض الأعمال'
                  : 'رمز QR للتحقق من الشهادة'
              }
              value={activeCertificateQrDestination.url}
              size={148}
            />
            <View style={styles.qrCopy}>
              <Text style={styles.qrTitle}>
                {activeCertificateQrDestination.title}
              </Text>
            </View>
          </View>
        )}
      </View>
    </>
  );
};
