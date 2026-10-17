import {useNavigation} from '@react-navigation/native';
import React, {useCallback, useEffect, useMemo, useState} from 'react';
import {RefreshControl} from 'react-native';
import {useSelector} from 'react-redux';
import {
  Container,
  Content,
  TAB_SCREEN_EDGES,
} from '../components/containers/Containers';
import {
  ResponsiveFrame,
  SectionHeading,
  StatusView,
} from '../components/ui/PremiumUI';
import {LearningDashboardSkeleton} from '../components/ui/Skeleton';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {useResponsiveLayout} from '../constants/designSystem';
import {sessionIdentityKey} from '../constants/helpers';
import {openGuestLogin} from '../navigation/journeyNavigation';
import {openRootTab} from '../navigation/rootRoutes';
import type {RootNavigation} from '../navigation/types';
import type {RootState} from '../store/store';
import {CourseShelf} from './myCorner/CourseShelf';
import {buildMyCornerModel, type LearningResumeTarget} from './myCorner/model';
import {ProfessionalProgress} from './myCorner/ProfessionalProgress';
import {SavedPreview} from './myCorner/SavedPreview';
import {useMyCornerData} from './myCorner/useMyCornerData';
import {useSavedPreview} from './myCorner/useSavedPreview';
import {WeeklyRhythm} from './myCorner/WeeklyRhythm';

export default function MyCorner() {
  const navigation = useNavigation<RootNavigation>();
  const {largeText} = useResponsiveLayout();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);
  const data = useMyCornerData(identityKey);
  const saved = useSavedPreview(identityKey, data.serverSession === true);
  const [selectedPathId, setSelectedPathId] = useState<string | null>(null);

  useEffect(() => setSelectedPathId(null), [identityKey]);

  const model = useMemo(
    () =>
      buildMyCornerModel({
        dashboard: data.dashboard,
        selectedPathId,
        signedIn: data.serverSession === true,
      }),
    [data.dashboard, data.serverSession, selectedPathId],
  );

  useEffect(() => {
    if (!model.learningPaths.length) {
      setSelectedPathId(null);
    } else if (!model.learningPaths.some(path => path.id === selectedPathId)) {
      setSelectedPathId(model.learningPaths[0].id);
    }
  }, [model.learningPaths, selectedPathId]);

  const openCourse = useCallback(
    (courseId: string) => navigation.navigate('CourseDetails', {courseId}),
    [navigation],
  );
  const resumeCourse = useCallback(
    (target: LearningResumeTarget) => navigation.navigate('Reels', target),
    [navigation],
  );
  const openSavedCollection = useCallback(
    (folderId: string) => navigation.navigate('SavedLibrary', {folderId}),
    [navigation],
  );
  const openSavedLibrary = useCallback(
    () => navigation.navigate('SavedLibrary'),
    [navigation],
  );
  const {reload: reloadDashboard} = data;
  const {reload: reloadSaved} = saved;
  const refresh = useCallback(() => {
    reloadDashboard();
    reloadSaved();
  }, [reloadDashboard, reloadSaved]);

  if (!data.owned) {
    return (
      <Container edges={TAB_SCREEN_EDGES} noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            <HeaderWithBack hasArrow={false} title="ركني" />
            <LearningDashboardSkeleton />
          </ResponsiveFrame>
        </Content>
      </Container>
    );
  }

  const coursesUnavailable =
    !data.dashboardError &&
    (data.serverSession === null || (data.dashboardLoading && !data.dashboard));
  const signedOut = data.serverSession === false;
  const empty = !model.courses.length;

  return (
    <Container edges={TAB_SCREEN_EDGES} noPadding>
      <Content
        noPadding
        refreshControl={
          data.serverSession === true ? (
            <RefreshControl
              refreshing={data.dashboardLoading}
              onRefresh={refresh}
            />
          ) : undefined
        }>
        <ResponsiveFrame>
          <HeaderWithBack hasArrow={false} title="ركني" />
          <SectionHeading
            title={
              !model.orderedCourses.length
                ? 'تعلم على طريقتك'
                : model.hasActiveCourses
                ? 'استكمل من مكانك'
                : model.allCoursesCompleted
                ? 'أنهيتها'
                : 'ابدأ التعلّم'
            }
          />

          {coursesUnavailable ? (
            <LearningDashboardSkeleton />
          ) : signedOut ? (
            <StatusView
              actionLabel="تسجيل الدخول"
              description="سجّل الدخول لحفظ تقدمك ومتابعته من أي جهاز"
              onAction={() => openGuestLogin(navigation, {name: 'MyCorner'})}
              state="empty"
              title="ستظهر كورساتك هنا"
            />
          ) : empty ? (
            <StatusView
              actionLabel={
                data.dashboardError ? 'إعادة المحاولة' : 'فتح الرئيسية'
              }
              description={
                data.dashboardError ||
                'الكورسات التي تفتحها ستظهر هنا مع آخر نقطة وصلت إليها'
              }
              onAction={
                data.dashboardError
                  ? data.reload
                  : () => openRootTab(navigation, 'Home')
              }
              state={data.dashboardError ? 'error' : 'empty'}
              title={
                data.dashboardError
                  ? 'تعذّر تحديث كورساتك'
                  : 'ابدأ أول كورس من الرئيسية'
              }
            />
          ) : (
            <CourseShelf
              error={data.dashboardError}
              largeText={largeText}
              learningOwnershipFresh={data.learningOwnershipFresh}
              onOpenCourse={openCourse}
              onResume={resumeCourse}
              onRetry={data.reload}
              orderedCourses={model.orderedCourses}
            />
          )}

          {data.serverSession === true && (
            <SavedPreview
              collections={saved.collections}
              hasCourses={!empty}
              onOpenAll={openSavedLibrary}
              onOpenCollection={openSavedCollection}
              status={saved.status}
            />
          )}

          <ProfessionalProgress
            badges={model.displayedBadges}
            earnedBadge={model.earnedProfessionalBadge}
            largeText={largeText}
            learningPaths={model.learningPaths}
            nextLevel={model.nextPathLevel}
            onSelectPath={setSelectedPathId}
            pathProgress={model.pathProgress}
            selectedPath={model.selectedPath}
            visible={
              model.professionalCourses.length > 0 ||
              Boolean(model.selectedPath)
            }
          />

          {data.serverSession === true && data.dashboard && (
            <WeeklyRhythm
              activityDays={model.activityDays}
              currentStreak={model.currentStreak}
              week={model.week}
            />
          )}
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}
