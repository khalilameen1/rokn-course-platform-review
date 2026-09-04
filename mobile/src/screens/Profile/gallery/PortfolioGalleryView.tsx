import React from 'react';
import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../../navigation/types';
import {openGuestLogin} from '../../../navigation/journeyNavigation';
import {
  ActivityIndicator,
  Image,
  Modal,
  Pressable,
  ScrollView,
  Text,
  TextInput,
  View,
} from 'react-native';
import Video from 'react-native-video';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {useReducedMotion} from '../../../hooks/useReducedMotion';
import Button from '../../../components/touchables/Button';
import {
  MetaPill,
  SectionHeading,
  StatusView,
} from '../../../components/ui/PremiumUI';
import {
  Palette,
  Spacing,
  useResponsiveLayout,
} from '../../../constants/designSystem';
import {formatArabicDisplayText} from '../../../constants/arabicFormatting';
import type {PortfolioGalleryController} from './usePortfolioGalleryController';
import {galleryStyles as styles} from './galleryStyles';
import {PortfolioProjectGrid} from './PortfolioProjectGrid';

export const PortfolioGalleryView = ({
  controller,
}: {
  controller: PortfolioGalleryController;
}) => {
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const {contentWidth, gutter, gridColumns, gridGap} = useResponsiveLayout();
  const columns = Math.max(1, Math.min(gridColumns, 3));
  const cardWidth =
    (contentWidth - gutter * 2 - gridGap * (columns - 1)) / columns;
  const {
    addProject,
    addSelectedMedia,
    adding,
    appActive,
    beginEdit,
    cancelEditing,
    chooseSourceProject,
    clearSelectedSourceProject,
    closeAddProject,
    closeProject,
    confirmDeleteSelectedProject,
    detailLoading,
    draftCover,
    draftMediaAssets,
    draftSaveError,
    draftSummary,
    draftTitle,
    editSummary,
    editTitle,
    editing,
    eligibleLoading,
    eligibleProjects,
    finalizeSelectedProject,
    handlePreviewPlaybackError,
    loadError,
    loading,
    loadProjects,
    openAddProject,
    openProject,
    onSharePortfolio,
    pickCover,
    previewMedia,
    projects,
    removeSelectedMedia,
    saveProjectEdits,
    saving,
    selectPreviewMedia,
    selected,
    selectedMediaSlots,
    selectedAction,
    selectedSourceProject,
    serverSession,
    setEditSummary,
    setEditTitle,
    updateDraftSummary,
    updateDraftTitle,
    uploadProgress,
  } = controller;
  return (
    <View style={styles.container}>
      <SectionHeading
        actionLabel={
          loading || serverSession === false ? undefined : 'إضافة مشروع'
        }
        onAction={openAddProject}
        title="مشاريعي"
      />
      {loading && !projects.length ? (
        <StatusView state="loading" title="جارٍ تحميل مشروعاتك" />
      ) : loadError && !projects.length ? (
        <StatusView
          actionLabel="إعادة المحاولة"
          description={loadError}
          onAction={loadProjects}
          state="error"
          title="تعذّر تحميل البورتفوليو"
        />
      ) : serverSession === false ? (
        <StatusView
          actionLabel="تسجيل الدخول"
          description="سجّل الدخول لإضافة مشروعاتك ومشاركة البورتفوليو"
          onAction={() =>
            openGuestLogin(navigation, {
              name: 'Profile',
              params: {tab: 'portfolio'},
            })
          }
          state="empty"
          title="بورتفوليوك مرتبط بحسابك"
        />
      ) : !projects.length ? (
        <StatusView
          description="أضف عملًا أو اختر مشروعًا أكملته"
          title="لا توجد مشروعات"
        />
      ) : (
        <>
          {!!loadError && (
            <Text accessibilityRole="alert" style={styles.loadNotice}>
              {loadError}
            </Text>
          )}
          <PortfolioProjectGrid
            cardWidth={cardWidth}
            gap={gridGap}
            onOpen={openProject}
            projects={projects}
          />
        </>
      )}

      <Modal
        animationType={reducedMotion ? 'none' : 'slide'}
        onRequestClose={closeProject}
        statusBarTranslucent
        transparent
        visible={!!selected}>
        <View style={styles.overlay}>
          <View accessibilityViewIsModal style={styles.sheet}>
            <ScrollView
              contentInsetAdjustmentBehavior="automatic"
              showsVerticalScrollIndicator={false}>
              {selected && (
                <>
                  {previewMedia?.type === 'video' && previewMedia.uri ? (
                    <Video
                      controls
                      onError={handlePreviewPlaybackError}
                      paused={!appActive}
                      resizeMode="contain"
                      source={{uri: previewMedia.uri}}
                      style={styles.detailCover}
                    />
                  ) : (
                    <Image
                      accessible={false}
                      importantForAccessibility="no"
                      progressiveRenderingEnabled
                      resizeMethod="resize"
                      source={
                        previewMedia?.type === 'image' && previewMedia.uri
                          ? {uri: previewMedia.uri}
                          : selected.cover
                      }
                      style={styles.detailCover}
                    />
                  )}
                  {detailLoading && (
                    <ActivityIndicator
                      color={Palette.primary}
                      style={styles.detailLoader}
                    />
                  )}
                  {!!selected.media.length && (
                    <ScrollView
                      horizontal
                      contentContainerStyle={styles.mediaStrip}
                      showsHorizontalScrollIndicator={false}>
                      {selected.media.map(media => (
                        <View key={media.id} style={styles.mediaThumbGroup}>
                          <Pressable
                            accessibilityLabel={
                              media.type === 'video'
                                ? 'عرض الفيديو'
                                : 'عرض الصورة'
                            }
                            accessibilityRole="button"
                            onPress={() => selectPreviewMedia(media)}
                            style={[
                              styles.mediaThumb,
                              previewMedia?.id === media.id &&
                                styles.mediaThumbActive,
                            ]}>
                            {media.type === 'image' && media.uri ? (
                              <Image
                                progressiveRenderingEnabled
                                resizeMethod="resize"
                                source={{uri: media.uri}}
                                style={styles.mediaThumbImage}
                              />
                            ) : (
                              <View style={styles.mediaThumbPlaceholder}>
                                <Text style={styles.mediaThumbLabel}>
                                  {media.status === 'processing'
                                    ? 'يُجهز'
                                    : media.type === 'video'
                                    ? 'فيديو'
                                    : 'ملف'}
                                </Text>
                              </View>
                            )}
                          </Pressable>
                          <Pressable
                            accessibilityLabel="حذف الملف"
                            accessibilityRole="button"
                            disabled={saving}
                            onPress={() => removeSelectedMedia(media)}
                            style={styles.mediaDelete}>
                            <Text style={styles.mediaDeleteText}>حذف</Text>
                          </Pressable>
                        </View>
                      ))}
                    </ScrollView>
                  )}
                  <View
                    style={[
                      styles.detailCopy,
                      {
                        paddingBottom: Math.max(
                          Spacing.xl,
                          insets.bottom + Spacing.md,
                        ),
                        paddingLeft: Math.max(
                          Spacing.xl,
                          insets.left + Spacing.md,
                        ),
                        paddingRight: Math.max(
                          Spacing.xl,
                          insets.right + Spacing.md,
                        ),
                      },
                    ]}>
                    <MetaPill
                      label={
                        selected.courseName
                          ? `من كورس ${selected.courseName}`
                          : 'مشروع مهني'
                      }
                      tone="primary"
                    />
                    {editing ? (
                      <>
                        <Text style={styles.fieldLabel}>اسم المشروع</Text>
                        <TextInput
                          accessibilityLabel="اسم المشروع"
                          editable={!saving}
                          onChangeText={setEditTitle}
                          style={styles.input}
                          value={editTitle}
                        />
                        <Text style={styles.fieldLabel}>وصف مختصر</Text>
                        <TextInput
                          accessibilityLabel="وصف المشروع"
                          editable={!saving}
                          multiline
                          onChangeText={setEditSummary}
                          style={[styles.input, styles.multiline]}
                          value={editSummary}
                        />
                      </>
                    ) : (
                      <>
                        <Text style={styles.detailTitle}>
                          {formatArabicDisplayText(selected.title)}
                        </Text>
                        <Text style={styles.detailSummary}>
                          {formatArabicDisplayText(selected.summary)}
                        </Text>
                      </>
                    )}
                    <View style={styles.skillsRow}>
                      {selected.skills.map(skill => (
                        <MetaPill key={skill} label={skill} />
                      ))}
                    </View>
                    {editing ? (
                      <>
                        <Button
                          disable={!editTitle.trim() || saving}
                          loader={saving}
                          onPress={saveProjectEdits}
                          title="حفظ التعديل"
                        />
                        <Button
                          disable={saving}
                          onPress={cancelEditing}
                          title="إلغاء"
                          useGradient={false}
                        />
                      </>
                    ) : (
                      <>
                        <Button
                          disable={saving || selectedMediaSlots === 0}
                          loader={saving}
                          onPress={addSelectedMedia}
                          title="إضافة صور أو فيديو"
                          useGradient={false}
                        />
                        {selectedAction === 'complete' ? (
                          <Button
                            disable={saving}
                            onPress={finalizeSelectedProject}
                            title="إتمام المشروع"
                            useGradient={false}
                          />
                        ) : null}
                        {selectedAction === 'share' && onSharePortfolio ? (
                          <Button
                            disable={saving}
                            onPress={() => void onSharePortfolio()}
                            title="مشاركة البورتفوليو"
                            useGradient={false}
                          />
                        ) : null}
                        <Button
                          disable={saving}
                          onPress={beginEdit}
                          title="تعديل المشروع"
                          useGradient={false}
                        />
                        <Button
                          onPress={closeProject}
                          title="إغلاق"
                          useGradient={false}
                        />
                        <Button
                          disable={saving}
                          loader={saving}
                          onPress={confirmDeleteSelectedProject}
                          title="حذف المشروع"
                          useGradient={false}
                        />
                      </>
                    )}
                  </View>
                </>
              )}
            </ScrollView>
          </View>
        </View>
      </Modal>

      <Modal
        animationType={reducedMotion ? 'none' : 'slide'}
        onRequestClose={closeAddProject}
        statusBarTranslucent
        transparent
        visible={adding}>
        <View style={styles.overlay}>
          <View accessibilityViewIsModal style={styles.sheet}>
            <ScrollView
              automaticallyAdjustKeyboardInsets
              contentInsetAdjustmentBehavior="automatic"
              keyboardDismissMode="interactive"
              keyboardShouldPersistTaps="handled">
              <View
                style={[
                  styles.detailCopy,
                  {
                    paddingBottom: Math.max(
                      Spacing.xl,
                      insets.bottom + Spacing.md,
                    ),
                    paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
                    paddingRight: Math.max(
                      Spacing.xl,
                      insets.right + Spacing.md,
                    ),
                  },
                ]}>
                <Text style={styles.detailTitle}>أضف مشروعًا</Text>
                {serverSession && (
                  <View style={styles.eligibleSection}>
                    <Text style={styles.fieldLabel}>من مشروعاتك المكتملة</Text>
                    {eligibleLoading ? (
                      <ActivityIndicator
                        color={Palette.primary}
                        style={styles.eligibleLoader}
                      />
                    ) : eligibleProjects.length ? (
                      <ScrollView
                        horizontal
                        contentContainerStyle={styles.eligibleList}
                        showsHorizontalScrollIndicator={false}>
                        {eligibleProjects.map(project => {
                          const active =
                            selectedSourceProject?.projectId ===
                            project.projectId;
                          return (
                            <Pressable
                              accessibilityRole="button"
                              disabled={saving}
                              key={project.projectId}
                              onPress={() => chooseSourceProject(project)}
                              style={({pressed}) => [
                                styles.eligibleCard,
                                active && styles.eligibleCardActive,
                                pressed && styles.pressed,
                              ]}>
                              {project.courseImage ? (
                                <Image
                                  progressiveRenderingEnabled
                                  resizeMethod="resize"
                                  source={{uri: project.courseImage}}
                                  style={styles.eligibleImage}
                                />
                              ) : null}
                              <View style={styles.eligibleCopy}>
                                <Text
                                  numberOfLines={1}
                                  style={styles.eligibleCourse}>
                                  {formatArabicDisplayText(project.courseName)}
                                </Text>
                                <Text
                                  numberOfLines={2}
                                  style={styles.eligibleTitle}>
                                  {formatArabicDisplayText(project.title)}
                                </Text>
                              </View>
                            </Pressable>
                          );
                        })}
                      </ScrollView>
                    ) : (
                      <Text style={styles.eligibleEmpty}>
                        أي مشروع تجتازه سيظهر هنا لتضيفه بضغطة
                      </Text>
                    )}
                    {selectedSourceProject && (
                      <Pressable
                        accessibilityRole="button"
                        disabled={saving}
                        onPress={clearSelectedSourceProject}
                        style={styles.manualEntryButton}>
                        <Text style={styles.manualEntryLabel}>
                          إضافة مشروع مستقل بدلًا منه
                        </Text>
                      </Pressable>
                    )}
                  </View>
                )}
                <Text style={styles.fieldLabel}>اسم المشروع</Text>
                <TextInput
                  accessibilityLabel="اسم المشروع"
                  editable={!saving}
                  onChangeText={updateDraftTitle}
                  placeholder="هوية لمقهى محلي"
                  placeholderTextColor={Palette.textFaint}
                  style={styles.input}
                  value={draftTitle}
                />
                <Text style={styles.fieldLabel}>وصف مختصر</Text>
                <TextInput
                  accessibilityLabel="وصف المشروع"
                  editable={!saving}
                  multiline
                  onChangeText={updateDraftSummary}
                  placeholder="المشكلة التي حللتها والنتيجة"
                  placeholderTextColor={Palette.textFaint}
                  style={[styles.input, styles.multiline]}
                  value={draftSummary}
                />
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="اختيار صور وفيديوهات المشروع"
                  disabled={saving}
                  onPress={pickCover}
                  style={styles.coverPicker}>
                  {draftMediaAssets.length ? (
                    <View style={styles.pickedMediaPreview}>
                      {draftCover ? (
                        <Image
                          progressiveRenderingEnabled
                          resizeMethod="resize"
                          source={draftCover}
                          style={styles.pickedCover}
                        />
                      ) : (
                        <View style={styles.pickedCoverFallback}>
                          <Text style={styles.coverPickerLabel}>فيديو</Text>
                        </View>
                      )}
                      <Text style={styles.pickedMediaCount}>
                        {draftMediaAssets.length} ملفات
                      </Text>
                    </View>
                  ) : (
                    <Text style={styles.coverPickerLabel}>
                      إضافة صور أو فيديوهات
                    </Text>
                  )}
                </Pressable>
                <Button
                  disable={
                    !draftTitle.trim() || !draftMediaAssets.length || saving
                  }
                  loader={saving}
                  onPress={addProject}
                  title="إضافة للبورتفوليو"
                />
                <Button
                  disable={saving}
                  onPress={closeAddProject}
                  title="إلغاء"
                  useGradient={false}
                />
                {saving && (
                  <View style={styles.savingIndicator}>
                    <ActivityIndicator color={Palette.primary} />
                    {uploadProgress ? (
                      <Text style={styles.uploadProgressText}>
                        رفع {uploadProgress.completed} من {uploadProgress.total}
                      </Text>
                    ) : null}
                  </View>
                )}
                {draftSaveError && !saving && (
                  <Text accessibilityRole="alert" style={styles.draftError}>
                    لم تُحفظ المسودة على الجهاز
                    {'\n'}يمكنك المتابعة أو تفريغ بعض المساحة
                  </Text>
                )}
              </View>
            </ScrollView>
          </View>
        </View>
      </Modal>
    </View>
  );
};

export default PortfolioGalleryView;
