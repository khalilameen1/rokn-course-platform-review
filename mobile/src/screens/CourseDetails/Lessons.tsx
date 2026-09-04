import {useFocusEffect} from '@react-navigation/native';
import React, {useCallback, useEffect, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  applyLocalLearningState,
  loadCourseLearningData,
} from '../../components/VideoPlayer/courseLearningApi';
import {CourseLearningData} from '../../components/VideoPlayer/types';
import {isGrantCourseAccess} from '../../components/VideoPlayer/courseEntitlements';
import Module from '../../components/view/Module';
import FullTrackUpgradeSheet from '../../components/FullTrackUpgradeSheet';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';
import {Fonts} from '../../constants/styleConstants';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';

type CourseOutlineProps = {
  courseId: string;
  identityKey: string;
  openFullTrackUpgrade: boolean;
  onFullTrackUpgradeHandled: () => void;
  onOpenCertificates: () => void;
};

export default function CourseOutline({
  courseId,
  identityKey,
  openFullTrackUpgrade,
  onFullTrackUpgradeHandled,
  onOpenCertificates,
}: CourseOutlineProps) {
  const ownerKey = `${identityKey}:${courseId}`;
  const [loadedCourse, setLoadedCourse] = useState<CourseLearningData | null>(
    null,
  );
  const loadedOwnerRef = useRef('');
  const courseOwnerMatches = loadedOwnerRef.current === ownerKey;
  const course = courseOwnerMatches ? loadedCourse : null;
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [fullTrackVisible, setFullTrackVisible] = useState(false);
  const loadGenerationRef = useRef(0);
  const loadAbortRef = useRef<AbortController | null>(null);

  const load = useCallback(async () => {
    loadAbortRef.current?.abort();
    const controller = new AbortController();
    loadAbortRef.current = controller;
    const generation = ++loadGenerationRef.current;
    const hasCurrentCourse = loadedOwnerRef.current === ownerKey;
    if (!hasCurrentCourse) {
      loadedOwnerRef.current = ownerKey;
      setLoadedCourse(null);
    }
    setLoading(true);
    setLoadError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      const result = await loadCourseLearningData(courseId || undefined, {
        signal: controller.signal,
      });
      assertAccountSessionBoundary(boundary);
      const withLocalState = await applyLocalLearningState(result.course);
      assertAccountSessionBoundary(boundary);
      if (
        generation !== loadGenerationRef.current ||
        controller.signal.aborted
      ) {
        return;
      }
      loadedOwnerRef.current = ownerKey;
      setLoadedCourse(withLocalState);
    } catch {
      if (
        generation !== loadGenerationRef.current ||
        controller.signal.aborted
      ) {
        return;
      }
      setLoadError('تعذّر تحميل خريطة الكورس الآن\nمكانك محفوظ');
    } finally {
      if (
        generation === loadGenerationRef.current &&
        !controller.signal.aborted
      ) {
        if (loadAbortRef.current === controller) loadAbortRef.current = null;
        setLoading(false);
      }
    }
  }, [courseId, ownerKey]);

  useFocusEffect(
    useCallback(() => {
      void load();
      return () => {
        loadGenerationRef.current += 1;
        loadAbortRef.current?.abort();
        loadAbortRef.current = null;
      };
    }, [load]),
  );

  useEffect(() => {
    if (!course || !openFullTrackUpgrade) return;
    if (isGrantCourseAccess(course.accessType)) {
      setFullTrackVisible(true);
    }
    onFullTrackUpgradeHandled();
  }, [course, onFullTrackUpgradeHandled, openFullTrackUpgrade]);

  if ((loading || !courseOwnerMatches) && !course) {
    return (
      <View style={styles.loading}>
        <ActivityIndicator color="#76A9FF" />
        <Text style={styles.loadingText}>جارٍ تحميل خريطة الكورس</Text>
      </View>
    );
  }

  if (!course) {
    return (
      <View style={styles.loading}>
        <Text style={styles.errorTitle}>تعذّر فتح خريطة الكورس</Text>
        <Text style={styles.loadingText}>{loadError}</Text>
        <Pressable
          accessibilityRole="button"
          style={styles.retryButton}
          onPress={() => void load()}>
          <Text style={styles.retryText}>إعادة المحاولة</Text>
        </Pressable>
      </View>
    );
  }

  const grantAccess = isGrantCourseAccess(course.accessType);
  const moduleProjects = (module: (typeof course.modules)[number]) =>
    module.projects || [];
  const hasProjects = course.modules.some(
    module => moduleProjects(module).length > 0,
  );
  const courseCompleted = course.modules.every(
    module =>
      module.reels.every(reel => reel.isCompleted) &&
      moduleProjects(module).every(project => project.status === 'passed'),
  );
  const certificateReady = course.certificateAvailable === true;
  const firstPendingModuleIndex = course.modules.findIndex(
    module =>
      !module.isLocked &&
      (module.reels.some(reel => !reel.isCompleted) ||
        moduleProjects(module).some(project => project.status !== 'passed')),
  );
  const lastUnlockedModuleIndex = course.modules.reduce(
    (lastIndex, module, index) => (module.isLocked ? lastIndex : index),
    0,
  );
  const expandedModuleIndex =
    firstPendingModuleIndex >= 0
      ? firstPendingModuleIndex
      : lastUnlockedModuleIndex;

  return (
    <View style={styles.container}>
      <View style={styles.intro}>
        <Text style={styles.eyebrow}>خريطة الكورس</Text>
        <Text style={styles.heading}>كل مقاطع الكورس أمامك</Text>
        <Text style={styles.introCopy}>
          افتح أي مقطع متاح
          {hasProjects ? '\nتظهر المشروعات في موضعها داخل الخريطة' : ''}
        </Text>
      </View>

      {course.modules.map((module, index) => (
        <Module
          key={module.id}
          courseId={course.id}
          module={module}
          initiallyExpanded={index === expandedModuleIndex}
        />
      ))}

      <Pressable
        accessibilityRole={
          grantAccess || certificateReady ? 'button' : undefined
        }
        accessibilityLabel={
          grantAccess
            ? 'عرض خيارات Rokn AI والشهادة'
            : certificateReady
            ? 'فتح شهادتك'
            : undefined
        }
        disabled={!grantAccess && !certificateReady}
        onPress={() => {
          if (grantAccess) {
            setFullTrackVisible(true);
            return;
          }
          onOpenCertificates();
        }}
        style={[
          styles.certificateCard,
          certificateReady && styles.certificateCardReady,
          grantAccess && styles.certificateCardGrant,
        ]}>
        <View style={styles.certificateIcon}>
          <Text style={styles.certificateSymbol}>▣</Text>
        </View>
        <View style={styles.certificateCopy}>
          <Text style={styles.certificateTitle}>
            {grantAccess
              ? 'الشهادة ضمن أحد الاختيارات المدفوعة'
              : certificateReady
              ? 'شهادتك جاهزة'
              : 'شهادة إتمام الكورس'}
          </Text>
          <Text style={styles.certificateDescription}>
            {grantAccess
              ? 'منحتك تفتح محتوى الكورس كاملًا\nأضف Rokn AI والشهادة عند الحاجة'
              : certificateReady
              ? 'ستظهر في بورتفوليوك ويصل رمز QR إلى صفحة المشاركة'
              : hasProjects
              ? 'تفتح بعد إكمال الكورس واجتياز مشروع العبور'
              : 'تفتح بعد إكمال الكورس'}
          </Text>
        </View>
        <View style={styles.certificateState}>
          <Text style={styles.certificateStateText}>
            {grantAccess ? 'اختياري' : certificateReady ? 'جاهزة' : 'مقفلة'}
          </Text>
        </View>
      </Pressable>
      <FullTrackUpgradeSheet
        completed={courseCompleted}
        courseId={course.id}
        courseTitle={course.title}
        onClose={() => setFullTrackVisible(false)}
        onUpgraded={async () => {
          await load();
        }}
        visible={fullTrackVisible}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    width: '100%',
    paddingHorizontal: 16,
    paddingBottom: 110,
  },
  loading: {
    minHeight: 260,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
  },
  loadingText: {
    color: 'rgba(255,255,255,.55)',
    fontFamily: Fonts.regular,
    fontSize: 12,
  },
  errorTitle: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 17,
    textAlign: 'center',
  },
  retryButton: {
    minWidth: 170,
    minHeight: 48,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
    marginTop: 10,
  },
  retryText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 13,
  },
  intro: {
    width: '100%',
    maxWidth: 760,
    alignSelf: 'center',
    marginBottom: 18,
  },
  eyebrow: {
    ...textDirection,
    color: '#76A9FF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  heading: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 20,
    lineHeight: 31,
    marginTop: 4,
  },
  introCopy: {
    ...textDirection,
    color: 'rgba(255,255,255,.54)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    marginTop: 3,
  },
  note: {
    width: '100%',
    maxWidth: 760,
    minHeight: 42,
    alignSelf: 'center',
    borderRadius: 14,
    paddingHorizontal: 12,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 8,
    backgroundColor: 'rgba(75,142,247,.08)',
    borderWidth: 1,
    borderColor: 'rgba(118,169,255,.15)',
    marginBottom: 12,
  },
  noteDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    backgroundColor: '#76A9FF',
  },
  noteText: {
    ...textDirection,
    flex: 1,
    color: 'rgba(255,255,255,.7)',
    fontFamily: Fonts.regular,
    fontSize: 10,
  },
  certificateCard: {
    width: '100%',
    maxWidth: 760,
    minHeight: 86,
    alignSelf: 'center',
    borderRadius: 20,
    padding: 14,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 12,
    backgroundColor: '#0E141C',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.07)',
    opacity: 0.65,
  },
  certificateCardReady: {
    opacity: 1,
    borderColor: 'rgba(93,210,153,.2)',
  },
  certificateCardGrant: {
    opacity: 1,
    borderColor: 'rgba(118,169,255,.2)',
  },
  certificateIcon: {
    width: 46,
    height: 46,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.05)',
  },
  certificateSymbol: {
    color: '#FFFFFF',
    fontSize: 22,
  },
  certificateCopy: {
    flex: 1,
  },
  certificateTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 13,
  },
  certificateDescription: {
    ...textDirection,
    color: 'rgba(255,255,255,.43)',
    fontFamily: Fonts.regular,
    fontSize: 9,
    lineHeight: 15,
    marginTop: 2,
  },
  certificateState: {
    minHeight: 28,
    paddingHorizontal: 10,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  certificateStateText: {
    color: 'rgba(255,255,255,.63)',
    fontFamily: Fonts.medium,
    fontSize: 9,
  },
});
