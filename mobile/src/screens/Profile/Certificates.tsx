import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import React from 'react';
import {Modal, Pressable, ScrollView, Text, View} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {SettingsTermsIcon} from '../../assets/SVG';
import FullTrackUpgradeSheet from '../../components/FullTrackUpgradeSheet';
import {
  MetaPill,
  SectionHeading,
  StatusView,
} from '../../components/ui/PremiumUI';
import {Spacing, useResponsiveLayout} from '../../constants/designSystem';
import {isolateBidirectionalText} from '../../constants/arabicFormatting';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {CertificateArtifactPreview} from './certificates/CertificateArtifactPreview';
import {
  CertificateDetailContent,
  CertificateNameForm,
} from './certificates/CertificateContent';
import {certificateStyles as styles} from './certificates/styles';
import {useCertificatesController} from './certificates/useCertificatesController';

export default function Certificates({
  displayName: resolvedDisplayName,
}: {
  displayName?: string;
}) {
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const {contentWidth, largeText} = useResponsiveLayout();
  const controller = useCertificatesController(resolvedDisplayName);
  const {
    certificatePending,
    certificates,
    closeIssueCertificate,
    closeSelectedCertificate,
    grantCourses,
    identityOwned,
    issueCourse,
    issueReady,
    loadCertificates,
    loadError,
    loading,
    mutationReady,
    openIssueCertificate,
    readyCourses,
    recoverPendingCertificates,
    retryPendingCertificate,
    selectCertificate,
    selectedCertificate,
    selectedGrantCourse,
    selectGrantCourse,
    serverSession,
  } = controller;

  return (
    <View style={styles.container}>
      <SectionHeading title="شهاداتي" />

      {(loading || !identityOwned) &&
      !certificates.length &&
      !readyCourses.length &&
      !grantCourses.length ? (
        <StatusView state="loading" title="جارٍ تحميل شهاداتك" />
      ) : loadError &&
        !certificates.length &&
        !readyCourses.length &&
        !grantCourses.length ? (
        <StatusView
          actionLabel="إعادة المحاولة"
          description={loadError}
          onAction={loadCertificates}
          state="error"
          title="تعذّر تحميل الشهادات"
        />
      ) : certificatePending &&
        !certificates.length &&
        !readyCourses.length &&
        !grantCourses.length ? (
        <StatusView
          actionLabel="إعادة المحاولة"
          description="سنحدّث حالتها تلقائيًا"
          onAction={mutationReady ? recoverPendingCertificates : undefined}
          state="loading"
          title="شهادتك قيد التجهيز"
        />
      ) : !certificates.length &&
        !readyCourses.length &&
        !grantCourses.length ? (
        <StatusView
          actionLabel={
            serverSession === false ? 'تسجيل الدخول' : 'استكشف الكورسات'
          }
          description={
            serverSession === false
              ? 'سجّل الدخول لعرض شهاداتك ومشاركتها'
              : 'تظهر شهادتك هنا بعد إكمال الكورس واستيفاء شروطها'
          }
          onAction={() => {
            if (serverSession === false) {
              openGuestLogin(navigation, {
                name: 'Profile',
                params: {tab: 'certificates'},
              });
              return;
            }
            navigation.navigate('Home');
          }}
          state="empty"
          title={
            serverSession === false
              ? 'شهاداتك مرتبطة بحسابك'
              : 'لا توجد شهادات بعد'
          }
        />
      ) : (
        <>
          {!!loadError && (
            <Text accessibilityRole="alert" style={styles.partialNotice}>
              {loadError}
            </Text>
          )}
          {certificatePending && (
            <Pressable
              accessibilityState={{disabled: !mutationReady}}
              accessibilityRole="button"
              disabled={!mutationReady}
              onPress={() => void recoverPendingCertificates()}
              style={styles.pendingNotice}>
              <Text accessibilityRole="alert" style={styles.partialNotice}>
                هناك شهادة قيد التجهيز
              </Text>
              <Text style={styles.pendingAction}>إعادة المحاولة</Text>
            </Pressable>
          )}
          <View style={styles.grid}>
            {certificates.map(certificate => (
              <Pressable
                accessibilityState={{
                  disabled: certificate.status === 'pending' && !mutationReady,
                }}
                disabled={certificate.status === 'pending' && !mutationReady}
                accessibilityLabel={
                  certificate.status === 'pending'
                    ? `تحديث حالة شهادة ${certificate.courseName}`
                    : `عرض شهادة ${certificate.courseName}`
                }
                accessibilityRole="button"
                key={certificate.publicId}
                onPress={() =>
                  certificate.status === 'pending'
                    ? void retryPendingCertificate(certificate)
                    : selectCertificate(certificate.publicId)
                }
                style={({pressed}) => [
                  styles.card,
                  contentWidth < 700 && styles.cardNarrow,
                  largeText && styles.cardLargeText,
                  pressed && styles.pressed,
                ]}>
                <View
                  style={[
                    styles.artifactThumb,
                    largeText && styles.artifactThumbLargeText,
                  ]}>
                  <CertificateArtifactPreview
                    compact={!largeText}
                    certificateUrl={certificate.certificateUrl}
                    courseTitle={certificate.courseName}
                    pending={certificate.status === 'pending'}
                  />
                </View>
                <View style={styles.cardCopy}>
                  <MetaPill
                    label={
                      certificate.status === 'pending'
                        ? 'قيد التجهيز'
                        : 'شهادة موثقة'
                    }
                    tone={
                      certificate.status === 'pending' ? 'neutral' : 'success'
                    }
                  />
                  <Text style={styles.title}>{certificate.courseName}</Text>
                  <View style={styles.verifiedRow}>
                    <View style={styles.verifiedDot} />
                    <Text numberOfLines={1} style={styles.verified}>
                      {certificate.status === 'pending' ? (
                        'اضغط لتحديث الحالة'
                      ) : (
                        <>
                          رقم الشهادة ·{' '}
                          {isolateBidirectionalText(certificate.publicId)}
                        </>
                      )}
                    </Text>
                  </View>
                </View>
              </Pressable>
            ))}
          </View>
          {!!readyCourses.length && (
            <View style={styles.lockedSection}>
              <Text style={styles.lockedHeading}>جاهزة للإصدار</Text>
              {readyCourses.map(course => (
                <Pressable
                  accessibilityState={{disabled: !issueReady}}
                  accessibilityLabel={`إصدار شهادة ${course.title}`}
                  accessibilityRole="button"
                  disabled={!issueReady}
                  key={`ready-${course.id}`}
                  onPress={() => openIssueCertificate(course)}
                  style={({pressed}) => [
                    styles.lockedCard,
                    styles.readyCard,
                    pressed && styles.pressed,
                  ]}>
                  <View
                    accessibilityElementsHidden
                    importantForAccessibility="no-hide-descendants"
                    style={styles.lockedIcon}>
                    <SettingsTermsIcon width={24} height={24} />
                  </View>
                  <View style={styles.lockedCopy}>
                    <Text numberOfLines={2} style={styles.lockedTitle}>
                      {course.title}
                    </Text>
                    <Text style={styles.lockedMeta}>
                      اختر الاسم ثم أصدر الشهادة
                    </Text>
                  </View>
                  <Text style={styles.readyAction}>إصدار</Text>
                </Pressable>
              ))}
            </View>
          )}
          {!!grantCourses.length && (
            <View style={styles.lockedSection}>
              <Text style={styles.lockedHeading}>شهادات تنتظر التفعيل</Text>
              <Text style={styles.lockedIntro}>
                أنهيت الكورس بمنحتك كاملة
                {'\n'}يمكنك إضافة الشهادة والاستفسارات من هنا
              </Text>
              {grantCourses.map(course => (
                <Pressable
                  accessibilityLabel={`تفعيل شهادة ${course.title}`}
                  accessibilityRole="button"
                  key={`grant-${course.id}`}
                  onPress={() => selectGrantCourse(course.id)}
                  style={({pressed}) => [
                    styles.lockedCard,
                    pressed && styles.pressed,
                  ]}>
                  <View
                    accessibilityElementsHidden
                    importantForAccessibility="no-hide-descendants"
                    style={styles.lockedIcon}>
                    <SettingsTermsIcon width={24} height={24} />
                  </View>
                  <View style={styles.lockedCopy}>
                    <Text numberOfLines={2} style={styles.lockedTitle}>
                      {course.title}
                    </Text>
                    <Text style={styles.lockedMeta}>
                      أنهيت الكورس · الشهادة اختيارية
                    </Text>
                  </View>
                  <Text style={styles.lockedAction}>عرض التفاصيل</Text>
                </Pressable>
              ))}
            </View>
          )}
        </>
      )}

      <FullTrackUpgradeSheet
        completed
        courseId={selectedGrantCourse?.id || ''}
        courseTitle={selectedGrantCourse?.title || ''}
        onClose={() => selectGrantCourse(null)}
        onUpgraded={loadCertificates}
        visible={Boolean(selectedGrantCourse)}
      />

      <Modal
        animationType={reducedMotion ? 'none' : 'fade'}
        onRequestClose={closeIssueCertificate}
        statusBarTranslucent
        transparent
        visible={Boolean(issueCourse)}>
        <View style={styles.overlay}>
          <View
            accessibilityLabel="إصدار الشهادة"
            accessibilityViewIsModal
            style={[styles.sheet, styles.issueSheet]}>
            <ScrollView
              automaticallyAdjustKeyboardInsets
              keyboardShouldPersistTaps="handled"
              contentContainerStyle={[
                styles.detailCopy,
                {
                  paddingBottom: Math.max(
                    Spacing.xl,
                    insets.bottom + Spacing.md,
                  ),
                  paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
                  paddingRight: Math.max(Spacing.xl, insets.right + Spacing.md),
                },
              ]}>
              <CertificateNameForm
                controller={controller}
                onCancel={closeIssueCertificate}
              />
            </ScrollView>
          </View>
        </View>
      </Modal>

      <Modal
        animationType={reducedMotion ? 'none' : 'slide'}
        onRequestClose={() => closeSelectedCertificate()}
        statusBarTranslucent
        transparent
        visible={Boolean(selectedCertificate)}>
        <View style={styles.overlay}>
          <View
            accessibilityLabel="تفاصيل الشهادة"
            accessibilityViewIsModal
            style={styles.sheet}>
            <View
              style={[
                styles.detailHeader,
                {
                  paddingLeft: Math.max(Spacing.md, insets.left + Spacing.md),
                  paddingRight: Math.max(Spacing.md, insets.right + Spacing.md),
                },
              ]}>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel="إغلاق تفاصيل الشهادة"
                onPress={() => closeSelectedCertificate()}
                style={({pressed}) => [
                  styles.closeDetail,
                  pressed && styles.detailActionPressed,
                ]}>
                <Text style={styles.closeDetailText}>إغلاق</Text>
              </Pressable>
            </View>
            <ScrollView
              contentContainerStyle={[
                styles.sheetContent,
                {
                  paddingBottom: Math.max(
                    Spacing.xl,
                    insets.bottom + Spacing.md,
                  ),
                },
              ]}
              showsVerticalScrollIndicator={false}>
              <CertificateDetailContent controller={controller} />
            </ScrollView>
          </View>
        </View>
      </Modal>
    </View>
  );
}
