import {useNavigation, useRoute} from '@react-navigation/native';
import React, {useEffect, useRef} from 'react';
import {View} from 'react-native';
import {useSelector} from 'react-redux';
import {Container, Content} from '../components/containers/Containers';
import {StatusView} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {sessionIdentityKey} from '../constants/helpers';
import {safeRoknRouteId} from '../navigation/deepLinks';
import {goBackOrHome} from '../navigation/RootNavigationHelper';
import type {RootNavigation, RootRoute} from '../navigation/types';
import type {RootState} from '../store/store';
import {
  CertificateDetailContent,
  CertificateNameForm,
  CertificateReadError,
} from './Profile/certificates/CertificateContent';
import {certificateStyles as styles} from './Profile/certificates/styles';
import {useCertificatesController} from './Profile/certificates/useCertificatesController';

export default function CourseCertificate() {
  const {params} = useRoute<RootRoute<'CourseCertificate'>>();
  const identityKey = useSelector((state: RootState) =>
    sessionIdentityKey(state.auth.userData),
  );
  const courseId = safeRoknRouteId(params.courseId);
  return (
    <Container noPadding>
      <HeaderWithBack title="شهادة الكورس" />
      {courseId ? (
        <CourseCertificateJourney
          key={`${identityKey}:${courseId}`}
          courseId={courseId}
        />
      ) : (
        <StatusView state="error" title="تعذّر فتح الشهادة" />
      )}
    </Container>
  );
}

const CourseCertificateJourney = ({courseId}: {courseId: string}) => {
  const navigation = useNavigation<RootNavigation>();
  const controller = useCertificatesController(undefined, courseId);
  const nameOffered = useRef(false);
  const activeCertificate = controller.certificates.find(
    item => item.status === 'active',
  );
  const pendingCertificate = controller.certificates.find(
    item => item.status === 'pending',
  );
  const readyCourse = controller.readyCourses[0];

  useEffect(() => {
    if (controller.loading || !controller.identityOwned) return;
    if (activeCertificate && !controller.selectedCertificate) {
      controller.selectCertificate(activeCertificate.publicId);
    } else if (readyCourse && controller.issueReady && !nameOffered.current) {
      // Opening the form is not issuance. Only its explicit button can POST.
      nameOffered.current = true;
      controller.openIssueCertificate(readyCourse);
    }
  }, [activeCertificate, controller, readyCourse]);

  let content: React.ReactNode;
  if (controller.selectedCertificate) {
    content = <CertificateDetailContent controller={controller} />;
  } else if (controller.issueCourse) {
    content = (
      <View style={styles.detailCopy}>
        <CertificateNameForm
          controller={controller}
          onCancel={() => goBackOrHome(navigation)}
        />
      </View>
    );
  } else if (
    controller.loading ||
    !controller.identityOwned ||
    (readyCourse && !controller.loadError)
  ) {
    content = <StatusView state="loading" title="جارٍ تحميل الشهادة" />;
  } else if (controller.loadError) {
    content = <CertificateReadError controller={controller} />;
  } else if (controller.certificatePending) {
    content = (
      <StatusView
        state="loading"
        title="شهادتك قيد التجهيز"
        actionLabel="إعادة المحاولة"
        onAction={
          controller.mutationReady
            ? () => {
                if (pendingCertificate) {
                  void controller.retryPendingCertificate(pendingCertificate);
                } else {
                  void controller.recoverPendingCertificates();
                }
              }
            : undefined
        }
      />
    );
  } else {
    content = (
      <StatusView
        state="empty"
        title="الشهادة غير متاحة الآن"
        actionLabel="العودة للكورس"
        onAction={() => navigation.navigate('CourseDetails', {courseId})}
      />
    );
  }
  return (
    <Content noPadding paddingBottom={0}>
      {content}
    </Content>
  );
};
