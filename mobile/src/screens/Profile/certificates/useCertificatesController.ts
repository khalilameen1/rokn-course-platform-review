import {useFocusEffect, useIsFocused} from '@react-navigation/native';
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
import {useAttachmentDownloadCancellation} from '../../../components/VideoPlayer/attachmentDownloadNotice';
import type {CourseAttachment} from '../../../components/VideoPlayer/types';
import {useAppForegroundState} from '../../../hooks/useAppActiveState';
import {settleWithin} from '../../../utils/settleWithin';

type CertificateMutationFlight = {
  presentation: number;
  dispatched: boolean;
};

/** A course-scoped caller must key its controller by account + course. */
export function useCertificatesController(
  resolvedDisplayName?: string,
  courseId?: string,
) {
  const cancellationForAttachment = useAttachmentDownloadCancellation();
  const screenFocused = useIsFocused();
  const appIsActive = useAppForegroundState();
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
  const issueFlight = useRef<CertificateMutationFlight | null>(null);
  const presentationGeneration = useRef(0);
  const canonicalReadReady = useRef(false);
  const readSettled = useRef(false);
  const pendingPollAttempts = useRef(0);
  const acceptedIssueCourseIds = useRef(new Set<string>());
  const identityOwnerRef = useRef(identityKey);
  const activeIdentityRef = useRef(identityKey);
  const presentationActiveRef = useRef(screenFocused && appIsActive);
  const mountedRef = useRef(true);
  activeIdentityRef.current = identityKey;
  presentationActiveRef.current = screenFocused && appIsActive;

  const ownsIdentity = useCallback(
    (expectedIdentity: string) =>
      mountedRef.current &&
      activeIdentityRef.current === expectedIdentity &&
      identityOwnerRef.current === expectedIdentity,
    [],
  );
  const ownsPresentation = useCallback(
    (expectedIdentity: string) =>
      ownsIdentity(expectedIdentity) && presentationActiveRef.current,
    [ownsIdentity],
  );
  const ownsFlight = useCallback(
    (flight: CertificateMutationFlight, expectedIdentity: string) =>
      ownsIdentity(expectedIdentity) && issueFlight.current === flight,
    [ownsIdentity],
  );
  const ownsIssue = useCallback(
    (flight: CertificateMutationFlight, expectedIdentity: string) =>
      ownsFlight(flight, expectedIdentity) &&
      ownsPresentation(expectedIdentity) &&
      presentationGeneration.current === flight.presentation,
    [ownsFlight, ownsPresentation],
  );

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      loadGeneration.current += 1;
      canonicalReadReady.current = false;
      readSettled.current = false;
      issueFlight.current = null;
    };
  }, []);

  useEffect(() => {
    loadGeneration.current += 1;
    canonicalReadReady.current = false;
    readSettled.current = false;
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
    if (!ownsPresentation(identityKey)) return;
    const generation = ++loadGeneration.current;
    canonicalReadReady.current = false;
    readSettled.current = false;
    const isCurrent = () =>
      loadGeneration.current === generation && ownsPresentation(identityKey);
    setLoading(true);
    setLoadError('');
    if (acceptedIssueCourseIds.current.size > 0) setCertificatePending(true);
    try {
      const boundary = await captureAccountSessionBoundary();
      assertAccountSessionBoundary(boundary);
      if (!isCurrent()) return;
      const sessionAvailable = await hasSession();
      assertAccountSessionBoundary(boundary);
      if (!isCurrent()) return;
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
        const scopedCachedCertificates = cachedCertificates.filter(
          item => !courseId || item.courseId === courseId,
        );
        if (isCurrent() && scopedCachedCertificates.length) {
          setCertificates(
            scopedCachedCertificates.filter(item => item.status !== 'revoked'),
          );
          setCertificatePending(
            acceptedIssueCourseIds.current.size > 0 ||
              scopedCachedCertificates.some(item => item.status === 'pending'),
          );
        }
        const [certificatesResult, learningResult] = await remoteReads;
        assertAccountSessionBoundary(boundary);
        if (!isCurrent()) return;
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
                (!courseId || course.id === courseId) &&
                course.accessType === 'scholarship' &&
                !course.certificateAvailable &&
                (course.progress >= 100 ||
                  (course.totalSections > 0 &&
                    course.completedSections >= course.totalSections)),
            ),
          );
        }
        if (certificatesResult.status === 'fulfilled') {
          canonicalReadReady.current = true;
          const remoteCertificates = certificatesResult.value.filter(
            item => !courseId || item.courseId === courseId,
          );
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
                  .filter(course => !courseId || course.id === courseId)
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
      if (isCurrent()) {
        readSettled.current = true;
        setLoading(false);
      }
    }
  }, [courseId, identityKey, ownsPresentation]);

  const finishIssueFlight = useCallback(
    async (flight: CertificateMutationFlight) => {
      if (!ownsFlight(flight, identityKey)) return;
      // Blur cannot undo a dispatched request. Keep its lock through the
      // response and reconcile under the current presentation before another
      // mutation; an earlier GET is not proof of this POST's outcome.
      if (
        flight.dispatched &&
        !ownsIssue(flight, identityKey) &&
        ownsPresentation(identityKey)
      ) {
        await loadCertificates();
      }
      if (ownsFlight(flight, identityKey)) {
        issueFlight.current = null;
        setIssuing(false);
      }
    },
    [identityKey, loadCertificates, ownsFlight, ownsIssue, ownsPresentation],
  );

  useFocusEffect(
    useCallback(() => {
      if (!appIsActive) return () => undefined;
      setIssuing(Boolean(issueFlight.current));
      loadCertificates();
      return () => {
        loadGeneration.current += 1;
        presentationGeneration.current += 1;
        canonicalReadReady.current = false;
        readSettled.current = false;
      };
    }, [appIsActive, loadCertificates]),
  );

  const recoverPendingCertificates = useCallback(async () => {
    if (
      issueFlight.current ||
      !readSettled.current ||
      !ownsPresentation(identityKey)
    )
      return;
    const flight = {
      presentation: presentationGeneration.current,
      dispatched: false,
    };
    issueFlight.current = flight;
    setIssuing(true);
    pendingPollAttempts.current = 0;
    try {
      const boundary = await captureAccountSessionBoundary();
      assertAccountSessionBoundary(boundary);
      if (!ownsIssue(flight, identityKey)) return;
      const pendingCourseIds = Array.from(
        new Set([
          ...certificates
            .filter(certificate => certificate.status === 'pending')
            .map(certificate => certificate.courseId),
          ...acceptedIssueCourseIds.current,
        ]),
      );
      // POST issue is idempotent for user + course. For an existing pending
      // row it only re-enqueues artifact recovery; one controller flight keeps
      // repeated taps from wasting the mutation throttle.
      flight.dispatched = pendingCourseIds.length > 0;
      const recoveryResults = pendingCourseIds.length
        ? await Promise.allSettled(
            pendingCourseIds.map(pendingCourseId =>
              recoverCertificate(pendingCourseId, boundary),
            ),
          )
        : [];
      assertAccountSessionBoundary(boundary);
      if (!ownsIssue(flight, identityKey)) return;
      await loadCertificates();
      if (
        recoveryResults.length > 0 &&
        recoveryResults.every(result => result.status === 'rejected') &&
        ownsIssue(flight, identityKey)
      ) {
        setLoadError('تعذّر تحديث الشهادة الآن\nحاول مرة أخرى');
      }
    } catch (error: unknown) {
      if (!ownsIssue(flight, identityKey)) return;
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        return;
      }
      setLoadError('تعذّر تحديث الشهادة الآن\nحاول مرة أخرى');
    } finally {
      await finishIssueFlight(flight);
    }
  }, [
    certificates,
    finishIssueFlight,
    identityKey,
    loadCertificates,
    ownsPresentation,
    ownsIssue,
  ]);

  useEffect(() => {
    if (
      !screenFocused ||
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
  }, [
    appIsActive,
    certificatePending,
    loadCertificates,
    loading,
    screenFocused,
  ]);

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
        message: `شهادتي على رُكن\n${activeCertificateLink}`,
        url: activeCertificateLink,
      });
    } catch {
      Alert.alert('تعذّرت المشاركة', 'حاول مرة أخرى');
    }
  };

  const asPdf = Boolean(selectedCertificate?.certificatePdfUrl);
  const certificateAttachment: CourseAttachment | null =
    selectedCertificate &&
    (selectedCertificate.certificatePdfUrl ||
      selectedCertificate.certificateUrl)
      ? {
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
        }
      : null;
  const cancelCertificateDownload = cancellationForAttachment(
    certificateAttachment,
  );
  const saveCertificate = () => {
    if (certificateAttachment) void openCourseAttachment(certificateAttachment);
  };

  const openIssueCertificate = (course: CourseProgress) => {
    if (
      !ownsPresentation(identityKey) ||
      !readSettled.current ||
      !canonicalReadReady.current ||
      issuing ||
      issueFlight.current
    )
      return;
    setIssueName(displayName);
    setIssueCourseId(course.id);
  };

  const closeIssueCertificate = () => {
    if (issuing || !ownsPresentation(identityKey)) return;
    setIssueCourseId(null);
    setIssueName('');
  };

  const confirmIssueCertificate = async () => {
    if (
      !issueCourse ||
      issuing ||
      issueFlight.current ||
      !readSettled.current ||
      !canonicalReadReady.current ||
      !ownsPresentation(identityKey)
    )
      return;
    const holderName = issueName.trim().replace(/\s+/g, ' ');
    if (Array.from(holderName).length < 2) {
      Alert.alert('اكتب اسمك', 'هذا الاسم سيظهر على الشهادة');
      return;
    }
    const flight = {
      presentation: presentationGeneration.current,
      dispatched: false,
    };
    issueFlight.current = flight;
    setIssuing(true);
    try {
      const boundary = await captureAccountSessionBoundary();
      assertAccountSessionBoundary(boundary);
      if (!ownsIssue(flight, identityKey)) return;
      flight.dispatched = true;
      const issued = await issueCertificate(
        issueCourse.id,
        holderName,
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      if (!ownsFlight(flight, identityKey)) return;
      if (issued?.status !== 'active') {
        // A positive receipt belongs to this account even after blur. Preserve
        // the marker until a canonical read observes the reserved credential.
        acceptedIssueCourseIds.current.add(issueCourse.id);
      }
      if (!ownsIssue(flight, identityKey)) return;
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
        pendingPollAttempts.current = 0;
        setCertificatePending(true);
        // Keep ownership until the read endpoint has observed the accepted
        // issue. Otherwise a fast second tap can POST the same issue again.
        await loadCertificates();
      }
    } catch (error: unknown) {
      if (!ownsIssue(flight, identityKey)) return;
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
      await finishIssueFlight(flight);
    }
  };

  const retryPendingCertificate = async (certificate: CertificateDto) => {
    if (
      issueFlight.current ||
      !readSettled.current ||
      !ownsPresentation(identityKey)
    )
      return;
    const flight = {
      presentation: presentationGeneration.current,
      dispatched: false,
    };
    issueFlight.current = flight;
    setIssuing(true);
    try {
      const boundary = await captureAccountSessionBoundary();
      assertAccountSessionBoundary(boundary);
      if (!ownsIssue(flight, identityKey)) return;
      // The original issue already froze the learner name. Reissuing without
      // a name addresses the same canonical row and only asks the backend to
      // recover its missing artifact; it cannot create a second credential.
      flight.dispatched = true;
      await recoverCertificate(certificate.courseId, boundary);
      assertAccountSessionBoundary(boundary);
      if (!ownsIssue(flight, identityKey)) return;
      pendingPollAttempts.current = 0;
      await loadCertificates();
    } catch (error: unknown) {
      if (!ownsIssue(flight, identityKey)) return;
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
      await finishIssueFlight(flight);
    }
  };

  return {
    activeCertificateQrDestination,
    activeCourseTitle,
    activeCredential,
    issueReady:
      canonicalReadReady.current &&
      readSettled.current &&
      !loading &&
      !issuing &&
      !issueFlight.current &&
      ownsPresentation(identityKey),
    mutationReady:
      readSettled.current &&
      !loading &&
      !issuing &&
      !issueFlight.current &&
      ownsPresentation(identityKey),
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
    cancelCertificateDownload,
    selectCertificate: setSelectedId,
    selectedCertificate,
    selectedGrantCourse,
    selectGrantCourse: setSelectedGrantId,
    shareCertificate,
    setIssueName,
    serverSession,
  };
}
