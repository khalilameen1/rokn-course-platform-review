import React from 'react';
import {FlatList, StatusBar, StyleSheet, View} from 'react-native';
import CourseChatOverlay from '../../components/VideoPlayer/CourseChatOverlay';
import NotificationPermissionPrimer from '../../components/ui/NotificationPermissionPrimer';
import {
  ReelsConnectionNote,
  ReelsLoadingState,
  ReelsPreviewGate,
  ReelsUnavailableState,
} from './ReelsPresentation';
import type {ReelsController} from './useReelsController';

const ReelsSurface = (controller: ReelsController) => {
  const {
    course,
    feedItems,
    insets,
    layout,
    loadError,
    loading,
  } = controller;

  return (
    <View style={styles.screen} onLayout={controller.onLayout}>
      <StatusBar
        translucent
        barStyle="light-content"
        backgroundColor="transparent"
      />
      {loading || (!course && !loadError) || !layout.height ? (
        <ReelsLoadingState />
      ) : loadError || !course ? (
        <ReelsUnavailableState
          message={loadError}
          onPrimary={controller.onReload}
          onSecondary={controller.onBack}
          primaryLabel="إعادة المحاولة"
          secondaryLabel="العودة للكورسات"
          title="تعذّر فتح الكورس"
        />
      ) : !feedItems.length ? (
        <ReelsUnavailableState
          message="لا توجد مقاطع منشورة أو مشروع مفتوح لهذا الكورس"
          onPrimary={controller.onReload}
          onSecondary={controller.onEmptyCourseDetails}
          primaryLabel="تحديث المحتوى"
          secondaryLabel="فتح تفاصيل الكورس"
          title="لا يوجد مقطع متاح الآن"
        />
      ) : (
        <>
          <FlatList
            accessibilityLabel="مقاطع الكورس"
            key={`reels:${controller.identityKey}:${course.id}`}
            ref={controller.listRef}
            data={feedItems}
            keyExtractor={item => item.key}
            renderItem={controller.renderItem}
            pagingEnabled
            scrollEnabled={controller.scrollEnabled}
            bounces={false}
            decelerationRate="fast"
            snapToInterval={layout.height}
            snapToAlignment="start"
            disableIntervalMomentum
            showsVerticalScrollIndicator={false}
            initialNumToRender={2}
            maxToRenderPerBatch={2}
            windowSize={3}
            // Keep video surfaces attached on wide Android tablets. Clipping
            // may restore audio while leaving the recycled frame black.
            removeClippedSubviews={false}
            getItemLayout={(_, index) => ({
              length: layout.height,
              offset: layout.height * index,
              index,
            })}
            viewabilityConfig={controller.viewabilityConfig}
            onViewableItemsChanged={controller.onViewableItemsChanged}
            scrollEventThrottle={16}
            onScroll={event =>
              controller.onScroll(event.nativeEvent.contentOffset.y)
            }
            onScrollBeginDrag={controller.onPagingStarted}
            onMomentumScrollBegin={controller.onPagingStarted}
            onMomentumScrollEnd={event =>
              controller.onPagingSettled(event.nativeEvent.contentOffset.y)
            }
            onScrollEndDrag={event => {
              if (Math.abs(event.nativeEvent.velocity?.y || 0) < 0.05) {
                controller.onPagingSettled(event.nativeEvent.contentOffset.y);
              }
            }}
            onScrollToIndexFailed={({index}) =>
              controller.onScrollToIndexFailed(index)
            }
          />
          {controller.previewGateVisible && (
            <ReelsPreviewGate
              bottomInset={insets.bottom}
              onBackToDetails={() => controller.showCourseDetails(false)}
              onStartLearning={() => controller.showCourseDetails(true)}
              previewCount={feedItems.length}
              topInset={insets.top}
            />
          )}
          {!!controller.connectionNote &&
            !controller.previewGateVisible && (
              <ReelsConnectionNote
                message={controller.connectionNote}
                onPress={controller.onConnectionNotePress}
                topInset={insets.top}
              />
            )}
          {controller.canOpenCourseAssistant && (
            <CourseChatOverlay
              visible={controller.chatVisible}
              course={course}
              reel={controller.currentReel}
              onClose={controller.closeChat}
            />
          )}
          <NotificationPermissionPrimer
            onClose={controller.closeReminderNudge}
            onEnable={controller.enableRemindersFromNudge}
            visible={controller.reminderNudgeVisible}
          />
        </>
      )}
    </View>
  );
};

export default ReelsSurface;

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#000000',
  },
});
