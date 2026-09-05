import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import React from 'react';
import {
  Modal,
  Pressable,
  ScrollView,
  Text,
  TextInput,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import Button from '../../components/touchables/Button';
import FullTrackUpgradeSheet from '../../components/FullTrackUpgradeSheet';
import QRCode from '../../components/ui/QRCode';
import {
  MetaPill,
  SectionHeading,
  StatusView,
} from '../../components/ui/PremiumUI';
import {
  Palette,
  Spacing,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {isolateBidirectionalText} from '../../constants/arabicFormatting';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {CertificateArtifactPreview} from './certificates/CertificateArtifactPreview';
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
  const {contentWidth} = useResponsiveLayout();
  const {
    activeCertificateLink,
    activeCourseTitle,
    activeCredential,
    certificatePending,
    certificates,
    closeIssueCertificate,
    closeSelectedCertificate,
    confirmIssueCertificate,
    grantCourses,
    identityOwned,
    issueCourse,
    issueName,
    issuing,
    loadCertificates,
    loadError,
    loading,
    openCertificate,
    openIssueCertificate,
    readyCourses,
    recoverPendingCertificates,
    retryPendingCertificate,
    saveCertificate,
    selectCertificate,
    selectedCertificate,
    selectedGrantCourse,
    selectGrantCourse,
    setIssueName,
    shareCertificate,
    serverSession,
  } = useCertificatesController(resolvedDisplayName);

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
          onAction={recoverPendingCertificates}
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
              accessibilityRole="button"
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
                  pressed && styles.pressed,
                ]}>
                <CertificateArtifactPreview
                  certificateUrl={certificate.certificateUrl}
                  courseTitle={certificate.courseName}
                  pending={certificate.status === 'pending'}
                />
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
                  <Text numberOfLines={2} style={styles.title}>
                    {certificate.courseName}
                  </Text>
                  <View style={styles.verifiedRow}>
                    <View style={styles.verifiedDot} />
                    <Text numberOfLines={1} style={styles.verified}>
                      {certificate.status === 'pending' ? (
                        'اضغط لتحديث الحالة'
                      ) : (
                        <>
                          رقم الاعتماد ·{' '}
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
                  accessibilityLabel={`إصدار شهادة ${course.title}`}
                  accessibilityRole="button"
                  key={`ready-${course.id}`}
                  onPress={() => openIssueCertificate(course)}
                  style={({pressed}) => [
                    styles.lockedCard,
                    styles.readyCard,
                    pressed && styles.pressed,
                  ]}>
                  <View style={[styles.lockedIcon, styles.readyIcon]}>
                    <Text style={styles.lockedIconText}>◇</Text>
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
                  <View style={styles.lockedIcon}>
                    <Text style={styles.lockedIconText}>◇</Text>
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
            <View
              style={[
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
              <Text style={styles.detailTitle}>الاسم على الشهادة</Text>
              <Text style={styles.issueHint}>
                راجعه قبل الإصدار
                {'\n'}لن يتغير بعد ذلك
              </Text>
              <TextInput
                accessibilityLabel="الاسم على الشهادة"
                autoCapitalize="words"
                editable={!issuing}
                maxLength={120}
                onChangeText={setIssueName}
                placeholder="اسمك الكامل"
                placeholderTextColor={Palette.textFaint}
                style={styles.issueInput}
                value={issueName}
              />
              <Button
                disable={Array.from(issueName.trim()).length < 2 || issuing}
                loader={issuing}
                onPress={() => void confirmIssueCertificate()}
                title="إصدار الشهادة"
              />
              <Button
                disable={issuing}
                onPress={closeIssueCertificate}
                title="إلغاء"
                useGradient={false}
              />
            </View>
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
              <CertificateArtifactPreview
                certificateUrl={selectedCertificate?.certificateUrl}
                courseTitle={activeCourseTitle}
              />
              <View
                style={[
                  styles.detailCopy,
                  {
                    paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
                    paddingRight: Math.max(
                      Spacing.xl,
                      insets.right + Spacing.md,
                    ),
                  },
                ]}>
                <View style={styles.verifiedRow}>
                  <View style={styles.verifiedDot} />
                  <Text style={styles.verified}>شهادة موثقة من ركن</Text>
                </View>
                <Text style={styles.detailTitle}>{activeCourseTitle}</Text>
                <Text style={styles.detailMeta}>
                  رقم الاعتماد {'\n'}
                  {isolateBidirectionalText(activeCredential)}
                </Text>
                <MetaPill
                  label="قابلة للتحقق والمشاركة"
                  tone="success"
                  style={styles.badge}
                />
                <View style={styles.qrDestination}>
                  <QRCode value={activeCertificateLink} size={148} />
                  <View style={styles.qrCopy}>
                    <Text style={styles.qrTitle}>امسح للتحقق</Text>
                    <Text numberOfLines={2} style={styles.qrLink}>
                      {isolateBidirectionalText(activeCertificateLink)}
                    </Text>
                    <Text style={styles.qrHint}>
                      يفتح صفحة التحقق من الشهادة
                    </Text>
                  </View>
                </View>
                <Button
                  onPress={() => void openCertificate()}
                  title="فتح صفحة التحقق"
                />
                <Button
                  onPress={() => void shareCertificate()}
                  title="مشاركة رابط الشهادة"
                  useGradient={false}
                />
                {(selectedCertificate?.certificatePdfUrl ||
                  selectedCertificate?.certificateUrl) && (
                  <Button
                    onPress={saveCertificate}
                    title="حفظ الشهادة"
                    useGradient={false}
                  />
                )}
                <Button
                  onPress={() => closeSelectedCertificate()}
                  title="إغلاق"
                  useGradient={false}
                />
              </View>
            </ScrollView>
          </View>
        </View>
      </Modal>
    </View>
  );
}
