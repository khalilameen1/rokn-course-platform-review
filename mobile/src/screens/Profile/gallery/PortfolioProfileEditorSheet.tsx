import React from 'react';
import {Modal, ScrollView, Text, View} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';

import Button from '../../../components/touchables/Button';
import {StatusView} from '../../../components/ui/PremiumUI';
import {Spacing} from '../../../constants/designSystem';
import {useReducedMotion} from '../../../hooks/useReducedMotion';
import type {usePortfolioProfileEditor} from './usePortfolioProfileEditor';
import {galleryStyles as styles} from './galleryStyles';
import {PortfolioChipEditor} from './PortfolioChipEditor';

type ProfileEditorController = ReturnType<typeof usePortfolioProfileEditor>;

/** Same bottom sheet as the project editor, for profile skills. */
export const PortfolioProfileEditorSheet = ({
  controller,
}: {
  controller: ProfileEditorController;
}) => {
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const {
    addSkill,
    closeProfileEditor,
    profileEditor: editor,
    removeSkill,
    saveProfile,
    setSkillInput,
  } = controller;
  const busy = editor.loading || editor.saving;
  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'slide'}
      onRequestClose={closeProfileEditor}
      statusBarTranslucent
      transparent
      visible={editor.open}>
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
                  paddingBottom: Math.max(Spacing.xl, insets.bottom + Spacing.md),
                  paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
                  paddingRight: Math.max(Spacing.xl, insets.right + Spacing.md),
                },
              ]}>
              <Text style={styles.detailTitle}>مهاراتك</Text>
              {editor.loading ? (
                <StatusView state="loading" title="بنحمّل بياناتك" />
              ) : (
                <>
                  <PortfolioChipEditor
                    addLabel="ضيف مهارة"
                    chips={editor.skills}
                    disabled={busy}
                    input={editor.skillInput}
                    label="المهارات"
                    onAdd={addSkill}
                    onChangeInput={setSkillInput}
                    onRemove={removeSkill}
                    placeholder="تصميم هوية"
                  />
                </>
              )}
              {!!editor.error && (
                <Text accessibilityRole="alert" style={styles.draftError}>
                  {editor.error}
                </Text>
              )}
              <Button
                disable={busy}
                loader={editor.saving}
                onPress={() => void saveProfile()}
                title="حفظ"
              />
              <Button
                disable={editor.saving}
                onPress={closeProfileEditor}
                title="إلغاء"
                useGradient={false}
              />
            </View>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};
