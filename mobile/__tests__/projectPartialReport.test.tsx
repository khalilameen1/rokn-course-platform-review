import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Text} from 'react-native';
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
        expect(text).toContain(project.title);
        expect(text).toContain(project.requirements);
        expect(text).toContain('أساسيات Blender 4');
        if (visible) {
          expect(panels[0].props.canReply).toBe(false);
          expect(text).toContain(content);
          expect(text).toContain('لم يكتمل الرد');
        }
      } finally {
        if (renderer) act(() => renderer.unmount());
      }
    },
  );
});
