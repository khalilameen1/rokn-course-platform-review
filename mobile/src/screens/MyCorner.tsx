import {useNavigation} from '@react-navigation/native';
import React, {useCallback, useEffect, useMemo, useState} from 'react';
import {useSelector} from 'react-redux';
import TabBar from '../components/TabBar';
import {Container, Content} from '../components/containers/Containers';
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
import type {RootNavigation} from '../navigation/types';
import type {RootState} from '../store/store';
import {CourseShelf} from './myCorner/CourseShelf';
import {buildMyCornerModel, type LearningResumeTarget} from './myCorner/model';
import {ProfessionalProgress} from './myCorner/ProfessionalProgress';
import {useMyCornerData} from './myCorner/useMyCornerData';
import {WeeklyRhythm} from './myCorner/WeeklyRhythm';

export default function MyCorner() {
  const navigation = useNavigation<RootNavigation>();
  const {largeText} = useResponsiveLayout();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);
  const data = useMyCornerData(identityKey);
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

  if (!data.owned) {
    return (
      <Container noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            <HeaderWithBack hasArrow={false} title="ركني" />
            <LearningDashboardSkeleton />
          </ResponsiveFrame>
        </Content>
        <TabBar />
      </Container>
    );
  }

  const coursesUnavailable =
    data.serverSession === null || (data.dashboardLoading && !data.dashboard);
  const signedOut = data.serverSession === false;
  const empty = data.serverSession === true && !model.courses.length;

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame>
          <HeaderWithBack hasArrow={false} title="ركني" />
          <SectionHeading
            eyebrow={
              !model.orderedCourses.length
                ? 'تعلمك في مكان واحد'
                : model.hasActiveCourses
                ? 'آخر ما كنت تتعلمه'
                : model.allCoursesCompleted
                ? 'الكورسات المكتملة'
                : 'جاهزة للبدء'
            }
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
              actionLabel="فتح الرئيسية"
              description={
                data.dashboardError ||
                'الكورسات التي تفتحها ستظهر هنا مع آخر نقطة وصلت إليها'
              }
              onAction={() => navigation.navigate('Home')}
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
              hasActiveCourses={model.hasActiveCourses}
              largeText={largeText}
              learningOwnershipFresh={data.learningOwnershipFresh}
              onOpenCourse={openCourse}
              onResume={resumeCourse}
              orderedCourses={model.orderedCourses}
              primaryResumeId={model.primaryResumeId}
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

          <WeeklyRhythm
            activityDays={model.activityDays}
            currentStreak={model.currentStreak}
            week={model.week}
          />
        </ResponsiveFrame>
      </Content>
      <TabBar />
    </Container>
  );
}
