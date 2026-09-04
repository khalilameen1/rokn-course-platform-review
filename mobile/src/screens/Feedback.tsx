import React from 'react';
import {Text} from 'react-native';
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
                composer.dismissReceipt();
                cases.selectCase(composer.receiptPublicId);
                void cases.reloadCases(composer.receiptPublicId);
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
            onRefresh={() => void cases.reloadCases()}
            onRemoveReplyAttachment={cases.removeReplyScreenshot}
            onReplyChange={cases.setReply}
            onSelectCase={cases.selectCase}
            onSendReply={() => void cases.sendReply()}
            previewArtifact={cases.previewArtifact}
            previewLoadFailed={cases.previewLoadFailed}
            replyAttachment={cases.replyAttachment}
            replyBusy={cases.replyBusy}
            replyError={cases.replyError}
            replyMessage={cases.replyMessage}
            selectedCase={cases.selectedCase}
            selectedCaseId={cases.selectedCaseId}
          />
          <FeedbackForm
            attachment={composer.attachment}
            busy={composer.busy}
            canSubmit={composer.canSubmit}
            category={composer.category}
            draftSaveError={composer.draftSaveError}
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
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}
