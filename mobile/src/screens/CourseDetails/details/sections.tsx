import React, {useMemo, useState} from 'react';
import {ActivityIndicator, Image, Pressable, Text, View} from 'react-native';
import {
  AccordionArrowDown,
  AccordionArrowUp,
  ArrowRight,
} from '../../../assets/SVG';
import {CourseDetailsSkeleton} from '../../../components/ui/Skeleton';
import {StatusView} from '../../../components/ui/PremiumUI';
import {CourseArtwork} from '../../../components/ui/CourseArtwork';
import {Palette, useResponsiveLayout} from '../../../constants/designSystem';
import {
  formatArabicMinutes,
  formatArabicNumber,
  formatArabicRatings,
  formatArabicStudents,
  formatAuthoredDisplayText,
} from '../../../constants/arabicFormatting';
import type {CourseDetails as CourseDetailsDto} from '../../../services/roknApi';
import type {CourseLearningData} from '../../../components/VideoPlayer/types';
import Lessons from '../Lessons';
import styles from './styles';

export const CourseAbout = ({details}: {details?: CourseDetailsDto | null}) => {
  const {isTablet, largeText} = useResponsiveLayout();
  const description = details?.description || '';
  const instructorName = details?.instructor || '';
  const instructorBio = details?.instructorBio || '';
  return (
    <View style={styles.aboutWrap}>
      <View
        style={[
          styles.aboutGrid,
          isTablet && !largeText && styles.aboutGridTablet,
        ]}>
        <View style={styles.aboutMain}>
          <Text style={styles.sectionTitle}>عن هذا الكورس</Text>
          {!!description && (
            <Text style={styles.bodyCopy}>
              {formatAuthoredDisplayText(description)}
            </Text>
          )}
        </View>

        {!!instructorName && (
          <View
            style={[
              styles.instructorCard,
              isTablet && !largeText && styles.instructorCardTablet,
            ]}>
            <Image
              source={
                details?.instructorImage
                  ? {uri: details.instructorImage}
                  : require('../../../assets/images/default-avatar.png')
              }
              style={styles.instructorImage}
            />
            <View style={styles.instructorCopy}>
              <Text style={styles.instructorLabel}>تعرّف على المدرب</Text>
              <Text style={styles.instructorName}>
                {formatAuthoredDisplayText(instructorName)}
              </Text>
              {!!instructorBio && (
                <Text style={styles.instructorBio}>
                  {formatAuthoredDisplayText(instructorBio)}
                </Text>
              )}
            </View>
          </View>
        )}
      </View>
    </View>
  );
};

export const LockedOutline = ({
  details,
  onPreviewSelect,
}: {
  details?: CourseDetailsDto | null;
  onPreviewSelect: (reelId: string) => void;
}) => {
  const [expandedModuleId, setExpandedModuleId] = useState<string | null>(null);
  const modules = useMemo(() => details?.modules || [], [details]);
  return (
    <View style={styles.lockedOutline}>
      <Text style={styles.sectionTitle}>محتوى الكورس</Text>
      {details && !modules.length && (
        <Text style={styles.lockedNote}>لم تُنشر خريطة هذا الكورس بعد</Text>
      )}
      {modules.map((module, index) => {
        const expanded = expandedModuleId === module.id;
        return (
          <View key={module.id} style={styles.modulePreview}>
            <Pressable
              accessibilityRole="button"
              accessibilityState={{expanded}}
              onPress={() => setExpandedModuleId(expanded ? null : module.id)}
              style={({pressed}) => [
                styles.moduleHeader,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.moduleNumber}>
                {formatArabicNumber(index + 1, {
                  minimumIntegerDigits: 2,
                  useGrouping: false,
                })}
              </Text>
              <View style={styles.moduleCopy}>
                <Text style={styles.moduleTitle}>
                  {formatAuthoredDisplayText(module.title)}
                </Text>
                <Text style={styles.moduleMeta}>
                  {formatArabicNumber(module.reelCount)} مقطع
                  {module.projectCount
                    ? ` · ${formatArabicNumber(module.projectCount)} مشروع`
                    : ''}
                </Text>
              </View>
              {expanded ? (
                <AccordionArrowUp accessible={false} />
              ) : (
                <AccordionArrowDown accessible={false} />
              )}
            </Pressable>
            {expanded && (
              <View style={styles.outlineItems}>
                {module.items.map(item => {
                  const canPreview = item.type === 'reel' && item.isPreview;
                  return (
                    <Pressable
                      accessibilityRole={canPreview ? 'button' : undefined}
                      disabled={!canPreview}
                      key={item.id}
                      onPress={() =>
                        onPreviewSelect(
                          'reelId' in item && item.reelId
                            ? item.reelId
                            : item.id,
                        )
                      }
                      style={({pressed}) => [
                        styles.outlineItem,
                        canPreview && styles.outlineItemPreview,
                        pressed && styles.pressed,
                      ]}>
                      <View style={styles.outlineItemCopy}>
                        <Text style={styles.outlineItemTitle}>
                          {formatAuthoredDisplayText(item.title)}
                        </Text>
                        <Text style={styles.outlineItemMeta}>
                          {item.type === 'project'
                            ? 'مشروع عبور · يُفتح بعد إكمال الوحدة'
                            : canPreview
                            ? 'مفتوح للمشاهدة الآن'
                            : 'يُفتح مع الكورس'}
                        </Text>
                      </View>
                      <View
                        style={[
                          styles.itemStatus,
                          canPreview && styles.itemStatusOpen,
                        ]}>
                        <Text
                          style={[
                            styles.itemStatusText,
                            canPreview && styles.itemStatusTextOpen,
                          ]}>
                          {item.type === 'project'
                            ? 'مشروع'
                            : canPreview
                            ? 'شاهد'
                            : 'مغلق'}
                        </Text>
                      </View>
                    </Pressable>
                  );
                })}
              </View>
            )}
          </View>
        );
      })}
      <Text style={styles.lockedNote}>
        يمكنك رؤية الخريطة قبل الشراء
        {'\n'}تُفتح المقاطع والمرفقات بعد شراء الكورس
      </Text>
    </View>
  );
};

type CourseHeroProps = {
  courseTitle: string;
  gutter: number;
  heroHeight: number;
  maxContentWidth: number;
  onBack: () => void;
  remoteCourse: CourseDetailsDto | null;
  topInset: number;
};

export const CourseHero = ({
  courseTitle,
  gutter,
  heroHeight,
  maxContentWidth,
  onBack,
  remoteCourse,
  topInset,
}: CourseHeroProps) => (
  <View style={[styles.hero, {paddingTop: topInset}]}>
    <View
      style={[
        styles.heroFrame,
        {
          paddingHorizontal: gutter,
          maxWidth: maxContentWidth,
        },
      ]}>
      <View style={styles.heroNavigation}>
        <Pressable
          accessibilityLabel="العودة"
          accessibilityRole="button"
          onPress={onBack}
          style={({pressed}) => [styles.backButton, pressed && styles.pressed]}>
          <ArrowRight accessible={false} />
        </Pressable>
        <Text style={styles.heroNavigationTitle}>تفاصيل الكورس</Text>
      </View>
      <View
        accessible
        accessibilityLabel={`غلاف ${formatAuthoredDisplayText(courseTitle)}`}
        accessibilityRole="image"
        style={[styles.heroArtwork, {height: heroHeight}]}>
        <CourseArtwork
          fallback={require('../../../assets/images/courseSliderBackground.jpg')}
          source={
            remoteCourse?.imageUrl ? {uri: remoteCourse.imageUrl} : undefined
          }
          style={styles.heroImage}
        />
      </View>
    </View>
  </View>
);

type CourseIntroProps = {
  courseTitle: string;
  durationMinutes: number | null;
  onPreview: () => void;
  pageReady: boolean;
  ratingAverage: number | null;
  ratingsCount: number;
  remoteCourse: CourseDetailsDto | null;
  remoteError: string;
  showSecondaryPreview: boolean;
  studentsCount: number;
};

export const CourseIntro = ({
  courseTitle,
  durationMinutes,
  onPreview,
  pageReady,
  ratingAverage,
  ratingsCount,
  remoteCourse,
  remoteError,
  showSecondaryPreview,
  studentsCount,
}: CourseIntroProps) => (
  <View style={styles.courseIntro}>
    <Text accessibilityRole="header" style={styles.heroTitle}>
      {formatAuthoredDisplayText(courseTitle)}
    </Text>
    {!!remoteCourse?.instructor && (
      <Text style={styles.instructorByline}>
        مع {formatAuthoredDisplayText(remoteCourse.instructor)}
      </Text>
    )}
    {pageReady && (
      <View style={styles.socialProofRow}>
        {durationMinutes !== null && (
          <Text style={styles.socialProofText}>
            {formatArabicMinutes(durationMinutes)}
          </Text>
        )}
        {ratingsCount > 0 && ratingAverage !== null ? (
          <View
            accessible
            accessibilityRole="text"
            accessibilityLabel={`التقييم ${formatArabicNumber(ratingAverage, {
              minimumFractionDigits: 1,
              maximumFractionDigits: 1,
            })} من ٥، ${formatArabicRatings(ratingsCount)}`}
            style={styles.ratingGroup}>
            <Text style={styles.ratingText}>
              ★{' '}
              {formatArabicNumber(ratingAverage, {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1,
              })}
            </Text>
            <Text style={styles.socialProofText}>
              {formatArabicRatings(ratingsCount)}
            </Text>
          </View>
        ) : (
          <Text style={styles.socialProofText}>لا توجد تقييمات</Text>
        )}
        {studentsCount > 0 && (
          <Text style={styles.socialProofText}>
            {formatArabicStudents(studentsCount)}
          </Text>
        )}
      </View>
    )}
    {!remoteError && showSecondaryPreview && (
      <Pressable
        accessibilityLabel="شاهد مجانًا"
        accessibilityRole="button"
        onPress={onPreview}
        style={({pressed}) => [
          styles.previewButton,
          pressed && styles.pressed,
        ]}>
        <Text style={styles.previewButtonText}>شاهد مجانًا</Text>
        <Text
          allowFontScaling={false}
          accessibilityElementsHidden
          style={styles.previewIcon}>
          ▶
        </Text>
      </Pressable>
    )}
  </View>
);

export const CourseActionBar = ({
  bottomInset,
  gutter,
  maxContentWidth,
  onPrimaryAction,
  pageReady,
  primaryActionDisabled,
  primaryActionLabel,
}: {
  bottomInset: number;
  gutter: number;
  maxContentWidth: number;
  onPrimaryAction: () => void;
  pageReady: boolean;
  primaryActionDisabled: boolean;
  primaryActionLabel: string;
}) => (
  <View style={[styles.actionBar, {paddingBottom: Math.max(bottomInset, 12)}]}>
    <View
      style={[
        styles.actionBarContent,
        {paddingHorizontal: gutter, maxWidth: maxContentWidth},
      ]}>
      <Pressable
        accessibilityLabel={primaryActionLabel}
        accessibilityRole="button"
        accessibilityState={{
          busy: !pageReady || primaryActionDisabled,
          disabled: !pageReady || primaryActionDisabled,
        }}
        disabled={!pageReady || primaryActionDisabled}
        onPress={onPrimaryAction}
        style={({pressed}) => [
          styles.primaryButton,
          pressed && styles.primaryButtonPressed,
          (!pageReady || primaryActionDisabled) && styles.disabled,
        ]}>
        {(!pageReady || primaryActionDisabled) && (
          <ActivityIndicator color={Palette.text} />
        )}
        <Text style={styles.primaryButtonText}>{primaryActionLabel}</Text>
      </Pressable>
    </View>
  </View>
);

type CourseRatingActionProps = {
  busy: boolean;
  editable: boolean;
  onDelete: () => void;
  onRate: (rating: number) => void;
  rating: number | null;
  visible: boolean;
};

export const CourseRatingAction = ({
  busy,
  editable,
  onDelete,
  onRate,
  rating,
  visible,
}: CourseRatingActionProps) => {
  if (!visible) return null;

  return (
    <View style={styles.ratingAction}>
      <Text style={styles.ratingActionTitle}>
        {rating ? 'تقييمك للكورس' : 'قيّم الكورس'}
      </Text>
      <View style={styles.ratingStars}>
        {[1, 2, 3, 4, 5].map(value => (
          <Pressable
            accessibilityLabel={`${value} من 5`}
            accessibilityRole="button"
            accessibilityState={{
              selected: rating === value,
              disabled: busy || !editable,
            }}
            disabled={busy || !editable}
            key={value}
            onPress={() => onRate(value)}
            style={({pressed}) => [
              styles.ratingStarButton,
              !editable && styles.disabled,
              pressed && styles.pressed,
            ]}>
            <Text
              allowFontScaling={false}
              style={[
                styles.ratingStar,
                value <= (rating ?? 0) && styles.ratingStarSelected,
              ]}>
              ★
            </Text>
          </Pressable>
        ))}
        {busy && <ActivityIndicator color={Palette.primary} size="small" />}
      </View>
      {rating && !busy ? (
        <Pressable
          accessibilityLabel="حذف تقييمي"
          accessibilityRole="button"
          onPress={onDelete}
          style={({pressed}) => [
            styles.ratingDeleteButton,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.ratingDeleteText}>حذف تقييمي</Text>
        </Pressable>
      ) : null}
    </View>
  );
};

type CourseBodyProps = {
  activeTab: 'about' | 'outline';
  onFullTrackUpgradeHandled: () => void;
  onOpenCertificates: () => void;
  onPreviewSelect: (reelId?: string) => void;
  onRetry: () => void;
  onTabChange: (tab: 'about' | 'outline') => void;
  openFullTrackUpgrade: boolean;
  owned: boolean;
  learningCourse: CourseLearningData | null;
  remoteCourse: CourseDetailsDto | null;
  remoteError: string;
  remoteLoading: boolean;
};

export const CourseBody = ({
  activeTab,
  onFullTrackUpgradeHandled,
  onOpenCertificates,
  onPreviewSelect: startPreview,
  onRetry,
  onTabChange,
  openFullTrackUpgrade,
  owned,
  learningCourse,
  remoteCourse,
  remoteError,
  remoteLoading,
}: CourseBodyProps) => (
  <>
    {remoteLoading ? (
      <CourseDetailsSkeleton />
    ) : remoteError ? (
      <StatusView
        actionLabel="إعادة المحاولة"
        description={remoteError}
        onAction={onRetry}
        state="error"
        title="تعذّر فتح تفاصيل الكورس"
      />
    ) : (
      <>
        <View style={styles.tabs} accessibilityRole="tablist">
          <Pressable
            accessibilityRole="tab"
            accessibilityState={{selected: activeTab === 'about'}}
            onPress={() => onTabChange('about')}
            style={[styles.tab, activeTab === 'about' && styles.tabActive]}>
            <Text
              style={[
                styles.tabText,
                activeTab === 'about' && styles.tabTextActive,
              ]}>
              عن الكورس
            </Text>
          </Pressable>
          <Pressable
            accessibilityRole="tab"
            accessibilityState={{selected: activeTab === 'outline'}}
            onPress={() => onTabChange('outline')}
            style={[styles.tab, activeTab === 'outline' && styles.tabActive]}>
            <Text
              style={[
                styles.tabText,
                activeTab === 'outline' && styles.tabTextActive,
              ]}>
              خريطة الكورس
            </Text>
          </Pressable>
        </View>
        {activeTab === 'about' ? (
          <CourseAbout details={remoteCourse} />
        ) : owned ? (
          <Lessons
            course={learningCourse}
            loading={remoteLoading}
            loadError={remoteError}
            onFullTrackUpgradeHandled={onFullTrackUpgradeHandled}
            onOpenCertificates={onOpenCertificates}
            onRetry={onRetry}
            openFullTrackUpgrade={openFullTrackUpgrade}
          />
        ) : (
          <LockedOutline
            details={remoteCourse}
            onPreviewSelect={startPreview}
          />
        )}
      </>
    )}
  </>
);
