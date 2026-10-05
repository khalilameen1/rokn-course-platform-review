import React from 'react';
import {Text, TextInput} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {FeedbackForm} from '../src/screens/feedback/FeedbackForm';
import {FeedbackConversation} from '../src/screens/feedback/FeedbackConversation';

jest.mock('react-native-svg', () => ({
  __esModule: true,
  default: 'Svg',
  Path: 'Path',
}));

const image = {uri: 'file:///draft/ready.jpg', type: 'image/jpeg'};

it.each([false, true])(
  'marks new-message preparation separately from sending (existing image %s)',
  existingImage => {
    const props: React.ComponentProps<typeof FeedbackForm> = {
      ready: true,
      busy: false,
      // Even a stale true prop must not enable the preparing form.
      canSubmit: true,
      preparingAttachment: true,
      attachment: existingImage ? image : undefined,
      category: 'problem',
      draftSaveError: false,
      error: '',
      includeDiagnostics: false,
      message: 'صورة توضح المشكلة في التطبيق',
      onChooseAttachment: jest.fn(),
      onMessageChange: jest.fn(),
      onRemoveAttachment: jest.fn(),
      onSelectCategory: jest.fn(),
      onToggleDiagnostics: jest.fn(),
      onSubmit: jest.fn(),
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    act(() => {
      renderer = TestRenderer.create(<FeedbackForm {...props} />);
    });
    try {
      const submit = renderer.root.findByProps({
        accessibilityRole: 'button',
        accessibilityLabel: 'إرسال الملاحظة',
      });
      expect(submit.props.disabled).toBe(true);
      expect(submit.props.accessibilityState).toEqual({
        busy: true,
        disabled: true,
      });
      expect(renderer.root.findByType(TextInput).props.editable).toBe(true);
      const labels = renderer.root
        .findAllByType(Text)
        .map(node => node.props.children);
      expect(labels).toContain('جارٍ تجهيز الصورة');
      expect(labels).not.toContain('جارٍ الإرسال');
      const imageButton = renderer.root.findByProps({
        accessibilityRole: 'button',
        accessibilityLabel: existingImage
          ? 'حذف الصورة المرفقة'
          : 'إضافة صورة توضح المشكلة',
      });
      expect(imageButton.props.disabled).toBe(true);
      act(() => {
        renderer.update(
          <FeedbackForm
            {...props}
            preparingAttachment={false}
            attachment={image}
          />,
        );
      });
      expect(
        renderer.root.findByProps({
          accessibilityRole: 'button',
          accessibilityLabel: 'إرسال الملاحظة',
        }).props.disabled,
      ).toBe(false);
    } finally {
      act(() => renderer.unmount());
    }
  },
);

it.each([false, true])(
  'marks reply preparation separately from sending (existing image %s)',
  existingImage => {
    const selectedCase = {
      publicId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
      caseNumber: 'RKN12345',
      category: 'bug',
      message: 'مشكلة في تشغيل المقطع',
      messages: [],
      attachments: [],
      createdAt: '2026-09-08T12:00:00.000Z',
      updatedAt: '2026-09-08T12:00:00.000Z',
      status: 'received',
    };
    const props: React.ComponentProps<typeof FeedbackConversation> = {
      cases: [selectedCase],
      casesBusy: false,
      casesError: '',
      replyAttachmentBusy: true,
      replyAttachment: existingImage ? image : undefined,
      replyBusy: false,
      replyError: '',
      replyReady: true,
      replyMessage: 'رد مع صورة للمشكلة',
      selectedCase,
      selectedCaseId: selectedCase.publicId,
      previewLoadFailed: false,
      onChooseReplyAttachment: jest.fn(),
      onCloseArtifact: jest.fn(),
      onArtifactLoadError: jest.fn(),
      onOpenArtifact: jest.fn(),
      onRefresh: jest.fn(),
      onRemoveReplyAttachment: jest.fn(),
      onReplyChange: jest.fn(),
      onSelectCase: jest.fn(),
      onSendReply: jest.fn(),
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    act(() => {
      renderer = TestRenderer.create(<FeedbackConversation {...props} />);
    });
    try {
      const submit = renderer.root.findByProps({
        accessibilityRole: 'button',
        onPress: props.onSendReply,
      });
      expect(submit.props.disabled).toBe(true);
      expect(submit.props.accessibilityState).toEqual({
        busy: true,
        disabled: true,
      });
      expect(renderer.root.findByType(TextInput).props.editable).toBe(true);
      const labels = renderer.root
        .findAllByType(Text)
        .map(node => node.props.children);
      expect(labels).toContain('جارٍ تجهيز الصورة');
      expect(labels).not.toContain('جارٍ الإرسال');
      const imageButton = renderer.root.findByProps({
        accessibilityRole: 'button',
        accessibilityLabel: existingImage
          ? 'حذف صورة الرد'
          : 'إضافة صورة إلى الرد',
      });
      expect(imageButton.props.disabled).toBe(true);
      act(() => {
        renderer.update(
          <FeedbackConversation
            {...props}
            replyAttachmentBusy={false}
            replyAttachment={image}
          />,
        );
      });
      expect(
        renderer.root.findByProps({
          accessibilityRole: 'button',
          onPress: props.onSendReply,
        }).props.disabled,
      ).toBe(false);
    } finally {
      act(() => renderer.unmount());
    }
  },
);
