import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  StyleSheet,
  Text,
} from 'react-native';
import type {CourseProject} from '../src/components/VideoPlayer/types';
import {cleanUnicodeText} from '../src/utils/unicodeText';
import FullTrackUpgradeSheet from '../src/components/FullTrackUpgradeSheet';
import {getFullTrackUpgradeQuote} from '../src/services/roknApi';
jest.mock(
  '../src/components/FullTrackUpgradeSheet',
  () => 'FullTrackUpgradeSheet',
);

const mockController = jest.fn();
jest.mock('@react-native-clipboard/clipboard', () => ({
  setString: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/projectTransition/pickers', () => ({
  pickProjectFilesOwned: jest.fn(),
}));
jest.mock('@react-navigation/native', () => ({useNavigation: () => ({})}));
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  goBackOrHome: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  openProjectInputAttachment: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  getFullTrackUpgradeQuote: jest.fn(),
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));
jest.mock(
  '../src/components/VideoPlayer/projectTransition/useProjectTransitionController',
  () => ({
    useProjectTransitionController: () => mockController(),
  }),
);
jest.mock(
  '../src/components/VideoPlayer/projectTransition/ProjectSubmissionEditor',
  () => () => null,
);

import ProjectTransition from '../src/components/VideoPlayer/ProjectTransition';
import ProjectFeedbackPanel from '../src/components/VideoPlayer/projectTransition/ProjectFeedbackPanel';
import {ProjectFeedbackReadRecovery} from '../src/components/VideoPlayer/projectTransition/ProjectFeedbackReadRecovery';
import ProjectSubmissionEditor from '../src/components/VideoPlayer/projectTransition/ProjectSubmissionEditor';

const project: CourseProject = {
  id: '7',
  sectionId: 'section-7',
  moduleId: 'module-1',
  title: 'مشروع ريلز 3',
  requirements: 'اكتب print(3) ثم اختر "ريلز"',
  status: 'passed',
  isGraduationProject: false,
};
const partial = 'توزيع العناصر واضح لكن التباين';

describe('interrupted project report presentation', () => {
  it.each([
    ['failed', partial, true],
    ['failed_retryable', partial, true],
    ['failed', 'const label = "ريلز";\nconst limit = 3;', true],
    ['failed', '', false],
    ['failed_retryable', '', false],
    ['hidden', partial, false],
  ])(
    'renders received report content for %s (%s) only when applicable',
    (reportViewState, content, visible) => {
      mockController.mockReturnValue({
        journeyState: 'passed',
        reportViewState,
        feedbackThread: {
          id: 'thread-7',
          feedbackLevel: 'enhanced',
          canReply: false,
          status: 'failed',
          remainingMessages: 50,
          messages: [
            {
              id: 'report-7',
              role: 'assistant',
              status: 'failed',
              text: content,
              errorCode: 'provider_outcome_unknown',
              canRetry: false,
            },
          ],
        },
        feedbackAttachments: [],
        feedbackDraft: '',
        normalizedFeedbackDraft: '',
        feedbackLevel: 'enhanced',
        feedbackPending: false,
        feedbackSending: false,
        canReplyToFeedback: false,
        feedbackError: '',
      });
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        act(() => {
          renderer = TestRenderer.create(
            <ProjectTransition
              active
              project={project}
              moduleTitle="أساسيات Blender 4"
              width={390}
              height={844}
              onSubmit={jest.fn()}
            />,
          );
        });
        const panels = renderer.root.findAllByType(ProjectFeedbackPanel);
        expect(panels).toHaveLength(visible ? 1 : 0);
        const text = renderer.root
          .findAllByType(Text)
          .map(node => cleanUnicodeText(node.props.children));
        expect(text).not.toContain(project.requirements);
        expect(text).toContain('أساسيات Blender 4');
        if (visible) {
          expect(panels[0].props.canReply).toBe(false);
          expect(text).toContain(content);
          expect(text).toContain('لم يكتمل الرد');
          expect(StyleSheet.flatten(panels[0].parent?.props.style)).toEqual(
            expect.objectContaining({width: '100%', alignSelf: 'stretch'}),
          );
        }

        act(() => {
          renderer.root
            .findByProps({accessibilityLabel: 'عرض تفاصيل المشروع'})
            .props.onPress();
        });
        const expandedText = renderer.root
          .findAllByType(Text)
          .map(node => cleanUnicodeText(node.props.children));
        expect(expandedText).toContain(project.title);
        expect(expandedText).toContain(project.requirements);
        expect(
          renderer.root.findByProps({
            accessibilityLabel: 'إخفاء تفاصيل المشروع',
          }).props.accessibilityState,
        ).toEqual({expanded: true});
      } finally {
        if (renderer) act(() => renderer.unmount());
      }
    },
  );
});

const controllerFor = (overrides: Record<string, unknown> = {}) => ({
  journeyState: 'draft',
  reportViewState: 'hidden',
  feedbackThread: null,
  feedbackAttachments: [],
  feedbackDraft: '',
  normalizedFeedbackDraft: '',
  feedbackLevel: 'none',
  feedbackPending: false,
  feedbackSending: false,
  canReplyToFeedback: false,
  feedbackError: '',
  feedbackReadError: '',
  feedbackReadRetrying: false,
  retryFeedbackRead: jest.fn(),
  refreshFeedbackRead: jest.fn(),
  canContinue: false,
  syncNote: '',
  reportRetrying: false,
  reviewFeedback: '',
  submissionAllowed: true,
  submissionDraftSaveError: false,
  fileSubmissionEnabled: true,
  filePickerDisabled: false,
  fileTypesLabel: 'صور أو ملفات',
  submissionMaximumFiles: 5,
  submissionNote: '',
  selectedFiles: [],
  submissionSending: false,
  submitDisabled: false,
  textSubmissionEnabled: true,
  changeFeedbackDraft: jest.fn(),
  pickFeedbackAttachments: jest.fn(),
  removeFeedbackAttachment: jest.fn(),
  retryFeedbackMessage: jest.fn(),
  sendFeedback: jest.fn(),
  retryReport: jest.fn(),
  editRetry: jest.fn(),
  changeSubmissionNote: jest.fn(),
  chooseProjectFile: jest.fn(),
  removeSubmissionFile: jest.fn(),
  submit: jest.fn(),
  ...overrides,
});

const renderTransitionFixture = (
  controller: Record<string, unknown>,
  props: {onContinue?: () => void} = {},
) => {
  mockController.mockReturnValue(controller);
  const element: React.ReactElement<
    React.ComponentProps<typeof ProjectTransition>
  > = (
    <ProjectTransition
      active
      project={project}
      moduleTitle="أساسيات Blender 4"
      width={390}
      height={844}
      onSubmit={jest.fn()}
      onContinue={props.onContinue}
    />
  );
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => {
    renderer = TestRenderer.create(element);
  });
  return {renderer, element};
};

const renderTransition = (
  controller: Record<string, unknown>,
  props: {onContinue?: () => void} = {},
) => renderTransitionFixture(controller, props).renderer;

describe('project lifecycle presentation', () => {
  it('recovers an empty report with GET instead of the report-generation action and leaves continuation available', () => {
    const retryRead = jest.fn();
    const retryReport = jest.fn();
    const onContinue = jest.fn();
    const controller = controllerFor({
      journeyState: 'passed',
      reportViewState: 'failed_retryable',
      canContinue: true,
      feedbackReadError: 'تعذّر تحميل التقرير',
      retryFeedbackRead: retryRead,
      retryReport,
    });
    const {renderer, element} = renderTransitionFixture(controller, {
      onContinue,
    });
    try {
      const text = () =>
        renderer.root
          .findAllByType(Text)
          .map(node => cleanUnicodeText(node.props.children));
      expect(text()).toContain('تعذّر تحميل التقرير');
      expect(text().some(value => value.includes('تعذّر تجهيز التقرير'))).toBe(
        false,
      );
      expect(renderer.root.findAllByType(ActivityIndicator)).toHaveLength(0);
      act(() =>
        renderer.root
          .findByProps({
            accessibilityLabel: 'إعادة تحميل تقرير المشروع والمناقشة',
          })
          .props.onPress(),
      );
      expect(retryRead).toHaveBeenCalledTimes(1);
      expect(retryReport).not.toHaveBeenCalled();
      act(() =>
        renderer.root
          .findByProps({accessibilityLabel: 'أكمل الكورس'})
          .props.onPress(),
      );
      expect(onContinue).toHaveBeenCalledTimes(1);
      mockController.mockReturnValue({
        ...controller,
        feedbackReadRetrying: true,
      });
      act(() => renderer.update(React.cloneElement(element)));
      expect(
        renderer.root.findByProps({
          accessibilityLabel: 'إعادة تحميل تقرير المشروع والمناقشة',
        }).props.accessibilityState,
      ).toEqual({busy: true, disabled: true});
      expect(text()).toContain('جارٍ التحديث');
      expect(renderer.root.findAllByType(ActivityIndicator)).toHaveLength(0);
      mockController.mockReturnValue({...controller, feedbackReadError: ''});
      act(() => renderer.update(React.cloneElement(element)));
      expect(text()).toContain('تعذّر تجهيز التقرير  حاول مرة أخرى');
    } finally {
      act(() => renderer.unmount());
    }
  });

  it('keeps the report, open discussion and composer while showing a transcript-read recovery', () => {
    const retryRead = jest.fn();
    const controller = controllerFor({
      journeyState: 'passed',
      reportViewState: 'ready',
      feedbackLevel: 'enhanced',
      canReplyToFeedback: true,
      feedbackDraft: 'سؤالي محفوظ',
      normalizedFeedbackDraft: 'سؤالي محفوظ',
      feedbackReadError: 'تعذّر تحديث الرد',
      retryFeedbackRead: retryRead,
      feedbackThread: {
        id: 'thread-7',
        feedbackLevel: 'enhanced',
        canReply: true,
        status: 'ready',
        remainingMessages: 5,
        messages: [
          {
            id: 'report-7',
            role: 'assistant',
            status: 'completed',
            text: partial,
          },
        ],
      },
    });
    const {renderer, element} = renderTransitionFixture(controller);
    try {
      act(() =>
        renderer.root
          .findByProps({accessibilityLabel: 'هل لديك سؤال؟'})
          .props.onPress(),
      );
      const readAction = () =>
        renderer.root.findByProps({
          accessibilityLabel: 'إعادة تحميل تقرير المشروع والمناقشة',
        });
      expect(
        renderer.root.findAllByType(ProjectFeedbackReadRecovery),
      ).toHaveLength(1);
      act(() => readAction().props.onPress());
      expect(retryRead).toHaveBeenCalledTimes(1);
      mockController.mockReturnValue({
        ...controller,
        feedbackReadRetrying: true,
      });
      act(() => renderer.update(React.cloneElement(element)));
      expect(
        renderer.root.findByProps({
          accessibilityLabel: 'استفسارك عن تقرير المشروع',
        }).props.value,
      ).toBe('سؤالي محفوظ');
      expect(
        renderer.root.findByProps({accessibilityLabel: 'إغلاق المناقشة'}),
      ).toBeDefined();
      expect(
        renderer.root
          .findAllByType(Text)
          .map(node => cleanUnicodeText(node.props.children)),
      ).toContain(partial);
      expect(controller.sendFeedback).not.toHaveBeenCalled();
      expect(controller.retryReport).not.toHaveBeenCalled();
    } finally {
      act(() => renderer.unmount());
    }
  });

  it('keeps a long report and continuation independent from the optional upgrade sheet', () => {
    const refresh = jest.fn();
    const refreshFeedbackRead = jest.fn();
    const onContinue = jest.fn();
    const longReport = 'ملاحظات المشروع كاملة دون اختصار\n'.repeat(80);
    mockController.mockReturnValue(
      controllerFor({
        journeyState: 'passed',
        canContinue: true,
        refreshFeedbackRead,
        reportViewState: 'ready',
        feedbackLevel: 'report',
        feedbackThread: {
          id: 'thread-7',
          feedbackLevel: 'report',
          canReply: false,
          status: 'ready',
          remainingMessages: 0,
          messages: [
            {
              id: 'report',
              role: 'assistant',
              status: 'completed',
              text: longReport,
            },
          ],
        },
      }),
    );
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      act(() => {
        renderer = TestRenderer.create(
          <ProjectTransition
            active
            courseId="7"
            courseTitle="تصميم"
            project={project}
            moduleTitle="تطبيق"
            width={320}
            height={640}
            onSubmit={jest.fn()}
            onContinue={onContinue}
            onEntitlementChanged={refresh}
          />,
        );
      });
      expect(renderer.root.findAllByType(FullTrackUpgradeSheet)).toHaveLength(
        0,
      );
      const reportBody = renderer.root
        .findAllByType(Text)
        .find(
          node => cleanUnicodeText(node.props.children) === longReport.trim(),
        )!;
      expect(reportBody).toBeDefined();
      expect(reportBody.props.numberOfLines).toBeUndefined();
      act(() =>
        renderer.root
          .findByProps({accessibilityLabel: 'هل لديك سؤال؟'})
          .props.onPress(),
      );
      const upgrade = renderer.root.findByType(FullTrackUpgradeSheet);
      expect(upgrade.props.requiredFeature).toBe('project_discussion');
      expect(upgrade.props.courseId).toBe('7');
      expect(upgrade.props.quotaExhausted).toBe(false);
      act(() => {
        void upgrade.props.onUpgraded();
      });
      expect(refreshFeedbackRead).toHaveBeenCalledTimes(1);
      expect(refresh).toHaveBeenCalledTimes(1);
      act(() => upgrade.props.onClose());
      act(() =>
        renderer.root
          .findByProps({accessibilityLabel: 'أكمل الكورس'})
          .props.onPress(),
      );
      expect(onContinue).toHaveBeenCalledTimes(1);
      expect(
        mockController.mock.results.at(-1)?.value.sendFeedback,
      ).not.toHaveBeenCalled();
    } finally {
      if (renderer) act(() => renderer.unmount());
    }
  });

  it('binds an exhausted discussion CTA to the existing project upgrade sheet and receipt refresh', async () => {
    jest.mocked(getFullTrackUpgradeQuote).mockClear();
    jest.mocked(getFullTrackUpgradeQuote).mockResolvedValue({
      upgradeAvailable: true,
      availablePlanCodes: ['mentor'],
      alreadyUpgraded: false,
    } as Awaited<ReturnType<typeof getFullTrackUpgradeQuote>>);
    const refreshFeedbackRead = jest.fn();
    const refresh = jest.fn();
    const onContinue = jest.fn();
    mockController.mockReturnValue(
      controllerFor({
        journeyState: 'passed',
        canContinue: true,
        reportViewState: 'ready',
        feedbackLevel: 'enhanced',
        refreshFeedbackRead,
        feedbackHydrating: true,
        feedbackThread: {
          id: 'thread-7',
          feedbackLevel: 'enhanced',
          canReply: true,
          status: 'ready',
          transcriptIncluded: true,
          remainingMessages: 2,
          replyLimitReached: true,
          messages: [
            {
              id: 'report',
              role: 'assistant',
              status: 'completed',
              text: partial,
            },
          ],
        },
      }),
    );
    const element = (
      <ProjectTransition
        active
        project={project}
        courseId="7"
        courseTitle="تصميم"
        moduleTitle="تطبيق"
        width={390}
        height={844}
        onSubmit={jest.fn()}
        onContinue={onContinue}
        onEntitlementChanged={refresh}
      />
    );
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(element);
      });
      await act(async () => {
        renderer.root
          .findByProps({accessibilityLabel: 'هل لديك سؤال؟'})
          .props.onPress();
      });
      expect(getFullTrackUpgradeQuote).not.toHaveBeenCalled();
      mockController.mockReturnValue({
        ...mockController.mock.results.at(-1)!.value,
        feedbackHydrating: false,
      });
      await act(async () => {
        renderer.update(React.cloneElement(element));
      });
      expect(getFullTrackUpgradeQuote).toHaveBeenCalledTimes(1);
      expect(renderer.root.findAllByType(FullTrackUpgradeSheet)).toHaveLength(
        0,
      );
      await act(async () => {
        renderer.root
          .findByProps({accessibilityLabel: 'قم بترقية الاشتراك'})
          .props.onPress();
      });
      const sheet = renderer.root.findByType(FullTrackUpgradeSheet);
      expect(sheet.props.requiredFeature).toBe('project_discussion');
      expect(sheet.props.quotaExhausted).toBe(true);
      expect(sheet.props.courseId).toBe('7');
      await act(async () => {
        await sheet.props.onUpgraded();
      });
      expect(refreshFeedbackRead).toHaveBeenCalledTimes(1);
      expect(refresh).toHaveBeenCalledTimes(1);
      await act(async () => {
        sheet.props.onClose();
      });
      await act(async () => {
        renderer.root
          .findByProps({accessibilityLabel: 'أكمل الكورس'})
          .props.onPress();
      });
      expect(onContinue).toHaveBeenCalledTimes(1);
    } finally {
      if (renderer) await act(async () => renderer.unmount());
    }
  });
  // Structural guard only: the renderer does not measure native IME geometry.
  // Field/CTA visibility must also be verified on the native keyboard surface.
  it.each(['android', 'ios'] as const)(
    'uses padding and the platform coordinate offset on %s without changing project actions',
    platform => {
      const originalPlatform = Platform.OS;
      let renderer: TestRenderer.ReactTestRenderer | undefined;
      const submit = jest.fn();
      try {
        Platform.OS = platform;
        const fixture = renderTransitionFixture(controllerFor({submit}));
        renderer = fixture.renderer;
        const avoidance = () => renderer!.root.findByType(KeyboardAvoidingView);
        expect(avoidance().props.enabled ?? true).toBe(true);
        expect(avoidance().props.behavior).toBe('padding');
        expect(avoidance().props.keyboardVerticalOffset).toBe(0);
        expect(StyleSheet.flatten(avoidance().props.style)).toEqual(
          expect.objectContaining({width: 390, height: 844}),
        );

        act(() => {
          renderer!.update(
            React.cloneElement(fixture.element, {height: 320, topInset: 24}),
          );
        });
        expect(StyleSheet.flatten(avoidance().props.style).height).toBe(320);
        expect(avoidance().props.keyboardVerticalOffset).toBe(
          platform === 'ios' ? 24 : 0,
        );
        expect(avoidance().props.enabled ?? true).toBe(true);
        expect(avoidance().props.behavior).toBe('padding');
        act(() => {
          renderer!.root.findByType(ProjectSubmissionEditor).props.onSubmit();
        });
        expect(submit).toHaveBeenCalledTimes(1);
      } finally {
        if (renderer) act(() => renderer!.unmount());
        Platform.OS = originalPlatform;
      }
    },
  );

  it('offers local draft recovery without exposing submission or an endless spinner', () => {
    const retrySubmissionDraftRestore = jest.fn();
    const submit = jest.fn();
    const retryReview = jest.fn();
    const renderer = renderTransition(
      controllerFor({
        journeyState: 'details',
        submissionDraftRestoreError: true,
        retrySubmissionDraftRestore,
        retryReview,
        submit,
      }),
    );
    try {
      expect(renderer.root.findAllByType(ActivityIndicator)).toHaveLength(0);
      expect(renderer.root.findAllByType(ProjectSubmissionEditor)).toHaveLength(
        0,
      );
      expect(
        renderer.root.findByProps({accessibilityLabel: 'عرض تفاصيل المشروع'}),
      ).toBeTruthy();
      act(() =>
        renderer.root
          .findByProps({accessibilityLabel: 'إعادة استعادة مسودة المشروع'})
          .props.onPress(),
      );
      expect(retrySubmissionDraftRestore).toHaveBeenCalledTimes(1);
      expect(submit).not.toHaveBeenCalled();
      expect(retryReview).not.toHaveBeenCalled();
    } finally {
      act(() => renderer.unmount());
    }
  });

  it.each([true, false])(
    'shows saved but unavailable review without a spinner or failed-project instruction (retry %s)',
    canRetry => {
      const retryReview = jest.fn();
      const renderer = renderTransition(
        controllerFor({
          journeyState: 'review_unavailable',
          reviewRetryAvailable: canRetry,
          retryReview,
          submissionAllowed: false,
        }),
      );
      try {
        const text = renderer.root
          .findAllByType(Text)
          .map(node => node.props.children);
        expect(text).toContain('تسليمك محفوظ ولم تكتمل مراجعته');
        expect(text).not.toContain('يحتاج المشروع إلى تعديل');
        expect(renderer.root.findAllByType(ActivityIndicator)).toHaveLength(0);
        expect(
          renderer.root.findAllByType(ProjectSubmissionEditor),
        ).toHaveLength(0);
        expect(text.includes('إعادة المراجعة')).toBe(canRetry);
        if (canRetry) {
          const label = renderer.root
            .findAllByType(Text)
            .find(node => node.props.children === 'إعادة المراجعة')!;
          let button = label.parent!;
          while (!button.props.onPress) button = button.parent!;
          act(() => button.props.onPress());
          expect(retryReview).toHaveBeenCalledTimes(1);
        }
      } finally {
        act(() => renderer.unmount());
      }
    },
  );
  it('keeps the brief dominant while the learner can submit', () => {
    const submit = jest.fn();
    const renderer = renderTransition(controllerFor({submit}));
    try {
      const text = renderer.root
        .findAllByType(Text)
        .map(node => cleanUnicodeText(node.props.children));
      expect(text).toContain(project.title);
      expect(text).toContain(project.requirements);
      expect(
        renderer.root.findAllByProps({
          accessibilityLabel: 'عرض تفاصيل المشروع',
        }),
      ).toHaveLength(0);
      act(() => {
        renderer.root.findByType(ProjectSubmissionEditor).props.onSubmit();
      });
      expect(submit).toHaveBeenCalledTimes(1);
    } finally {
      act(() => renderer.unmount());
    }
  });

  it('shows reviewing as the primary state with no false action', () => {
    const renderer = renderTransition(
      controllerFor({journeyState: 'reviewing'}),
    );
    try {
      expect(
        renderer.root.findByProps({accessibilityLabel: 'عرض تفاصيل المشروع'}),
      ).toBeTruthy();
      expect(
        renderer.root.findAllByProps({accessibilityLabel: 'أكمل الكورس'}),
      ).toHaveLength(0);
      expect(renderer.root.findAllByType(ProjectSubmissionEditor)).toHaveLength(
        0,
      );
    } finally {
      act(() => renderer.unmount());
    }
  });

  it('keeps the accepted-course action available before optional details', () => {
    const onContinue = jest.fn();
    const renderer = renderTransition(
      controllerFor({
        journeyState: 'passed',
        canContinue: true,
        submissionDraftRestoreError: true,
      }),
      {onContinue},
    );
    try {
      act(() => {
        renderer.root
          .findByProps({accessibilityLabel: 'أكمل الكورس'})
          .props.onPress();
      });
      expect(onContinue).toHaveBeenCalledTimes(1);
      expect(
        renderer.root.findByProps({accessibilityLabel: 'عرض تفاصيل المشروع'}),
      ).toBeTruthy();
    } finally {
      act(() => renderer.unmount());
    }
  });

  it('preserves the resubmission action for a rejected project', () => {
    const editRetry = jest.fn();
    const renderer = renderTransition(
      controllerFor({
        journeyState: 'needs_changes',
        reviewFeedback: 'عدّل التباين ثم أرسل من جديد',
        editRetry,
      }),
    );
    try {
      act(() => {
        renderer.root
          .findByProps({accessibilityLabel: 'عدّل التسليم'})
          .props.onPress();
      });
      expect(editRetry).toHaveBeenCalledTimes(1);
      const text = renderer.root
        .findAllByType(Text)
        .map(node => cleanUnicodeText(node.props.children));
      expect(text).toContain('عدّل التباين ثم أرسل من جديد');
    } finally {
      act(() => renderer.unmount());
    }
  });
});
