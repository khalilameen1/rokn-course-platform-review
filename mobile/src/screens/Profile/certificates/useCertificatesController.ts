import {useFocusEffect} from '@react-navigation/native';
import {useCallback, useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';
import {useSelector} from 'react-redux';
import {
  getCertificates,
  getCachedCertificates,
  getLearningCourses,
  hasSession,
  issueCertificate,
  recoverCertificate,
  type Certificate as CertificateDto,
  type CourseProgress,
} from '../../../services/roknApi';
import {openExternalUrlOnce, shareOnce} from '../../../services/systemActions';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractUserProfile,
  sessionIdentityKey,
} from '../../../constants/helpers';
import type {RootState} from '../../../store/store';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {openCourseAttachment} from '../../../components/VideoPlayer/attachmentActions';
import {useAppActiveState} from '../../../hooks/useAppActiveState';
import {settleWithin} from '../../../utils/settleWithin';

export function useCertificatesController(resolvedDisplayName?: string) {
  const appIsActive = useAppActiveState();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const user = extractUserProfile(storedUser);
  const displayName = resolvedDisplayName || user?.name || '';
  const identityKey = sessionIdentityKey(storedUser);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [certificatePending, setCertificatePending] = useState(false);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [certificates, setCertificates] = useState<CertificateDto[]>([]);
  const [readyCourses, setReadyCourses] = useState<CourseProgress[]>([]);
  const [grantCourses, setGrantCourses] = useState<CourseProgress[]>([]);
  const [selectedGrantId, setSelectedGrantId] = useState<string | null>(null);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [issueCourseId, setIssueCourseId] = useState<string | null>(null);
  const [issueName, setIssueName] = useState('');
  const [issuing, setIssuing] = useState(false);
  const loadGeneration = useRef(0);
  const issueFlight = useRef<symbol | null>(null);
  const pendingPollAttempts = useRef(0);
  const acceptedIssueCourseIds = useRef(new Set<string>());
  const identityOwnerRef = useRef(identityKey);

  useEffect(() => {
    loadGeneration.current += 1;
    issueFlight.current = null;
    pendingPollAttempts.current = 0;
    acceptedIssueCourseIds.current.clear();
    setLoading(true);
    setLoadError('');
    setCertificatePending(false);
    setSelectedId(null);
    setCertificates([]);
    setReadyCourses([]);
    setGrantCourses([]);
    setSelectedGrantId(null);
    setServerSession(null);
    setIssueCourseId(null);
    setIssueName('');
    setIssuing(false);
    identityOwnerRef.current = identityKey;
  }, [identityKey]);

  const loadCertificates = useCallback(async () => {
    const generation = ++loadGeneration.current;
    const isCurrent = () =>
      loadGeneration.current === generation &&
      identityOwnerRef.current === identityKey;
    setLoading(true);
    setLoadError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      const sessionAvailable = await hasSession();
      assertAccountSessionBoundary(boundary);
      if (isCurrent()) setServerSession(sessionAvailable);
      if (sessionAvailable) {
        const certificatesFlight = getCertificates(boundary);
        const learningFlight = getLearningCourses();
        const remoteReads = Promise.allSettled([
          certificatesFlight,
          learningFlight,
        ]);
        const cachedCertificates = await settleWithin(
          getCachedCertificates(boundary),
          [],
        );
        assertAccountSessionBoundary(boundary);
        if (isCurrent() && cachedCertificates.length) {
          setCertificates(
            cachedCertificates.filter(item => item.status !== 'revoked'),
          );
          setCertificatePending(
            cachedCertificates.some(item => item.status === 'pending'),
          );
        }
        const [certificatesResult, learningResult] = await remoteReads;
        assertAccountSessionBoundary(boundary);
        if (
          certificatesResult.status === 'rejected' &&
          learningResult.status === 'rejected'
        ) {
          throw certificatesResult.reason;
        }
        if (
          certificatesResult.status === 'rejected' ||
          learningResult.status === 'rejected'
        ) {
          setLoadError('نعرض المتاح الآن وسنحدّث الباقي عند عودة الاتصال');
        }
        if (learningResult.status === 'fulfilled' && isCurrent()) {
          setGrantCourses(
            learningResult.value.filter(
              course =>
                course.accessType === 'scholarship' &&
                !course.certificateAvailable &&
                (course.progress >= 100 ||
                  (course.totalSections > 0 &&
                    course.completedSections >= course.totalSections)),
            ),
          );
        }
        if (certificatesResult.status === 'fulfilled') {
          const remoteCertificates = certificatesResult.value;
          remoteCertificates.forEach(item => {
            acceptedIssueCourseIds.current.delete(item.courseId);
          });
          const hasPendingCertificate =
            acceptedIssueCourseIds.current.size > 0 ||
            remoteCertificates.some(item => item.status === 'pending');
          if (learningResult.status === 'fulfilled') {
            const certificateByCourse = new Map(
              remoteCertificates.map(item => [item.courseId, item]),
            );
            if (isCurrent()) {
              // certificate_available is the server-side eligibility verdict;
              // progress alone does not include every project/evidence gate.
              setReadyCourses(
                learningResult.value
                  .filter(course => course.certificateAvailable)
                  .filter(
                    course =>
                      !certificateByCourse.has(course.id) &&
                      !acceptedIssueCourseIds.current.has(course.id),
                  ),
              );
            }
          }
          if (isCurrent()) {
            setCertificatePending(hasPendingCertificate);
            if (!hasPendingCertificate) pendingPollAttempts.current = 0;
            setCertificates(
              remoteCertificates.filter(item => item.status !== 'revoked'),
            );
          }
        } else if (isCurrent()) {
          setReadyCourses([]);
        }
        return;
      }
      if (isCurrent()) {
        setGrantCourses([]);
        setReadyCourses([]);
        setCertificatePending(false);
        setCertificates([]);
      }
    } catch (error: unknown) {
      if (isCurrent()) {
        if (
          error instanceof Error &&
          error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        ) {
          return;
        }
        setLoadError('تعذّر التحقق من شهاداتك الآن\nشهاداتك محفوظة');
      }
    } finally {
      if (isCurrent()) setLoading(false);
    }
  }, [identityKey]);

  useFocusEffect(
    useCallback(() => {
      if (!appIsActive) return () => undefined;
      loadCertificates();
      return () => {
        loadGeneration.current += 1;
      };
    }, [appIsActive, loadCertificates]),
  );

  const recoverPendingCertificates = useCallback(async () => {
    if (issueFlight.current) return;
    const flight = Symbol('certificate-recovery-all');
    issueFlight.current = flight;
    pendingPollAttempts.current = 0;
    try {
      const boundary = await captureAccountSessionBoundary();
      const pendingCourseIds = Array.from(
        new Set(
          [
            ...certificates
              .filter(certificate => certificate.status === 'pending')
              .map(certificate => certificate.courseId),
            ...acceptedIssueCourseIds.current,
          ],
        ),
      );
      // POST issue is idempotent for user + course. For an existing pending
      // row it only re-enqueues artifact recovery; one controller flight keeps
      // repeated taps from wasting the mutation throttle.
      const recoveryResults = pendingCourseIds.length
        ? await Promise.allSettled(
          pendingCourseIds.map(courseId =>
            recoverCertificate(courseId, boundary),
          ),
        )
        : [];
      assertAccountSessionBoundary(boundary);
      if (issueFlight.current !== flight) return;
      await loadCertificates();
      if (
        recoveryResults.length > 0 &&
        recoveryResults.every(result => result.status === 'rejected') &&
        issueFlight.current === flight
      ) {
        setLoadError('تعذّر تحديث الشهادة الآن\nحاول مرة أخرى');
      }
    } catch (error: unknown) {
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        return;
      }
      if (issueFlight.current === flight) {
        setLoadError('تعذّر تحديث الشهادة الآن\nحاول مرة أخرى');
      }
    } finally {
      if (issueFlight.current === flight) issueFlight.current = null;
    }
  }, [certificates, loadCertificates]);

  useEffect(() => {
    if (
      !appIsActive ||
      !certificatePending ||
      loading ||
      pendingPollAttempts.current >= 5
    ) {
      return undefined;
    }
    const delayMs = Math.min(
      20000,
      3000 * Math.pow(1.7, pendingPollAttempts.current),
    );
    const timer = setTimeout(() => {
      pendingPollAttempts.current += 1;
      // Polling is a read journey. Re-enqueueing certificate generation is a
      // write and remains behind the learner's explicit retry action below.
      void loadCertificates();
    }, delayMs);
    return () => clearTimeout(timer);
  }, [appIsActive, certificatePending, loadCertificates, loading]);

  const selectedCertificate =
    certificates.find(certificate => certificate.publicId === selectedId) ||
    null;
  const selectedGrantCourse =
    grantCourses.find(course => course.id === selectedGrantId) || null;
  const issueCourse =
    readyCourses.find(course => course.id === issueCourseId) || null;
  const activeCourseTitle = selectedCertificate?.courseName || '';
  const activeCredential = selectedCertificate?.publicId || '';
  const activeCertificateLink = selectedCertificate?.verificationUrl || '';
  const activeCertificateQrDestination =
    selectedCertificate?.qrDestination || null;
  const openCertificate = async () => {
    if (!selectedCertificate || !activeCertificateLink) return;
    try {
      await openExternalUrlOnce(activeCertificateLink);
    } catch {
      Alert.alert('تعذّر فتح الشهادة', 'حاول مرة أخرى');
    }
  };

  const shareCertificate = async () => {
    if (!selectedCertificate || !activeCertificateLink) return;
    try {
      await shareOnce(`certificate:${activeCredential}`, {
        message: `شهادتي الموثقة على ركن\n${activeCertificateLink}`,
        url: activeCertificateLink,
      });
    } catch {
      Alert.alert('تعذّرت المشاركة', 'حاول مرة أخرى');
    }
  };

  const saveCertificate = () => {
    if (
      !selectedCertificate?.certificatePdfUrl &&
      !selectedCertificate?.certificateUrl
    ) {
      return;
    }
    const asPdf = Boolean(selectedCertificate.certificatePdfUrl);
    void openCourseAttachment({
      id: `certificate-${selectedCertificate.publicId}`,
      title: `شهادة ${selectedCertificate.courseName}`,
      url:
        selectedCertificate.certificatePdfUrl ||
        selectedCertificate.certificateUrl ||
        '',
      fileType: asPdf ? 'pdf' : 'image/png',
      mimeType: asPdf ? 'application/pdf' : 'image/png',
      downloadVersion: selectedCertificate.publicId,
      external: false,
      platform: 'mobile',
      temporary: false,
    });
  };

  const openIssueCertificate = (course: CourseProgress) => {
    setIssueName(displayName);
    setIssueCourseId(course.id);
  };

  const closeIssueCertificate = () => {
    if (issuing) return;
    setIssueCourseId(null);
    setIssueName('');
  };

  const confirmIssueCertificate = async () => {
    if (!issueCourse || issuing || issueFlight.current) return;
    const holderName = issueName.trim().replace(/\s+/g, ' ');
    if (Array.from(holderName).length < 2) {
      Alert.alert('اكتب اسمك', 'هذا الاسم سيظهر على الشهادة');
      return;
    }
    const flight = Symbol('certificate-issue');
    issueFlight.current = flight;
    setIssuing(true);
    try {
      const boundary = await captureAccountSessionBoundary();
      const issued = await issueCertificate(
        issueCourse.id,
        holderName,
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      if (issueFlight.current !== flight) return;
      setReadyCourses(current =>
        current.filter(course => course.id !== issueCourse.id),
      );
      setIssueCourseId(null);
      setIssueName('');
      if (issued?.status === 'active') {
        setCertificates(current => [
          issued,
          ...current.filter(
            certificate => certificate.publicId !== issued.publicId,
          ),
        ]);
        setSelectedId(issued.publicId);
      } else {
        acceptedIssueCourseIds.current.add(issueCourse.id);
        pendingPollAttempts.current = 0;
        setCertificatePending(true);
        // Keep ownership until the read endpoint has observed the accepted
        // issue. Otherwise a fast second tap can POST the same issue again.
        await loadCertificates();
      }
    } catch (error: unknown) {
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        return;
      }
      Alert.alert(
        'تعذّر إصدار الشهادة',
        learnerErrorMessage(error, 'حاول مرة أخرى'),
      );
      // A timed-out POST has an unknown outcome. Keep the issue single-flight
      // until the authoritative list has been reconciled so a fast second tap
      // cannot request the same credential again.
      await loadCertificates();
    } finally {
      if (issueFlight.current === flight) {
        issueFlight.current = null;
        setIssuing(false);
      }
    }
  };

  const retryPendingCertificate = async (certificate: CertificateDto) => {
    if (issueFlight.current) return;
    const flight = Symbol('certificate-recovery');
    issueFlight.current = flight;
    try {
      const boundary = await captureAccountSessionBoundary();
      // The original issue already froze the learner name. Reissuing without
      // a name addresses the same canonical row and only asks the backend to
      // recover its missing artifact; it cannot create a second credential.
      await recoverCertificate(certificate.courseId, boundary);
      assertAccountSessionBoundary(boundary);
      if (issueFlight.current !== flight) return;
      pendingPollAttempts.current = 0;
      await loadCertificates();
    } catch (error: unknown) {
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        return;
      }
      Alert.alert(
        'تعذّر تجهيز الشهادة',
        learnerErrorMessage(error, 'حاول مرة أخرى'),
      );
    } finally {
      if (issueFlight.current === flight) {
        issueFlight.current = null;
      }
    }
  };

  return {
    activeCertificateQrDestination,
    activeCourseTitle,
    activeCredential,
    certificatePending,
    certificates,
    closeIssueCertificate,
    closeSelectedCertificate: () => setSelectedId(null),
    confirmIssueCertificate,
    grantCourses,
    identityOwned: identityOwnerRef.current === identityKey,
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
    selectCertificate: setSelectedId,
    selectedCertificate,
    selectedGrantCourse,
    selectGrantCourse: setSelectedGrantId,
    shareCertificate,
    setIssueName,
    serverSession,
  };
}
