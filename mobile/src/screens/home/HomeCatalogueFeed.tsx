import React, {memo} from 'react';
import {ActivityIndicator, Pressable, StyleSheet, Text, View} from 'react-native';
import CourseCarousel from '../../components/view/CourseCarousel';
import CoursesSection from '../../components/view/CoursesSection';
import {ResponsiveFrame, StatusView} from '../../components/ui/PremiumUI';
import {CatalogueSkeleton} from '../../components/ui/Skeleton';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../../constants/designSystem';
import type {Course} from '../../types/Course';
import type {HomeCourseSection} from './homeCatalogue';

type HomeCatalogueFeedProps = {
  active: boolean;
  catalogue: Course[];
  error: string;
  hasSearchQuery: boolean;
  heroCourses: Course[];
  loadMoreError: string;
  loading: boolean;
  loadingMore: boolean;
  recommendations: Course[];
  searchMatches: Course[];
  sections: HomeCourseSection[];
  staleNotice: string;
  onLoadMore: () => void;
  onOpenCourse: (course: Course) => void;
  onRefresh: () => void | Promise<void>;
};

const RetryNotice = ({
  message,
  onPress,
}: {
  message: string;
  onPress: () => void | Promise<void>;
}) => (
  <ResponsiveFrame>
    <Pressable
      accessibilityRole="button"
      onPress={() => void onPress()}
      style={({pressed}) => [styles.notice, pressed && styles.pressed]}>
      <Text style={styles.noticeText}>{message}</Text>
      <Text style={styles.noticeAction}>إعادة المحاولة</Text>
    </Pressable>
  </ResponsiveFrame>
);

const HomeCatalogueFeed = memo<HomeCatalogueFeedProps>(
  ({
    active,
    catalogue,
    error,
    hasSearchQuery,
    heroCourses,
    loadMoreError,
    loading,
    loadingMore,
    recommendations,
    searchMatches,
    sections,
    staleNotice,
    onLoadMore,
    onOpenCourse,
    onRefresh,
  }) => (
    <>
      {loading ? (
        <CatalogueSkeleton />
      ) : error && !hasSearchQuery ? (
        <ResponsiveFrame>
          <StatusView
            actionLabel="إعادة المحاولة"
            description={error}
            onAction={onRefresh}
            state="error"
            title="تعذّر تحميل الكورسات"
          />
        </ResponsiveFrame>
      ) : !catalogue.length && !hasSearchQuery ? (
        <ResponsiveFrame>
          <StatusView
            description="ستظهر الكورسات هنا فور نشرها"
            state="empty"
            title="الجديد في الطريق"
          />
        </ResponsiveFrame>
      ) : !hasSearchQuery ? (
        <CourseCarousel
          active={active}
          data={heroCourses}
          onButtonPress={onOpenCourse}
        />
      ) : null}

      {!!staleNotice && !error && (
        <RetryNotice message={staleNotice} onPress={onRefresh} />
      )}

      {!loading && !error && !hasSearchQuery ? (
        <>
          {!!recommendations.length && (
            <CoursesSection
              data={recommendations}
              onCoursePress={onOpenCourse}
              title="مقترحات لك"
            />
          )}
          {sections.map(section => (
            <CoursesSection
              data={section.data}
              key={section.id}
              onCoursePress={onOpenCourse}
              title={section.title}
            />
          ))}
        </>
      ) : null}

      {hasSearchQuery && !loading && !error && searchMatches.length ? (
        <CoursesSection
          data={searchMatches}
          onCoursePress={onOpenCourse}
          title="نتائج البحث"
        />
      ) : hasSearchQuery && !loading && !error ? (
        <ResponsiveFrame>
          <StatusView
            description="ابحث باسم المهارة أو المدرب"
            state="empty"
            title="لم نجد نتيجة مطابقة"
          />
        </ResponsiveFrame>
      ) : null}

      {hasSearchQuery && !loading && error ? (
        <ResponsiveFrame>
          <StatusView
            actionLabel="إعادة المحاولة"
            description={error}
            onAction={onRefresh}
            state="error"
            title="تعذّر البحث الآن"
          />
        </ResponsiveFrame>
      ) : null}

      {!!loadMoreError && !error && (
        <RetryNotice message={loadMoreError} onPress={onLoadMore} />
      )}

      {loadingMore && !loadMoreError && (
        <View accessibilityRole="progressbar" style={styles.loadingMore}>
          <ActivityIndicator color={Palette.primary} />
        </View>
      )}
    </>
  ),
);

const styles = StyleSheet.create({
  notice: {
    minHeight: Accessibility.minTouchTarget,
    marginTop: Spacing.md,
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.sm,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  noticeText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
  },
  noticeAction: {
    ...Type.caption,
    ...textDirection,
    color: Palette.primary,
    marginTop: Spacing.xxs,
  },
  pressed: {opacity: 0.78},
  loadingMore: {alignItems: 'center', paddingVertical: Spacing.lg},
});

HomeCatalogueFeed.displayName = 'HomeCatalogueFeed';
export default HomeCatalogueFeed;
