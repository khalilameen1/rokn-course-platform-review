import React from 'react';
import {Modal, Pressable, ScrollView, Text, TextInput, View} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';

import Button from '../../../components/touchables/Button';
import {StatusView} from '../../../components/ui/PremiumUI';
import {Palette, Spacing} from '../../../constants/designSystem';
import {useReducedMotion} from '../../../hooks/useReducedMotion';
import type {usePortfolioProfileEditor} from './usePortfolioProfileEditor';
import {galleryStyles as styles} from './galleryStyles';
import {PortfolioChipEditor} from './PortfolioChipEditor';

type ProfileEditorController = ReturnType<typeof usePortfolioProfileEditor>;

/** Same bottom sheet as the project editor: skills, then contact buttons. */
export const PortfolioProfileEditorSheet = ({
  controller,
}: {
  controller: ProfileEditorController;
}) => {
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const {
    addLink,
    addSkill,
    addWhatsApp,
    closeProfileEditor,
    profileEditor: editor,
    removeLink,
    removeSkill,
    saveProfile,
    setSkillInput,
    setWhatsAppInput,
    updateLink,
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
              <Text style={styles.detailTitle}>مهاراتك وروابطك</Text>
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
                  <Text style={styles.fieldLabel}>رقم واتساب</Text>
                  <View style={styles.chipInputRow}>
                    <TextInput
                      accessibilityLabel="رقم واتساب"
                      editable={!busy}
                      keyboardType="phone-pad"
                      onChangeText={setWhatsAppInput}
                      onSubmitEditing={addWhatsApp}
                      placeholder="01012345678"
                      placeholderTextColor={Palette.textFaint}
                      style={[styles.input, styles.chipInput]}
                      value={editor.whatsAppInput}
                    />
                    <Pressable
                      accessibilityLabel="ضيف زرار واتساب"
                      accessibilityRole="button"
                      disabled={busy || !editor.whatsAppInput.trim()}
                      onPress={addWhatsApp}
                      style={({pressed}) => [
                        styles.chipAddButton,
                        (busy || !editor.whatsAppInput.trim()) &&
                          styles.detailActionDisabled,
                        pressed && styles.detailActionPressed,
                      ]}>
                      <Text style={styles.detailSecondaryLabel}>ضيف</Text>
                    </Pressable>
                  </View>
                  <Text style={styles.fieldLabel}>روابط التواصل</Text>
                  {editor.links.map((link, index) => (
                    <View key={index} style={styles.linkRow}>
                      <TextInput
                        accessibilityLabel="اسم الرابط"
                        editable={!busy}
                        maxLength={40}
                        onChangeText={label => updateLink(index, {label})}
                        placeholder="Behance"
                        placeholderTextColor={Palette.textFaint}
                        style={styles.input}
                        value={link.label}
                      />
                      <TextInput
                        accessibilityLabel="الرابط"
                        autoCapitalize="none"
                        autoCorrect={false}
                        editable={!busy}
                        keyboardType="url"
                        onChangeText={url => updateLink(index, {url})}
                        placeholder="https://"
                        placeholderTextColor={Palette.textFaint}
                        style={styles.input}
                        value={link.url}
                      />
                      <Pressable
                        accessibilityLabel={`شيل ${link.label || 'الرابط'}`}
                        accessibilityRole="button"
                        disabled={busy}
                        onPress={() => removeLink(index)}
                        style={styles.manualEntryButton}>
                        <Text style={styles.mediaDeleteText}>شيل الرابط</Text>
                      </Pressable>
                    </View>
                  ))}
                  {!editor.maxLinksReached && (
                    <Pressable
                      accessibilityRole="button"
                      disabled={busy}
                      onPress={addLink}
                      style={styles.manualEntryButton}>
                      <Text style={styles.manualEntryLabel}>ضيف رابط</Text>
                    </Pressable>
                  )}
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
