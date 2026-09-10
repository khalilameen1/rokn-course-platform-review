import React, {useEffect, useState} from 'react';
import {Pressable, Text, View} from 'react-native';
import {useRoute} from '@react-navigation/native';
import {useTranslation} from 'react-i18next';
import {useSelector} from 'react-redux';

import {Container, Content} from '../components/containers/Containers';
import {ResponsiveFrame, StatusView} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {sessionIdentityKey} from '../constants/helpers';
import type {RootRoute} from '../navigation/types';
import type {RootState} from '../store/store';
import {FeedbackConversation} from './feedback/FeedbackConversation';
import {FeedbackForm} from './feedback/FeedbackForm';
import {styles} from './feedback/styles';
import {useFeedbackCases} from './feedback/useFeedbackCases';
import {useFeedbackComposer} from './feedback/useFeedbackComposer';

export default function Feedback() {
  const route = useRoute<RootRoute<'Feedback'>>();
  const {i18n} = useTranslation();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);
  const requestedCaseId = route.params?.caseId?.trim().toUpperCase() || '';
  const [showComposer, setShowComposer] = useState(false);
  useEffect(() => {
    if (requestedCaseId) setShowComposer(false);
  }, [requestedCaseId]);
  const composer = useFeedbackComposer({
    identityKey,
    locale: i18n.resolvedLanguage || i18n.language || 'ar',
    sourceScreen: route.params?.sourceScreen || 'feedback',
  });
  const cases = useFeedbackCases(identityKey, requestedCaseId);

  if (composer.sent) {
    return (
      <Container noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            <HeaderWithBack title="تواصل معنا" />
            <StatusView
              actionLabel="فتح المتابعة"
              description="وصلتنا رسالتك\nيمكنك متابعة الرد من هنا"
              onAction={() => {
                setShowComposer(false);
                composer.dismissReceipt();
                void cases.reloadCases(
                  composer.receiptPublicId,
                  composer.receipt,
                );
              }}
              title="تم الإرسال"
            />
            {!!composer.receiptId && (
              <Text style={styles.receipt}>
                رقم المتابعة {composer.receiptId}
              </Text>
            )}
          </ResponsiveFrame>
        </Content>
      </Container>
    );
  }

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame style={styles.frame}>
          <HeaderWithBack title="تواصل معنا" />
          <View accessibilityRole="tablist" style={styles.modeTabs}>
            {[
              {compose: false, label: 'طلبات الدعم'},
              {compose: true, label: 'رسالة جديدة'},
            ].map(mode => (
              <Pressable
                accessibilityRole="tab"
                accessibilityState={{selected: showComposer === mode.compose}}
                key={mode.label}
                onPress={() => setShowComposer(mode.compose)}
                style={[
                  styles.modeButton,
                  showComposer === mode.compose && styles.modeButtonSelected,
                ]}>
                <Text style={styles.modeButtonText}>{mode.label}</Text>
              </Pressable>
            ))}
          </View>
          {!showComposer && (
            <FeedbackConversation
              cases={cases.supportCases}
              casesBusy={cases.casesBusy}
              casesError={cases.casesError}
              onArtifactLoadError={cases.markArtifactLoadFailed}
              onChooseReplyAttachment={() => void cases.chooseReplyScreenshot()}
              onCloseArtifact={cases.closeArtifact}
              onOpenArtifact={(artifact, forceRefresh) =>
                void cases.openArtifact(artifact, forceRefresh)
              }
              onRefresh={() =>
                void cases.reloadCases(
                  '',
                  composer.trackingRecoveryNeeded
                    ? composer.receipt
                    : undefined,
                )
              }
              onRemoveReplyAttachment={cases.removeReplyScreenshot}
              onReplyChange={cases.setReply}
              onSelectCase={cases.selectCase}
              onSendReply={() => void cases.sendReply()}
              previewArtifact={cases.previewArtifact}
              previewLoadFailed={cases.previewLoadFailed}
              replyAttachment={cases.replyAttachment}
              replyBusy={cases.replyBusy}
              replyError={cases.replyError}
              replyReady={cases.replyReady}
              replyRestoreError={cases.replyRestoreError}
              onRetryReplyRestore={cases.retryReplyRestore}
              replyMessage={cases.replyMessage}
              selectedCase={cases.selectedCase}
              selectedCaseId={cases.selectedCaseId}
            />
          )}
          {showComposer &&
            (composer.trackingRecoveryNeeded ? (
              <StatusView
                title="رسالتك وصلت"
                description="تعذّر حفظ رقم المتابعة على الجهاز\nاحفظ المتابعة قبل إرسال رسالة جديدة"
                actionLabel={composer.busy ? 'جارٍ الحفظ' : 'إعادة المحاولة'}
                onAction={() => void composer.retryTracking()}
              />
            ) : (
              <FeedbackForm
                attachment={composer.attachment}
                busy={composer.busy}
                canSubmit={composer.canSubmit}
                category={composer.category}
                draftSaveError={composer.draftSaveError}
                draftRestoreError={composer.draftRestoreError}
                onRetryRestore={composer.retryDraftRestore}
                error={composer.error}
                includeDiagnostics={composer.includeDiagnostics}
                message={composer.message}
                onChooseAttachment={() => void composer.chooseScreenshot()}
                onMessageChange={composer.setMessage}
                onRemoveAttachment={composer.removeScreenshot}
                onSelectCategory={composer.selectCategory}
                onToggleDiagnostics={composer.setIncludeDiagnostics}
                onSubmit={() => void composer.submit()}
                ready={composer.ready}
              />
            ))}
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}
