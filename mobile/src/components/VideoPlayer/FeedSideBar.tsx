import {
  BottomSheetBackdrop,
  type BottomSheetBackdropProps,
  BottomSheetModal,
  BottomSheetScrollView,
} from '@gorhom/bottom-sheet';
import React, {useCallback, useEffect, useMemo, useRef} from 'react';
import {
  ActivityIndicator,
  Pressable,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';
import {formatArabicNumber} from '../../constants/arabicFormatting';
import {SavedFolderOption} from './courseLearningApi';
import {CourseLearningData, CourseReel} from './types';
import {openCourseAttachment} from './attachmentActions';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {courseLearningProgress} from './courseLearning/sequence';
import {hasCourseLearningAccess} from './courseEntitlements';
import FeedActions, {AttachmentIcon} from './feedSideBar/FeedActions';
import CourseIndexModule from './feedSideBar/CourseIndexModule';
import {feedSideBarStyles as styles} from './feedSideBar/styles';
import {useAttachmentPrompt} from './feedSideBar/useAttachmentPrompt';
import {useSavedFolderPicker} from './feedSideBar/useSavedFolderPicker';

interface FeedSideBarProps {
  course: CourseLearningData;
  currentReel: CourseReel;
  currentFeedKey: string;
  isSaved: boolean;
  savePending: boolean;
  bottomInset?: number;
  onToggleSave: (folder?: SavedFolderOption | null) => void;
  onBeforeOpenSave: () => boolean;
  onOpenChat: () => void;
  onOverlayVisibilityChange?: (visible: boolean) => void;
  onSelectFeedItem: (key: string) => void;
  currentTime: number;
}

const FeedSideBar = ({
  course,
  currentReel,
  currentFeedKey,
  isSaved,
  savePending,
  bottomInset = 0,
  onToggleSave,
  onBeforeOpenSave,
  onOpenChat,
  onOverlayVisibilityChange,
  onSelectFeedItem,
  currentTime,
}: FeedSideBarProps) => {
  const {height, fontScale} = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const compact = height < 620 || fontScale > 1.25;
  const indexSheetRef = useRef<BottomSheetModal>(null);
  const saveSheetRef = useRef<BottomSheetModal>(null);
  const attachmentSheetRef = useRef<BottomSheetModal>(null);
  const openSheetsRef = useRef(new Set<'index' | 'save' | 'attachment'>());
  const snapPoints = useMemo(() => ['78%', '94%'], []);
  const saveSnapPoints = useMemo(() => ['52%', '72%'], []);
  const attachmentSnapPoints = useMemo(() => ['48%', '72%'], []);
  const presentAttachments = useCallback(
    () => attachmentSheetRef.current?.present(),
    [],
  );
  const presentSaveSheet = useCallback(
    () => saveSheetRef.current?.present(),
    [],
  );
  const dismissSaveSheet = useCallback(
    () => saveSheetRef.current?.dismiss(),
    [],
  );
  const {attachments, markAttachmentsVisible, openAttachments} =
    useAttachmentPrompt({
      course,
      currentTime,
      present: presentAttachments,
    });
  const {
    createAndSave,
    creating: folderBusy,
    error: folderError,
    folders,
    loading: foldersLoading,
    name: newFolderName,
    open: openSaveSheet,
    saveInFolder,
    setName: setNewFolderName,
  } = useSavedFolderPicker({
    dismiss: dismissSaveSheet,
    onBeforeOpen: onBeforeOpenSave,
    onToggleSave,
    present: presentSaveSheet,
  });

  const reportSheetState = useCallback(
    (sheet: 'index' | 'save' | 'attachment', visible: boolean) => {
      if (visible) {
        openSheetsRef.current.add(sheet);
      } else {
        openSheetsRef.current.delete(sheet);
      }
      onOverlayVisibilityChange?.(openSheetsRef.current.size > 0);
    },
    [onOverlayVisibilityChange],
  );

  useEffect(() => {
    const openSheets = openSheetsRef.current;
    return () => {
      openSheets.clear();
      onOverlayVisibilityChange?.(false);
    };
  }, [onOverlayVisibilityChange]);
  const progress = courseLearningProgress(course.modules);

  const renderBackdrop = useCallback(
    (props: BottomSheetBackdropProps) => (
      <BottomSheetBackdrop
        {...props}
        appearsOnIndex={0}
        disappearsOnIndex={-1}
        opacity={0.55}
        pressBehavior="close"
      />
    ),
    [],
  );

  const handleSelect = (key: string) => {
    onSelectFeedItem(key);
    indexSheetRef.current?.dismiss();
  };

  return (
    <>
      <FeedActions
        bottomInset={bottomInset}
        compact={compact}
        currentReelNumber={currentReel.reelNumber}
        isSaved={isSaved}
        savePending={savePending}
        showAttachments={attachments.length > 0}
        showChat={hasCourseLearningAccess(course.accessType)}
        totalReels={course.totalReels}
        onOpenAttachments={openAttachments}
        onOpenChat={onOpenChat}
        onOpenIndex={() => indexSheetRef.current?.present()}
        onOpenSave={openSaveSheet}
      />

      <BottomSheetModal
        ref={indexSheetRef}
        snapPoints={snapPoints}
        animateOnMount={!reducedMotion}
        bottomInset={bottomInset}
        enableDynamicSizing={false}
        enablePanDownToClose
        topInset={insets.top}
        backdropComponent={renderBackdrop}
        onChange={index => reportSheetState('index', index >= 0)}
        onDismiss={() => reportSheetState('index', false)}
        backgroundStyle={styles.sheetBackground}
        handleIndicatorStyle={styles.sheetIndicator}>
        <BottomSheetScrollView
          accessibilityViewIsModal
          showsVerticalScrollIndicator={false}
          contentContainerStyle={styles.sheetContent}>
          <View style={styles.sheetHeader}>
            <View style={styles.sheetHeaderCopy}>
              <Text style={styles.sheetEyebrow}>فهرس الكورس</Text>
              <Text accessibilityRole="header" style={styles.sheetTitle}>
                {course.title}
              </Text>
            </View>
            <View style={styles.progressPill}>
              <Text style={styles.progressText}>
                {formatArabicNumber(progress.completed)}/
                {formatArabicNumber(progress.total)}
              </Text>
            </View>
          </View>
          <View style={styles.progressTrack}>
            <View
              style={[
                styles.progressFill,
                {
                  width: `${Math.min(
                    100,
                    (progress.completed / Math.max(1, progress.total)) * 100,
                  )}%`,
                },
              ]}
            />
          </View>

          {course.modules.map(module => (
            <CourseIndexModule
              key={module.id}
              module={module}
              currentFeedKey={currentFeedKey}
              onSelect={handleSelect}
            />
          ))}
        </BottomSheetScrollView>
      </BottomSheetModal>

      <BottomSheetModal
        ref={attachmentSheetRef}
        snapPoints={attachmentSnapPoints}
        animateOnMount={!reducedMotion}
        bottomInset={bottomInset}
        enableDynamicSizing={false}
        enablePanDownToClose
        topInset={insets.top}
        backdropComponent={renderBackdrop}
        onChange={index => {
          const visible = index >= 0;
          reportSheetState('attachment', visible);
          if (visible) markAttachmentsVisible();
        }}
        onDismiss={() => reportSheetState('attachment', false)}
        backgroundStyle={styles.sheetBackground}
        handleIndicatorStyle={styles.sheetIndicator}>
        <BottomSheetScrollView
          accessibilityViewIsModal
          showsVerticalScrollIndicator={false}
          contentContainerStyle={styles.attachmentSheetContent}>
          <Text style={styles.sheetEyebrow}>ملفات الكورس</Text>
          <Text accessibilityRole="header" style={styles.attachmentTitle}>
            {course.attachmentPrompt?.title || 'مرفقات تساعدك في التطبيق'}
          </Text>
          <Text style={styles.attachmentBody}>
            {course.attachmentPrompt?.body ||
              'حمّل الملفات واستخدمها مع محتوى الكورس'}
          </Text>
          <View style={styles.attachmentList}>
            {attachments.map(attachment => (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={`تنزيل ${attachment.title}`}
                key={attachment.id}
                onPress={() => void openCourseAttachment(attachment)}
                style={({pressed}) => [
                  styles.attachmentRow,
                  pressed && styles.pressed,
                ]}>
                <View style={styles.attachmentGlyph}>
                  <AttachmentIcon />
                </View>
                <View style={styles.attachmentCopy}>
                  <Text style={styles.attachmentName}>{attachment.title}</Text>
                  <Text style={styles.attachmentMeta}>
                    {attachment.platform === 'computer'
                      ? 'يُفتح من الكمبيوتر'
                      : attachment.fileSize ||
                        attachment.fileType ||
                        'ملف مرفق'}
                  </Text>
                </View>
                <Text style={styles.attachmentAction}>
                  {course.attachmentPrompt?.buttonText || 'تحميل'}
                </Text>
              </Pressable>
            ))}
          </View>
        </BottomSheetScrollView>
      </BottomSheetModal>

      <BottomSheetModal
        ref={saveSheetRef}
        snapPoints={saveSnapPoints}
        android_keyboardInputMode="adjustResize"
        animateOnMount={!reducedMotion}
        bottomInset={bottomInset}
        enableBlurKeyboardOnGesture
        enableDynamicSizing={false}
        enablePanDownToClose
        keyboardBehavior="interactive"
        keyboardBlurBehavior="restore"
        topInset={insets.top}
        backdropComponent={renderBackdrop}
        onChange={index => reportSheetState('save', index >= 0)}
        onDismiss={() => reportSheetState('save', false)}
        backgroundStyle={styles.sheetBackground}
        handleIndicatorStyle={styles.sheetIndicator}>
        <BottomSheetScrollView
          accessibilityViewIsModal
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
          contentContainerStyle={styles.saveSheetContent}>
          <Text style={styles.sheetEyebrow}>المحفوظات</Text>
          <Text accessibilityRole="header" style={styles.saveSheetTitle}>
            أين تريد حفظ المقطع
          </Text>
          {foldersLoading ? (
            <ActivityIndicator color="#76A9FF" style={styles.folderLoader} />
          ) : (
            <View style={styles.folderList}>
              {folders.map(folder => (
                <Pressable
                  accessibilityRole="button"
                  key={folder.id}
                  accessibilityState={{disabled: savePending}}
                  disabled={savePending}
                  onPress={() => saveInFolder(folder)}
                  style={({pressed}) => [
                    styles.folderRow,
                    pressed && styles.pressed,
                  ]}>
                  <Text style={styles.folderRowText}>{folder.name}</Text>
                  <Text style={styles.folderRowAction}>
                    {isSaved ? 'إضافة' : 'حفظ'}
                  </Text>
                </Pressable>
              ))}
            </View>
          )}
          {!!folderError && (
            <Text accessibilityRole="alert" style={styles.folderError}>
              {folderError}
            </Text>
          )}
          <View style={styles.newFolderRow}>
            <TextInput
              accessibilityLabel="اسم القائمة الجديدة"
              maxLength={60}
              onChangeText={setNewFolderName}
              onSubmitEditing={() => void createAndSave()}
              placeholder="اسم قائمة جديدة"
              placeholderTextColor="rgba(255,255,255,.38)"
              returnKeyType="done"
              style={styles.folderInput}
              value={newFolderName}
            />
            <Pressable
              accessibilityRole="button"
              accessibilityState={{
                busy: folderBusy,
                disabled: savePending || !newFolderName.trim() || folderBusy,
              }}
              disabled={savePending || !newFolderName.trim() || folderBusy}
              onPress={() => void createAndSave()}
              style={({pressed}) => [
                styles.createFolderButton,
                (!newFolderName.trim() || folderBusy) &&
                  styles.createFolderButtonDisabled,
                pressed && styles.pressed,
              ]}>
              {folderBusy ? (
                <ActivityIndicator color="#FFFFFF" size="small" />
              ) : (
                <Text style={styles.createFolderButtonText}>إنشاء وحفظ</Text>
              )}
            </Pressable>
          </View>
          {isSaved && (
            <Pressable
              accessibilityRole="button"
              accessibilityState={{disabled: savePending}}
              disabled={savePending}
              onPress={() => {
                onToggleSave(null);
                saveSheetRef.current?.dismiss();
              }}
              style={({pressed}) => [
                styles.removeSaveButton,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.removeSaveText}>إزالة من المحفوظات</Text>
            </Pressable>
          )}
        </BottomSheetScrollView>
      </BottomSheetModal>
    </>
  );
};

export default FeedSideBar;
