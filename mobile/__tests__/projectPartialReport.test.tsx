import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {ActivityIndicator, StyleSheet, Text} from 'react-native';
import type {CourseProject} from '../src/components/VideoPlayer/types';
import {cleanUnicodeText} from '../src/utils/unicodeText';

const mockController = jest.fn();
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

const renderTransition = (
  controller: Record<string, unknown>,
  props: {onContinue?: () => void} = {},
) => {
  mockController.mockReturnValue(controller);
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => {
    renderer = TestRenderer.create(
      <ProjectTransition
        active
        project={project}
        moduleTitle="أساسيات Blender 4"
        width={390}
        height={844}
        onSubmit={jest.fn()}
        onContinue={props.onContinue}
      />,
    );
  });
  return renderer;
};

describe('project lifecycle presentation', () => {
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
      controllerFor({journeyState: 'passed', canContinue: true}),
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
