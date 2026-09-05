import {useNavigation} from '@react-navigation/native';
import React from 'react';
import {
  Image,
  Pressable,
  ScrollView,
  Text,
  TextInput,
  View,
} from 'react-native';
import {Swipeable} from 'react-native-gesture-handler';
import {StatusView, SectionHeading} from '../../components/ui/PremiumUI';
import {SavedLibrarySkeleton} from '../../components/ui/Skeleton';
import {
  formatAuthoredDisplayText,
  formatArabicNumber,
  toArabicDigits,
} from '../../constants/arabicFormatting';
import {Palette} from '../../constants/designSystem';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import type {RootNavigation} from '../../navigation/types';
import {savedLibraryStyles as styles} from './saved/styles';
import {useSavedLibrary} from './saved/useSavedLibrary';

export default function SavedVideos() {
  const navigation = useNavigation<RootNavigation>();
  const {
    actionError,
    activeFolderId,
    createFolder,
    creatingFolder,
    deleteActiveFolder,
    deletingFolder,
    error,
    folderCounts,
    folderError,
    folderLoadError,
    folderOptions,
    groupedSaved,
    identityOwned,
    loadMore,
    loading,
    loadingMore,
    loadMoreError,
    newFolderName,
    nextPage,
    removeSaved,
    removingSaved,
    retry,
    saved,
    selectFolder,
    serverSession,
    setNewFolderName,
    showCreateFolder,
    toggleCreateFolder,
    visibleSaved,
  } = useSavedLibrary();

  if (!identityOwned || (loading && !saved.length)) {
    return <SavedLibrarySkeleton />;
  }

  if (error && !saved.length) {
    return (
      <StatusView
        actionLabel="إعادة المحاولة"
        description={error}
        onAction={() => retry()}
        state="error"
        title="تعذّر تحميل المحفوظات"
      />
    );
  }

  if (serverSession === false) {
    return (
      <StatusView
        actionLabel="تسجيل الدخول"
        description="سجّل الدخول لعرض محفوظاتك على أي جهاز"
        onAction={() =>
          openGuestLogin(navigation, {
            name: 'Profile',
            params: {tab: 'saved'},
          })
        }
        state="empty"
        title="محفوظاتك مرتبطة بحسابك"
      />
    );
  }

  return (
    <View style={styles.container}>
      <SectionHeading
        actionLabel={showCreateFolder ? 'إلغاء' : 'قائمة جديدة'}
        onAction={toggleCreateFolder}
        title="محفوظاتك"
      />
      {!!error && saved.length ? (
        <Pressable
          accessibilityRole="button"
          onPress={() => retry()}
          style={({pressed}) => [
            styles.retryNotice,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.retryNoticeText}>{error}</Text>
          <Text style={styles.retryNoticeAction}>إعادة المحاولة</Text>
        </Pressable>
      ) : null}
      {folderLoadError ? (
        <Text accessibilityRole="alert" style={styles.actionError}>
          {folderLoadError}
        </Text>
      ) : null}
      {showCreateFolder ? (
        <View style={styles.createFolderCard}>
          <Text style={styles.createFolderTitle}>اسم القائمة الجديدة</Text>
          <View style={styles.createFolderRow}>
            <TextInput
              accessibilityLabel="اسم القائمة الجديدة"
              autoFocus
              maxLength={60}
              onChangeText={setNewFolderName}
              onSubmitEditing={createFolder}
              placeholder="مثلاً: أراجعها هذا الأسبوع"
              placeholderTextColor={Palette.textFaint}
              returnKeyType="done"
              selectionColor={Palette.primary}
              style={styles.folderInput}
              value={newFolderName}
            />
            <Pressable
              accessibilityRole="button"
              disabled={!newFolderName.trim() || creatingFolder}
              onPress={createFolder}
              style={({pressed}) => [
                styles.createButton,
                (!newFolderName.trim() || creatingFolder) &&
                  styles.createButtonDisabled,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.createButtonText}>
                {creatingFolder ? 'جارٍ الإنشاء' : 'إنشاء'}
              </Text>
            </Pressable>
          </View>
          {!!folderError && (
            <Text accessibilityRole="alert" style={styles.inlineError}>
              {folderError}
            </Text>
          )}
        </View>
      ) : null}

      <ScrollView
        contentContainerStyle={styles.folderChips}
        horizontal
        showsHorizontalScrollIndicator={false}>
        <Pressable
          accessibilityRole="button"
          accessibilityState={{selected: activeFolderId === 'all'}}
          onPress={() => selectFolder('all')}
          style={[
            styles.folderChip,
            activeFolderId === 'all' && styles.folderChipActive,
          ]}>
          <Text
            style={[
              styles.folderChipText,
              activeFolderId === 'all' && styles.folderChipTextActive,
            ]}>
            الكل · {formatArabicNumber(saved.length)}
          </Text>
        </Pressable>
        {folderOptions.map(folder => {
          const count = folderCounts.get(folder.id) ?? 0;
          return (
            <Pressable
              accessibilityRole="button"
              accessibilityState={{selected: activeFolderId === folder.id}}
              key={folder.id}
              onPress={() => selectFolder(folder.id)}
              style={[
                styles.folderChip,
                activeFolderId === folder.id && styles.folderChipActive,
              ]}>
              <Text
                numberOfLines={1}
                style={[
                  styles.folderChipText,
                  activeFolderId === folder.id && styles.folderChipTextActive,
                ]}>
                {formatAuthoredDisplayText(folder.name)} ·{' '}
                {formatArabicNumber(count)}
              </Text>
            </Pressable>
          );
        })}
      </ScrollView>

      {activeFolderId !== 'all' ? (
        <Pressable
          accessibilityLabel="حذف القائمة المحددة"
          accessibilityRole="button"
          disabled={deletingFolder}
          onPress={deleteActiveFolder}
          style={({pressed}) => [
            styles.deleteFolderButton,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.deleteFolderText}>
            {deletingFolder ? 'جارٍ حذف القائمة' : 'حذف هذه القائمة'}
          </Text>
        </Pressable>
      ) : null}

      {!!folderError && !showCreateFolder ? (
        <Text accessibilityRole="alert" style={styles.inlineError}>
          {folderError}
        </Text>
      ) : null}

      {!!actionError && (
        <Text accessibilityRole="alert" style={styles.actionError}>
          {actionError}
        </Text>
      )}

      {!saved.length ? (
        <StatusView
          description="اضغط حفظ أثناء المشاهدة واختر القائمة المناسبة"
          state="empty"
          title="لا توجد مقاطع محفوظة"
        />
      ) : !visibleSaved.length ? (
        <StatusView
          description="اختر هذه القائمة عند الضغط على حفظ"
          state="empty"
          title="لا توجد مقاطع في هذه القائمة"
        />
      ) : null}

      {groupedSaved.map(([folderId, group]) => (
        <View key={folderId} style={styles.folder}>
          {activeFolderId === 'all' ? (
            <Text style={styles.folderTitle}>
              {formatAuthoredDisplayText(group.name)}
            </Text>
          ) : null}
          <View style={styles.list}>
            {group.items.map((item, index) => {
              const removalKey = `${item.folderId}:${item.id}`;
              const removalPending = removingSaved.has(removalKey);
              return (
                <View key={`${item.folderId}:${item.id}`}>
                  <Swipeable
                    enabled={!removalPending}
                    friction={2}
                    overshootLeft={false}
                    overshootRight={false}
                    renderRightActions={() => (
                      <Pressable
                        accessibilityLabel="إزالة من هذه القائمة"
                        accessibilityRole="button"
                        accessibilityState={{disabled: removalPending}}
                        disabled={removalPending}
                        onPress={() => void removeSaved(item)}
                        style={styles.swipeDelete}>
                        <Text style={styles.swipeDeleteText}>إزالة</Text>
                      </Pressable>
                    )}>
                    <Pressable
                      accessibilityLabel={`تشغيل ${item.title}`}
                      accessibilityRole="button"
                      onPress={() =>
                        navigation.navigate('Reels', {
                          courseId: item.courseId,
                          lessonId: item.id,
                        })
                      }
                      style={({pressed}) => [
                        styles.row,
                        pressed && styles.pressed,
                      ]}>
                      <View style={styles.thumbWrap}>
                        <Image
                          progressiveRenderingEnabled
                          resizeMethod="resize"
                          source={
                            item.imageUrl
                              ? {uri: item.imageUrl}
                              : require('../../assets/images/courseSliderBackground.jpg')
                          }
                          style={styles.thumb}
                        />
                        <View style={styles.playMark}>
                          <Text style={styles.playText}>▶</Text>
                        </View>
                      </View>
                      <View style={styles.copy}>
                        <Text numberOfLines={2} style={styles.title}>
                          {formatAuthoredDisplayText(item.title)}
                        </Text>
                        <Text numberOfLines={1} style={styles.course}>
                          {formatAuthoredDisplayText(item.courseTitle)}
                        </Text>
                        <Text style={styles.duration}>
                          {toArabicDigits(item.duration)}
                        </Text>
                      </View>
                      <Pressable
                        accessibilityLabel={
                          removalPending
                            ? 'جارٍ الإزالة'
                            : 'إزالة من هذه القائمة'
                        }
                        accessibilityRole="button"
                        accessibilityState={{
                          busy: removalPending,
                          disabled: removalPending,
                        }}
                        disabled={removalPending}
                        hitSlop={8}
                        onPress={event => {
                          event.stopPropagation();
                          void removeSaved(item);
                        }}
                        style={styles.removeButton}>
                        <Text style={styles.removeText}>
                          {removalPending ? '…' : '×'}
                        </Text>
                      </Pressable>
                    </Pressable>
                  </Swipeable>
                  {index < group.items.length - 1 && (
                    <View style={styles.divider} />
                  )}
                </View>
              );
            })}
          </View>
        </View>
      ))}
      {nextPage ? (
        <View style={styles.moreWrap}>
          {loadMoreError ? (
            <Text accessibilityRole="alert" style={styles.moreError}>
              {loadMoreError}
            </Text>
          ) : null}
          <Pressable
            accessibilityLabel={
              loadingMore ? 'جارٍ تحميل المزيد' : 'عرض محفوظات أكثر'
            }
            accessibilityRole="button"
            disabled={loadingMore}
            onPress={loadMore}
            style={({pressed}) => [
              styles.moreButton,
              pressed && styles.pressed,
              loadingMore && styles.moreButtonDisabled,
            ]}>
            <Text style={styles.moreButtonText}>
              {loadingMore ? 'جارٍ تحميل المزيد' : 'عرض المزيد'}
            </Text>
          </Pressable>
        </View>
      ) : null}
    </View>
  );
}
