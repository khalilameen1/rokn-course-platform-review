import {
  useFocusEffect,
  useIsFocused,
  useNavigation,
  useRoute,
} from '@react-navigation/native';
import React, {useCallback, useEffect, useRef, useState} from 'react';
import {Pressable, ScrollView, StatusBar, Text, View} from 'react-native';
import {useSelector} from 'react-redux';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {Palette, useResponsiveLayout} from '../../constants/designSystem';
import {sessionIdentityKey} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';
import type {RootNavigation, RootRoute} from '../../navigation/types';
import {trackProductEvent} from '../../services/productAnalytics';
import type {RootState} from '../../store/store';
import {
  CoursePurchaseDialog,
  CourseRetentionDialog,
} from './details/PurchaseDialogs';
import {selectCourseHeroHeight} from './details/selectors';
import {
  CourseBody,
  CourseHero,
  CourseIntro,
  CourseRatingAction,
} from './details/sections';
import styles from './details/styles';
import {useCourseDetailsData} from './details/useCourseDetailsData';
import {useCoursePurchase} from './details/useCoursePurchase';
import {useCourseRating} from './details/useCourseRating';

export default function CourseDetails() {
  const route = useRoute<RootRoute<'CourseDetails'>>();
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const layout = useResponsiveLayout();
  const appIsActive = useAppForegroundState();
  const screenFocused = useIsFocused();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);
  const courseId = String(route.params?.courseId || '');
  const [activeTab, setActiveTab] = useState<'about' | 'outline'>('about');
  const [notice, setNotice] = useState('');
  const courseDetailsFocusedOnceRef = useRef(false);
  const previousAppActiveRef = useRef(appIsActive);
  const reelsNavigationFlightRef = useRef(false);

  const details = useCourseDetailsData({courseId, identityKey});
  const {course} = details;
  const reloadCourse = course.reload;

  const purchase = useCoursePurchase({
    courseId,
    data: details,
    identityKey,
    navigation,
    routeParams: route.params,
    setNotice,
  });

  const {
    courseDescription,
    courseTitle,
    durationMinutes,
    owned,
    pageReady,
    previewReelCount,
    primaryActionDisabled,
    primaryActionLabel,
    showSecondaryPreview,
    ratingAverage,
    ratingsCount,
    studentsCount,
  } = purchase.presentation;
  const runPrimaryAction = purchase.runPrimaryAction;

  useFocusEffect(
    useCallback(() => {
      reelsNavigationFlightRef.current = false;
      if (courseDetailsFocusedOnceRef.current) reloadCourse();
      courseDetailsFocusedOnceRef.current = true;
    }, [reloadCourse]),
  );

  useEffect(() => {
    const becameActive = appIsActive && !previousAppActiveRef.current;
    previousAppActiveRef.current = appIsActive;
    if (becameActive && screenFocused) reloadCourse();
  }, [appIsActive, reloadCourse, screenFocused]);

  const rating = useCourseRating({
    course: course.value,
    courseId,
    identityKey,
    owned,
    reload: reloadCourse,
    serverSession: course.session,
    setCourse: course.setValue,
    setNotice,
  });

  const startCourse = useCallback(
    (resumeAfterPurchase = false) => {
      if (reelsNavigationFlightRef.current) return;
      reelsNavigationFlightRef.current = true;
      const resumeReelId =
        resumeAfterPurchase && route.params?.resumeAfterPreview
          ? String(route.params?.resumeReelId || '').trim()
          : '';
      if (resumeAfterPurchase) {
        navigation.setParams({
          resumeAfterPreview: false,
          resumeReelId: undefined,
        });
      }
      navigation.navigate('Reels', {
        courseId,
        reelId: undefined,
        lessonId: undefined,
        projectId: undefined,
        continueAfterReelId: resumeReelId || undefined,
        preview: false,
        previewCount: undefined,
        initialReelIndex: undefined,
        initialPositionSeconds: undefined,
      });
    },
    [
      courseId,
      navigation,
      route.params?.resumeAfterPreview,
      route.params?.resumeReelId,
    ],
  );

  const startPreview = useCallback(
    (reelId?: string) => {
      if (reelsNavigationFlightRef.current) return;
      reelsNavigationFlightRef.current = true;
      void trackProductEvent({
        event_name: 'sample_started',
        screen_key: 'course_details',
        course_id: courseId,
      });
      navigation.navigate('Reels', {
        courseId,
        reelId: reelId || undefined,
        lessonId: undefined,
        projectId: undefined,
        preview: true,
        previewCount: previewReelCount,
        initialReelIndex: undefined,
        initialPositionSeconds: undefined,
      });
    },
    [courseId, navigation, previewReelCount],
  );

  const handlePrimaryAction = useCallback(() => {
    runPrimaryAction({
      onPreview: () => startPreview(),
      onStart: () => startCourse(),
    });
  }, [runPrimaryAction, startCourse, startPreview]);

  const handleFullTrackUpgradePrompt = useCallback(() => {
    navigation.setParams({openFullTrackUpgrade: false});
  }, [navigation]);

  const openCertificates = useCallback(() => {
    navigation.navigate('Profile', {tab: 'certificates'});
  }, [navigation]);

  const heroHeight = selectCourseHeroHeight(layout);

  return (
    <View style={styles.screen}>
      <StatusBar barStyle="light-content" backgroundColor={Palette.canvas} />
      <ScrollView
        contentContainerStyle={{paddingBottom: insets.bottom + 112}}
        showsVerticalScrollIndicator={false}>
        <CourseHero
          courseTitle={courseTitle}
          gutter={layout.gutter}
          heroHeight={heroHeight}
          maxContentWidth={layout.maxContentWidth}
          onBack={() => goBackOrHome(navigation)}
          remoteCourse={course.value}
          topInset={insets.top}
        />

        <View
          style={[
            styles.content,
            {
              paddingHorizontal: layout.gutter,
              maxWidth: layout.maxContentWidth,
            },
          ]}>
          <CourseIntro
            courseDescription={courseDescription}
            durationMinutes={durationMinutes}
            onPrimaryAction={handlePrimaryAction}
            onPreview={() => startPreview()}
            pageReady={pageReady}
            primaryActionLabel={primaryActionLabel}
            primaryActionDisabled={primaryActionDisabled}
            ratingAverage={ratingAverage}
            ratingsCount={ratingsCount}
            remoteError={course.error}
            showSecondaryPreview={showSecondaryPreview}
            studentsCount={studentsCount}
          />
          <CourseRatingAction
            busy={rating.busy}
            editable={course.value?.ratingEligible === true}
            onDelete={rating.remove}
            onRate={rating.submit}
            rating={rating.rating}
            visible={
              pageReady &&
              owned &&
              course.session === true &&
              (course.value?.ratingEligible === true || rating.rating !== null)
            }
          />
          {course.value?.fromCache === true && (
            <Pressable
              accessibilityRole="button"
              onPress={reloadCourse}
              style={({pressed}) => [
                styles.cachedDetailsNotice,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.cachedDetailsText}>
                نعرض آخر تفاصيل محفوظة
              </Text>
              <Text style={styles.cachedDetailsAction}>إعادة المحاولة</Text>
            </Pressable>
          )}
          {!!(notice || course.notice) &&
            purchase.dialog.dialogStep === null && (
              <Text style={[styles.notice, styles.inlineNotice]}>
                {notice || course.notice}
              </Text>
            )}
          <CourseBody
            activeTab={activeTab}
            onFullTrackUpgradeHandled={handleFullTrackUpgradePrompt}
            onOpenCertificates={openCertificates}
            onPreviewSelect={startPreview}
            onRetry={reloadCourse}
            onTabChange={setActiveTab}
            openFullTrackUpgrade={route.params?.openFullTrackUpgrade === true}
            owned={owned}
            learningCourse={course.learningValue}
            remoteCourse={course.value}
            remoteError={course.error}
            remoteLoading={course.loading}
          />
        </View>
      </ScrollView>

      <CoursePurchaseDialog
        {...purchase.dialog}
        bottomInset={insets.bottom}
        courseTitle={courseTitle}
        projectCount={course.value?.projectCount ?? 0}
        isTablet={layout.isTablet}
        notice={notice}
        onSuccessStart={() => {
          purchase.closeSuccess();
          startCourse(true);
        }}
      />

      <CourseRetentionDialog
        bottomInset={insets.bottom}
        isTablet={layout.isTablet}
        onClose={purchase.retention.close}
        onOpenWallet={() => navigation.navigate('Wallet')}
        owned={owned}
        retentionVisible={purchase.retention.visible}
      />
    </View>
  );
}
