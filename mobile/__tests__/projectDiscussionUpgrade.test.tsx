import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Text, TextInput} from 'react-native';

const mockQuote = jest.fn();
const mockRead = jest.fn();
let mockForeground = true;
jest.mock('../src/services/roknApi', () => ({
  getFullTrackUpgradeQuote: (...args: unknown[]) => mockQuote(...args),
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => mockForeground,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  openProjectInputAttachment: jest.fn(),
  loadProjectFeedbackThread: (...args: unknown[]) => mockRead(...args),
}));
jest.mock('../src/components/ui/CopyButton', () => ({CopyButton: () => null}));
jest.mock('../src/components/ui/AiResponseReportButton', () => ({
  AiResponseReportButton: () => null,
}));
jest.mock('react-native-svg', () => ({__esModule: true, default: 'Svg', Path: 'Path'}));

import ProjectFeedbackPanel from '../src/components/VideoPlayer/projectTransition/ProjectFeedbackPanel';
import {useCourseUpgradeOffer} from '../src/hooks/useCourseUpgradeOffer';
import {useProjectFeedbackThread} from '../src/components/VideoPlayer/projectTransition/useProjectFeedbackThread';

const available = {upgradeAvailable: true, availablePlanCodes: ['mentor'], alreadyUpgraded: false};
const unavailable = {upgradeAvailable: false, availablePlanCodes: [], alreadyUpgraded: false};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(finish => {resolve = finish;});
  return {promise, resolve};
};
type Props = React.ComponentProps<typeof ProjectFeedbackPanel>;
const panelProps = (): Props => ({
  active: true, courseId: '3', projectId: '7', attachments: [], canReply: false,
  draft: 'سؤالي محفوظ', normalizedDraft: 'سؤالي محفوظ', error: '',
  feedbackLevel: 'enhanced', pending: false, sending: false,
  thread: {
    id: 'thread-7', feedbackLevel: 'enhanced', canReply: true, status: 'ready',
    transcriptIncluded: true, remainingMessages: 0, replyLimitReached: true,
    messages: [{id: 'report', role: 'assistant', status: 'completed', text: 'تقريرك'}],
  },
  onChangeDraft: jest.fn(), onPickAttachments: jest.fn(), onRemoveAttachment: jest.fn(),
  onRetryMessage: jest.fn(), onSend: jest.fn(), onRequestDiscussionUpgrade: jest.fn(),
});

describe('optional exhausted project discussion', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const texts = () => renderer!.root.findAllByType(Text).map(node => node.props.children);
  const buttons = (label: string) => renderer!.root.findAll(node =>
    node.props.accessibilityLabel === label && typeof node.props.onPress === 'function');
  const press = async (label: string) => {
    await act(async () => {buttons(label)[0].props.onPress();});
  };
  const mount = async (props = panelProps()) => {
    await act(async () => {renderer = TestRenderer.create(<ProjectFeedbackPanel {...props} />);});
    return props;
  };
  beforeEach(() => {
    mockQuote.mockReset().mockResolvedValue(available);
    mockRead.mockReset();
    mockForeground = true;
  });
  afterEach(async () => {if (renderer) await act(async () => renderer!.unmount());});

  it('reads an offer only after opening discussion and opens purchase only after its CTA', async () => {
    const props = await mount();
    expect(mockQuote).not.toHaveBeenCalled();
    await press('هل لديك سؤال؟');
    expect(mockQuote).toHaveBeenCalledWith('3', {requiredFeature: 'project_discussion'});
    expect(props.onRequestDiscussionUpgrade).not.toHaveBeenCalled();
    expect(renderer!.root.findAllByType(TextInput)).toHaveLength(0);
    await press('قم بترقية الاشتراك');
    expect(props.onRequestDiscussionUpgrade).toHaveBeenCalledWith(true);
    expect(props.onSend).not.toHaveBeenCalled();
  });

  it('does not sell the highest plan again', async () => {
    mockQuote.mockResolvedValue({...unavailable, alreadyUpgraded: true});
    await mount();
    await press('هل لديك سؤال؟');
    expect(buttons('قم بترقية الاشتراك')).toHaveLength(0);
    expect(texts()).toContain('لا يوجد اشتراك أعلى يتيح مناقشة إضافية لهذا الكورس');
  });

  it('uses the server token/cost exhaustion verdict even with messages remaining', async () => {
    const props = panelProps();
    props.thread.remainingMessages = 4;
    props.canReply = true; // The panel is also defensive against a stale controller.
    await mount(props);
    await press('هل لديك سؤال؟');
    expect(renderer!.root.findAllByType(TextInput)).toHaveLength(0);
    expect(buttons('قم بترقية الاشتراك').length).toBeGreaterThan(0);
    expect(texts()).not.toContain('المناقشة غير متاحة الآن');
  });

  it.each(['pending', 'restore', 'read', 'hydrating', 'failed', 'summary', 'revoked'] as const)(
    'does not turn %s into a purchasable exhaustion state', async reason => {
      const props = panelProps();
      if (reason === 'pending') props.pending = true;
      if (reason === 'restore') props.draftRestoreError = true;
      if (reason === 'read') props.readError = 'تعذّر تحديث الرد';
      if (reason === 'hydrating') props.readHydrating = true;
      if (reason === 'failed') props.thread.status = 'failed';
      if (reason === 'summary') props.thread.transcriptIncluded = false;
      if (reason === 'revoked') props.thread.canReply = false;
      await mount(props);
      await press('هل لديك سؤال؟');
      expect(mockQuote).not.toHaveBeenCalled();
      expect(buttons('قم بترقية الاشتراك')).toHaveLength(0);
    });

  it('does not offer a cached exhausted quota while a return GET can replenish it', async () => {
    const props = panelProps();
    props.readHydrating = true;
    await mount(props);
    await press('هل لديك سؤال؟');
    expect(mockQuote).not.toHaveBeenCalled();
    expect(texts()).toContain('جارٍ تحديث المناقشة');
    await act(async () => {
      renderer!.update(<ProjectFeedbackPanel {...props} readHydrating={false}
        canReply thread={{...props.thread, remainingMessages: 8, replyLimitReached: false}} />);
    });
    expect(mockQuote).not.toHaveBeenCalled();
    expect(renderer!.root.findByType(TextInput).props.value).toBe('سؤالي محفوظ');
  });

  it('blocks the first return commit using the actual thread GET owner before passive effects settle', async () => {
    const props = panelProps();
    const resumed = deferred<Props['thread']>();
    mockRead.mockReturnValueOnce(resumed.promise);
    const Harness = ({active = true}: {active?: boolean}) => {
      const read = useProjectFeedbackThread({
        projectId: '7', seedThread: props.thread, active, appIsActive: true,
        feedbackLevel: 'enhanced', replyEnabled: true, reportStatus: 'ready',
      });
      return <ProjectFeedbackPanel {...props} active={active}
        readHydrating={read.hydrating} thread={read.thread!}
        canReply={read.thread?.canReply === true && !read.hydrating} />;
    };
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    await press('هل لديك سؤال؟');
    expect(mockQuote).toHaveBeenCalledTimes(1);
    await act(async () => {renderer!.update(<Harness active={false} />);});
    mockQuote.mockClear();
    await act(async () => {renderer!.update(<Harness />);});
    expect(mockRead).toHaveBeenCalledTimes(1);
    expect(mockQuote).not.toHaveBeenCalled();
    expect(texts()).toContain('جارٍ تحديث المناقشة');
    await act(async () => {
      resumed.resolve({...props.thread, remainingMessages: 8, replyLimitReached: false});
    });
    expect(mockQuote).not.toHaveBeenCalled();
    expect(renderer!.root.findByType(TextInput).props.value).toBe('سؤالي محفوظ');
  });

  it('offers read retry rather than fabricating an upgrade after a network failure', async () => {
    mockQuote.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(available);
    const props = await mount();
    await press('هل لديك سؤال؟');
    expect(buttons('قم بترقية الاشتراك')).toHaveLength(0);
    await press('إعادة المحاولة');
    expect(buttons('قم بترقية الاشتراك').length).toBeGreaterThan(0);
    expect(props.onRequestDiscussionUpgrade).not.toHaveBeenCalled();
  });

  it('retires an old quote on close and reads a fresh one on reopen', async () => {
    const old = deferred<typeof available>();
    mockQuote.mockReturnValueOnce(old.promise).mockResolvedValueOnce(unavailable);
    await mount();
    await press('هل لديك سؤال؟');
    await press('إغلاق المناقشة');
    await press('هل لديك سؤال؟');
    await act(async () => {old.resolve(available);});
    expect(mockQuote).toHaveBeenCalledTimes(2);
    expect(buttons('قم بترقية الاشتراك')).toHaveLength(0);
  });

  it('retains legacy message-count exhaustion but does not read while backgrounded', async () => {
    mockForeground = false;
    const props = panelProps();
    delete props.thread.replyLimitReached;
    await mount(props);
    await press('هل لديك سؤال؟');
    expect(mockQuote).not.toHaveBeenCalled();
    mockForeground = true;
    await act(async () => {renderer!.update(<ProjectFeedbackPanel {...props} />);});
    expect(mockQuote).toHaveBeenCalledTimes(1);
  });
});

describe('shared feature offer contract', () => {
  let current!: ReturnType<typeof useCourseUpgradeOffer>;
  const Harness = ({ownerKey = 'account-a', active = true}: {ownerKey?: string; active?: boolean}) => {
    current = useCourseUpgradeOffer({ownerKey, active, courseId: '3', requiredFeature: 'project_discussion'});
    return null;
  };
  it('rejects a contradictory contract and ignores the old account result', async () => {
    const old = deferred<typeof available>();
    mockQuote.mockReset().mockReturnValueOnce(old.promise).mockResolvedValueOnce({
      ...available, availablePlanCodes: [],
    });
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {renderer = TestRenderer.create(<Harness />);});
      await act(async () => {renderer.update(<Harness ownerKey="account-b" />);});
      await act(async () => {old.resolve(available);});
      expect(current.status).toBe('error');
    } finally {
      if (renderer) await act(async () => renderer.unmount());
    }
  });
});
