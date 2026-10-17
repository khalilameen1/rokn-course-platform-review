import {useNavigation} from '@react-navigation/native';
import React from 'react';
import {Pressable, ScrollView, Text, TextInput, View} from 'react-native';
import {Swipeable} from 'react-native-gesture-handler';
import {CloseChat} from '../../assets/SVG';
import {StatusView, SectionHeading} from '../../components/ui/PremiumUI';
import {SavedLibrarySkeleton} from '../../components/ui/Skeleton';
import {
  formatAuthoredDisplayText,
  formatArabicNumber,
} from '../../constants/arabicFormatting';
import {Palette} from '../../constants/designSystem';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import type {RootNavigation} from '../../navigation/types';
import {SavedLessonRow} from './SavedLessonRow';
import {savedLibraryStyles as styles} from './styles';
import {useSavedLibrary} from './useSavedLibrary';

export default function SavedVideos({
  initialFolderId,
}: {
  initialFolderId?: string;
}) {
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
  } = useSavedLibrary(initialFolderId);

  if (
    !identityOwned ||
    (loading &&
      !saved.length &&
      !folderOptions.length &&
      activeFolderId === 'all')
  ) {
    return <SavedLibrarySkeleton />;
  }

  if (
    error &&
    !saved.length &&
    !folderOptions.length &&
    activeFolderId === 'all'
  ) {
    return (
      <StatusView
        actionLabel="إعادة المحاولة"
        description={error}
        onAction={() => retry()}
        state="error"
        title="معرفناش نحمّل المحفوظات"
      />
    );
  }

  if (serverSession === false) {
    return (
      <StatusView
        actionLabel="تسجيل الدخول"
        description="سجّل الدخول لعرض محفوظاتك على أي جهاز"
        onAction={() =>
          openGuestLogin(navigation, {name: 'SavedLibrary'})
        }
        state="empty"
        title="محفوظاتك مرتبطة بحسابك"
      />
    );
  }

  // A primary failure without rows already has its full error state below.
  // Folder/index failures remain recoverable beside successful lesson content.
  const partialReadError = saved.length
    ? error || folderLoadError
    : error
    ? ''
    : folderLoadError;

  return (
    <View style={styles.container}>
      <SectionHeading
        actionLabel={showCreateFolder ? 'إلغاء' : 'قائمة جديدة'}
        onAction={toggleCreateFolder}
        title="قوائمك"
      />
      {partialReadError ? (
        <Pressable
          accessibilityLabel="إعادة تحميل المحفوظات والقوائم"
          accessibilityRole="button"
          accessibilityState={{disabled: loading, busy: loading}}
          disabled={loading}
          onPress={() => retry()}
          style={({pressed}) => [
            styles.retryNotice,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.retryNoticeText}>{partialReadError}</Text>
          <Text style={styles.retryNoticeAction}>
            {loading ? 'جارٍ التحديث' : 'إعادة المحاولة'}
          </Text>
        </Pressable>
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
            الكل
          </Text>
        </Pressable>
        {folderOptions.map(folder => {
          const count = folderCounts.get(folder.id);
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
                {formatAuthoredDisplayText(folder.name)}
                {count !== undefined ? ` · ${formatArabicNumber(count)}` : ''}
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

      {loading && !saved.length ? (
        <SavedLibrarySkeleton />
      ) : error && !saved.length ? (
        <StatusView
          actionLabel="إعادة المحاولة"
          description={error}
          onAction={() => retry()}
          state="error"
          title="معرفناش نحمّل المحفوظات"
        />
      ) : !saved.length && activeFolderId === 'all' ? (
        <StatusView
          description="اضغط احفظ وانت بتتفرج وهيتحفظ هنا"
          state="empty"
          title="لسه مفيش حاجة محفوظة"
        />
      ) : !visibleSaved.length ? (
        <StatusView
          description="بعد ما تحفظ درس اضغط غيّر واختار القائمة دي"
          state="empty"
          title="القائمة دي فاضية"
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
                    <SavedLessonRow
                      item={item}
                      onPress={() =>
                        navigation.navigate('Reels', {
                          courseId: item.courseId,
                          lessonId: item.id,
                        })
                      }
                      trailing={
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
                          {removalPending ? (
                            <Text style={styles.removeText}>…</Text>
                          ) : (
                            <CloseChat width={14} height={14} />
                          )}
                        </Pressable>
                      }
                    />
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
