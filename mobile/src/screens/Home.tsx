import {useIsFocused, useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {useCallback, useEffect, useMemo, useRef} from 'react';
import {
  Alert,
  Image,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import {useTranslation} from 'react-i18next';
import {NotificationIcon, SearchIcon} from '../assets/SVG';
import TabBar from '../components/TabBar';
import {Container, Content} from '../components/containers/Containers';
import {ResponsiveFrame} from '../components/ui/PremiumUI';
import {
  Accessibility,
  fixedIconSlot,
  flexibleTextColumn,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../constants/designSystem';
import type {Course} from '../types/Course';
import {sessionIdentityKey} from '../constants/helpers';
import {normalizeText} from '../utils/searchText';
import SearchAssist from '../components/search/SearchAssist';
import {trackProductEvent} from '../services/productAnalytics';
import {
  buildHomeSections,
  buildQuickSearches,
  searchHomeCatalogue,
  selectHeroCourses,
} from './home/homeCatalogue';
import {useHomeCatalogue} from './home/useHomeCatalogue';
import HomeCatalogueFeed from './home/HomeCatalogueFeed';
import {HomeOverlays} from './home/HomeOverlays';
import {useAppActiveState} from '../hooks/useAppActiveState';
import {useSelector} from 'react-redux';
import type {RootState} from '../store/store';
import {useHomeScrollMemory} from './home/useHomeScrollMemory';
import {useHomeEngagement} from './home/useHomeEngagement';
import {useHomeSearch} from './home/useHomeSearch';

const QUICK_SEARCHES = [
  'العمل الحر',
  'التسويق',
  'التصميم',
  'صناعة المحتوى',
  'اللغات',
];

const Home = () => {
  const navigation = useNavigation<RootNavigation>();
  const screenFocused = useIsFocused();
  const appIsActive = useAppActiveState();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);
  const {t} = useTranslation();
  const search = useHomeSearch(identityKey);
  const courseNavigationFlightRef = useRef(false);

  useEffect(() => {
    if (screenFocused) courseNavigationFlightRef.current = false;
  }, [screenFocused]);

  const openCourseDetailsOnce = useCallback(
    (course: Pick<Course, 'id'>) => {
      if (courseNavigationFlightRef.current) return false;
      courseNavigationFlightRef.current = true;
      navigation.navigate('CourseDetails', {
        courseId: course.id,
      });
      return true;
    },
    [navigation],
  );

  const {
    catalogue,
    error: catalogueError,
    handleScroll: handleCatalogueScroll,
    loadMore: loadMoreCatalogue,
    loading: catalogueLoading,
    loadingMore,
    loadMoreError,
    loadedSearchQuery,
    serverSession,
    refresh: refreshCatalogue,
    remoteCourses,
    staleNotice,
  } = useHomeCatalogue({
    active: screenFocused,
    appIsActive,
    identityKey,
    searchQuery: search.query,
  });
  const homeScroll = useHomeScrollMemory({
    active: screenFocused && appIsActive,
    identityKey,
    loading: catalogueLoading,
    searchQuery: search.query,
    setSearchQuery: search.setQuery,
  });
  const engagement = useHomeEngagement({
    active: screenFocused && appIsActive,
    identityKey,
    loading: catalogueLoading,
    navigation,
    openCourse: openCourseDetailsOnce,
    remoteCourses,
    serverSession,
  });

  useEffect(() => {
    void trackProductEvent({event_name: 'home_viewed', screen_key: 'home'});
  }, []);

  const homeSections = useMemo(() => {
    return buildHomeSections({catalogue});
  }, [catalogue]);

  const heroCourses = useMemo(() => selectHeroCourses(catalogue), [catalogue]);

  const quickSearches = useMemo(
    () => buildQuickSearches(catalogue, QUICK_SEARCHES),
    [catalogue],
  );

  const searchMatches = useMemo(
    () =>
      searchHomeCatalogue({
        catalogue,
        remoteCourses,
        searchQuery: search.query,
        loadedSearchQuery,
      }),
    [catalogue, loadedSearchQuery, remoteCourses, search.query],
  );

  const hasSearchQuery = Boolean(normalizeText(search.query));

  const handleHomeScroll = useCallback(
    (event: Parameters<typeof handleCatalogueScroll>[0]) => {
      handleCatalogueScroll(event);
      homeScroll.record(event.nativeEvent.contentOffset.y);
    },
    [handleCatalogueScroll, homeScroll],
  );

  useEffect(() => {
    if (
      hasSearchQuery &&
      !catalogueLoading &&
      !catalogueError &&
      searchMatches.length === 0
    ) {
      void trackProductEvent({
        event_name: 'search_zero_results',
        screen_key: 'search',
        value: Math.min(search.query.trim().length, 200),
      });
    }
  }, [
    catalogueError,
    catalogueLoading,
    hasSearchQuery,
    searchMatches.length,
    search.query,
  ]);

  const openCourse = useCallback(
    (course: Course) => {
      if (course.published === false) {
        Alert.alert('قريبًا', 'هذا الكورس قيد الإعداد');
        return;
      }
      if (!openCourseDetailsOnce(course)) return;
      void trackProductEvent({
        event_name: 'course_opened',
        screen_key: 'home',
        course_id: course.id,
      });
    },
    [openCourseDetailsOnce],
  );

  return (
    <Container noPadding>
      <Content
        controls={homeScroll.bind}
        noPadding
        onScroll={handleHomeScroll}
        onScrollBeginDrag={homeScroll.markUserMoved}
        scrollEventThrottle={250}>
        <ResponsiveFrame>
          <View style={styles.topView}>
            <View style={styles.brandCopy}>
              <Image
                source={require('../assets/images/logo.png')}
                style={styles.logo}
              />
            </View>
            <Pressable
              accessibilityLabel={t('Notifications')}
              accessibilityRole="button"
              hitSlop={6}
              onPress={() => navigation.navigate('Notifications')}
              style={({pressed}) => [
                styles.iconButton,
                pressed && styles.pressed,
              ]}>
              <NotificationIcon />
            </Pressable>
          </View>

          <View style={styles.searchContainer}>
            <View style={styles.searchIconSlot}>
              <SearchIcon color={Palette.textMuted} />
            </View>
            <TextInput
              accessibilityLabel={t('Search')}
              autoCorrect={false}
              onBlur={search.blur}
              onChangeText={search.setQuery}
              onFocus={search.focus}
              onSubmitEditing={() => search.commit(search.query)}
              placeholder="ابحث عن مهارة أو كورس"
              placeholderTextColor={Palette.textFaint}
              returnKeyType="search"
              selectionColor={Palette.primary}
              style={styles.searchInput}
              value={search.query}
            />
            {!!search.query && (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('Close')}
                hitSlop={8}
                onPress={() => search.setQuery('')}
                style={styles.clearSearch}>
                <Text style={styles.clearSearchText}>×</Text>
              </Pressable>
            )}
          </View>
          <SearchAssist
            onClearRecent={search.clearHistory}
            onSelect={search.commit}
            recent={search.history}
            searching={hasSearchQuery && catalogueLoading}
            suggestions={quickSearches}
            visible={search.focused && !hasSearchQuery}
          />
        </ResponsiveFrame>

        <HomeCatalogueFeed
          active={screenFocused && appIsActive}
          error={catalogueError}
          hasSearchQuery={hasSearchQuery}
          heroCourses={heroCourses}
          loadMoreError={loadMoreError}
          loading={catalogueLoading}
          loadingMore={loadingMore}
          onLoadMore={loadMoreCatalogue}
          onOpenCourse={openCourse}
          onRefresh={refreshCatalogue}
          searchMatches={searchMatches}
          sections={homeSections}
          staleNotice={staleNotice}
        />
      </Content>
      <TabBar />
      <HomeOverlays
        campaign={engagement.campaign}
        campaignImageFailed={engagement.campaignImageFailed}
        onCampaignImageError={engagement.markCampaignImageFailed}
        onDismissCampaign={open => {
          void engagement.dismissCampaign(open).catch(() => undefined);
        }}
        onDismissWelcome={engagement.dismissWelcome}
        onOpenWelcome={engagement.openWelcome}
        guestPrompt={engagement.guestPrompt}
        onDismissGuestPrompt={engagement.dismissGuest}
        onOpenGuestPrompt={engagement.openGuest}
        welcomeMessage={engagement.welcome}
        rewardPrompt={engagement.rewardPrompt}
        onDismissRewardPrompt={engagement.dismissReward}
        onOpenRewardPrompt={engagement.openReward}
      />
    </Container>
  );
};

const styles = StyleSheet.create({
  topView: {
    minHeight: 70,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: Spacing.xs,
  },
  brandCopy: {
    ...flexibleTextColumn,
    direction: 'rtl',
    alignItems: 'flex-start',
    justifyContent: 'center',
  },
  logo: {width: 94, height: 38, resizeMode: 'contain'},
  iconButton: {
    ...fixedIconSlot,
    borderRadius: Radius.md,
    backgroundColor: Palette.surface,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  searchContainer: {
    alignItems: 'center',
    backgroundColor: Palette.surface,
    borderColor: Palette.lineSoft,
    borderRadius: Radius.md,
    borderWidth: 1,
    ...rtlRowStyle,
    minHeight: 52,
    marginBottom: Spacing.lg,
    paddingHorizontal: Spacing.md,
  },
  searchInput: {
    ...Type.body,
    ...textDirection,
    color: Palette.text,
    flex: 1,
    minWidth: 0,
    minHeight: 50,
    marginHorizontal: Spacing.sm,
    paddingVertical: 0,
    textAlignVertical: 'center',
  },
  searchIconSlot: {
    ...fixedIconSlot,
    width: 30,
    minWidth: 30,
  },
  clearSearch: {
    alignItems: 'center',
    height: Accessibility.minTouchTarget,
    justifyContent: 'center',
    width: Accessibility.minTouchTarget,
  },
  clearSearchText: {color: Palette.textMuted, fontSize: 24, lineHeight: 28},
  pressed: {opacity: 0.75},
});

export default Home;
