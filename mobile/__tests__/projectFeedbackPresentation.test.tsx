import React from 'react';
import Clipboard from '@react-native-clipboard/clipboard';
import TestRenderer, {act} from 'react-test-renderer';
import {Alert, StyleSheet, Text, TextInput} from 'react-native';
import ProjectFeedbackPanel from '../src/components/VideoPlayer/projectTransition/ProjectFeedbackPanel';
import {CopyButton} from '../src/components/ui/CopyButton';
import {cleanUnicodeText} from '../src/utils/unicodeText';

jest.mock('@react-native-clipboard/clipboard', () => ({
  setString: jest.fn(),
}));
jest.mock('react-native-svg', () => ({
  __esModule: true,
  default: 'Svg',
  Path: 'Path',
  Rect: 'Rect',
}));

const mockOpenAttachment = jest.fn(async (..._args: unknown[]) => undefined);
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  openProjectInputAttachment: (...args: unknown[]) =>
    mockOpenAttachment(...args),
}));

type Props = React.ComponentProps<typeof ProjectFeedbackPanel>;
const reportText = 'التوزيع واضح\n\nجرّب تعديل التباين في Grease Pencil 4';
const report: Props['thread']['messages'][number] = {
  id: 'report-1',
  role: 'assistant',
  status: 'completed',
  text: reportText,
};
const file: Props['attachments'][number] = {
  uploadId: 'upload-1',
  serverId: 'attachment-1',
  name: 'design 4.png',
  type: 'image/png',
  uri: 'file:///design.png',
};
const props = (overrides: Partial<Props> = {}): Props => ({
  attachments: [],
  canReply: true,
  draft: '',
  error: '',
  feedbackLevel: 'enhanced',
  normalizedDraft: '',
  pending: false,
  projectId: '7',
  sending: false,
  thread: {
    id: 'thread-1',
    feedbackLevel: 'enhanced',
    canReply: true,
    status: 'ready',
    remainingMessages: 3,
    messages: [report],
    attachmentsEnabled: true,
    attachmentMaxFiles: 2,
  },
  onChangeDraft: jest.fn(),
  onPickAttachments: jest.fn(),
  onRemoveAttachment: jest.fn(),
  onRetryMessage: jest.fn(),
  onSend: jest.fn(),
  ...overrides,
});
const texts = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root
    .findAllByType(Text)
    .map(node => cleanUnicodeText(node.props.children));
const actions = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root.findAll(
    node =>
      node.props.accessibilityRole === 'button' &&
      typeof node.props.onPress === 'function',
  );
const button = (renderer: TestRenderer.ReactTestRenderer, label: string) =>
  actions(renderer).find(node => node.props.accessibilityLabel === label)!;

describe('project feedback report and conversation presentation', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const render = (value: Props) =>
    act(() => {
      renderer = TestRenderer.create(<ProjectFeedbackPanel {...value} />);
    });
  afterEach(() => {
    if (renderer) act(() => renderer.unmount());
    jest.restoreAllMocks();
    mockOpenAttachment.mockClear();
    jest.mocked(Clipboard.setString).mockClear();
  });

  it('renders the report first at full width with authored paragraphs and readable type', () => {
    render(props());
    const rendered = texts(renderer);
    expect(rendered).toContain('تقرير المشروع');
    expect(rendered).toContain(reportText);
    expect(rendered.indexOf(reportText)).toBeLessThan(
      rendered.indexOf('استفسارات عن التقرير'),
    );
    expect(rendered).not.toContain('متصل الآن');
    const body = renderer.root
      .findAllByType(Text)
      .find(node => cleanUnicodeText(node.props.children) === reportText)!;
    expect(StyleSheet.flatten(body.props.style)).toMatchObject({
      fontSize: 15,
      lineHeight: 24,
      writingDirection: 'rtl',
    });
    expect(body.props.numberOfLines).toBeUndefined();
    expect(body.props.selectable).toBe(false);
    const reportContainer = StyleSheet.flatten(body.parent!.props.style);
    expect(reportContainer.alignSelf).toBe('stretch');
    expect(reportContainer.maxWidth).toBeUndefined();
    expect(reportContainer.backgroundColor).toBeUndefined();
    // The composer has a separate full-width text row rather than squeezing
    // text between two fixed buttons on 320dp screens or at larger font scales.
    const input = renderer.root.findByType(TextInput);
    expect(StyleSheet.flatten(input.props.style).width).toBe('100%');
    expect(actions(renderer).length).toBeGreaterThan(1);
    for (const action of actions(renderer)) {
      const actionStyle =
        typeof action.props.style === 'function'
          ? action.props.style({pressed: false})
          : action.props.style;
      expect(StyleSheet.flatten(actionStyle).minHeight).toBeGreaterThanOrEqual(
        48,
      );
    }
  });

  it('copies completed report and follow-up content without display controls or native text selection', () => {
    const inquiry = 'value = ٣ + 2;\n\nهل أغيّر Grease Pencil 4؟';
    const answer = 'غيّر value فقط\n\nاحتفظ بالإصدار 4';
    const base = props();
    base.thread = {
      ...base.thread,
      messages: [
        report,
        {id: 'user-copy', role: 'user', status: 'failed', text: inquiry},
        {
          id: 'answer-copy',
          role: 'assistant',
          status: 'completed',
          text: answer,
        },
      ],
    };
    render(base);

    const copyActions = renderer.root.findAllByType(CopyButton);
    expect(copyActions.map(action => action.props.value)).toEqual([
      reportText,
      inquiry,
      answer,
    ]);
    expect(copyActions.map(action => action.props.accessibilityLabel)).toEqual([
      'نسخ تقرير المشروع',
      'نسخ الرسالة',
      'نسخ الرسالة',
    ]);
    for (const copyAction of copyActions) {
      const action = copyAction.findAll(
        node =>
          typeof node.props.onPress === 'function' &&
          node.props.accessibilityRole === 'button',
      )[0];
      act(() => action.props.onPress());
    }
    expect(jest.mocked(Clipboard.setString).mock.calls).toEqual([
      [reportText],
      [inquiry],
      [answer],
    ]);
    expect(texts(renderer)).not.toContain('نسخ');
    for (const text of renderer.root.findAllByType(Text)) {
      expect(text.props.selectable).not.toBe(true);
    }
  });

  it.each(['queued', 'sent', 'streaming', 'cancelled'] as const)(
    'does not offer copy for an assistant %s placeholder or unfinished report',
    status => {
      const base = props({pending: true, error: 'تعذّر الاتصال'});
      base.thread = {
        ...base.thread,
        messages: [{...report, status, text: 'تقرير لم يكتمل بعد'}],
      };
      render(base);
      expect(renderer.root.findAllByType(CopyButton)).toHaveLength(0);
    },
  );

  it('keeps failure guidance without a copy action when no report body was received', () => {
    const base = props({error: 'تعذّر الاتصال'});
    base.thread = {
      ...base.thread,
      messages: [
        {...report, status: 'failed', text: '', errorCode: 'provider_timeout'},
      ],
    };
    render(base);
    expect(texts(renderer)).toContain('تعذّر الرد الآن\nأرسل رسالتك مرة أخرى');
    expect(texts(renderer)).toContain('تعذّر الاتصال');
    expect(renderer.root.findAllByType(CopyButton)).toHaveLength(0);
  });

  it('keeps report-only inquiry explanatory and never mounts an inactive composer', () => {
    const base = props({canReply: false, feedbackLevel: 'report'});
    base.thread = {...base.thread, feedbackLevel: 'report', canReply: false};
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    render(base);
    expect(renderer.root.findAllByType(TextInput)).toHaveLength(0);
    expect(texts(renderer)).toContain(
      'فئتك تشمل التقرير فقط والردود متاحة في فئة المتابعة',
    );
    const gate = button(renderer, 'اعرف فئة الرد على التقرير');
    expect(gate.props.disabled).not.toBe(true);
    act(() => gate.props.onPress());
    expect(alert).toHaveBeenCalledWith(
      'الرد غير مشمول',
      'الردود متاحة في فئة المتابعة',
    );
    expect(base.onSend).not.toHaveBeenCalled();
  });

  it('shows truthful preparation while a sent inquiry has no response then preserves streamed and failed text', () => {
    const base = props({pending: true});
    const user: typeof report = {
      id: 'user-1',
      role: 'user',
      status: 'sent',
      text: 'أعدل التباين إزاي؟',
    };
    base.thread = {...base.thread, messages: [report, user]};
    render(base);
    expect(texts(renderer)).toContain('جارٍ تجهيز الرد');
    expect(renderer.root.findAllByType(TextInput)).toHaveLength(0);
    const partial = 'افتح إعدادات اللون\n\nقلل السطوع';
    const response: typeof report = {
      id: 'reply-1',
      role: 'assistant',
      status: 'streaming',
      text: partial,
    };
    act(() =>
      renderer.update(
        <ProjectFeedbackPanel
          {...base}
          thread={{...base.thread, messages: [report, user, response]}}
        />,
      ),
    );
    expect(texts(renderer)).toContain(partial);
    expect(texts(renderer)).toContain('يكتب الآن');
    expect(texts(renderer)).not.toContain('جارٍ تجهيز الرد');
    expect(
      renderer.root.findAllByType(CopyButton).map(action => action.props.value),
    ).not.toContain(partial);
    act(() =>
      renderer.update(
        <ProjectFeedbackPanel
          {...base}
          pending={false}
          thread={{
            ...base.thread,
            messages: [
              report,
              {...user, status: 'completed'},
              {...response, status: 'failed', canRetry: false},
            ],
          }}
        />,
      ),
    );
    expect(texts(renderer)).toContain(partial);
    expect(texts(renderer)).toContain('لم يكتمل الرد');
    expect(
      renderer.root.findAllByType(CopyButton).map(action => action.props.value),
    ).toContain(partial);
  });

  it.each(['queued', 'sent'] as const)(
    'does not leave an empty %s report without a preparing indicator',
    status => {
      const base = props({pending: true, canReply: false});
      base.thread = {...base.thread, messages: [{...report, text: '', status}]};
      render(base);
      expect(texts(renderer)).toContain('جارٍ تجهيز التقرير');
      expect(texts(renderer)).not.toContain('متصل الآن');
      expect(renderer.root.findAllByType(TextInput)).toHaveLength(0);
    },
  );

  it('preserves attachment ownership arguments, retry action, draft removal and send permission', async () => {
    const failed: typeof report = {
      id: 'user-1',
      role: 'user',
      status: 'failed',
      text: 'محاولتي',
      canRetry: true,
      attachments: [file],
    };
    const base = props({attachments: [file], normalizedDraft: ''});
    base.thread = {...base.thread, messages: [report, failed]};
    render(base);
    await act(async () => button(renderer, `فتح ${file.name}`).props.onPress());
    expect(mockOpenAttachment).toHaveBeenCalledWith({
      projectId: '7',
      threadId: 'thread-1',
      file,
    });
    const retry = actions(renderer).find(node =>
      node
        .findAllByType(Text)
        .some(text => text.props.children === 'إرسال مرة أخرى'),
    )!;
    act(() => retry.props.onPress());
    expect(base.onRetryMessage).toHaveBeenCalledWith(failed);
    act(() => button(renderer, `إزالة ${file.name}`).props.onPress());
    expect(base.onRemoveAttachment).toHaveBeenCalledWith(file);
    expect(button(renderer, 'إرسال الاستفسار').props.disabled).toBe(false);
    act(() => button(renderer, 'إرسال الاستفسار').props.onPress());
    expect(base.onSend).toHaveBeenCalledTimes(1);
    act(() => renderer.update(<ProjectFeedbackPanel {...base} sending />));
    expect(button(renderer, 'إرسال الاستفسار').props.disabled).toBe(true);
    expect(button(renderer, `إزالة ${file.name}`).props.disabled).toBe(true);
    expect(button(renderer, 'إضافة مرفق').props.disabled).toBe(true);
  });

  it('keeps quota, attachment caps, fresh-file retry guidance and error copy', () => {
    const base = props({attachments: [file], error: 'تعذّر الاتصال'});
    base.thread = {
      ...base.thread,
      attachmentMaxFiles: 1,
      messages: [
        report,
        {
          id: 'user-1',
          role: 'user',
          status: 'failed',
          canRetry: true,
          attachments: [{...file, serverId: undefined}],
        },
      ],
    };
    render(base);
    expect(button(renderer, 'إضافة مرفق').props.disabled).toBe(true);
    expect(texts(renderer)).toContain('أضف الملف مرة أخرى ثم أرسل الرسالة');
    expect(texts(renderer)).toContain('تعذّر الاتصال');
    act(() =>
      renderer.update(
        <ProjectFeedbackPanel
          {...base}
          thread={{...base.thread, remainingMessages: 0}}
        />,
      ),
    );
    expect(renderer.root.findAllByType(TextInput)).toHaveLength(0);
    expect(texts(renderer)).toContain('اكتملت رسائل الفئة');
  });
});
