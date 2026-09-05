import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockLoadDraft = jest.fn(async (..._args: unknown[]) => ({
  files: [],
  note: '',
  updatedAt: Date.now(),
}));
const mockSaveDraft = jest.fn(async (..._args: unknown[]) => undefined);
const mockClearDraft = jest.fn(async (..._args: unknown[]) => undefined);

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user-a',
  })),
}));

jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));

jest.mock('../src/services/projectSubmissionDraft', () => ({
  cacheProjectDraftFile: jest.fn(async file => file),
  clearProjectSubmissionDraft: (...args: unknown[]) =>
    mockClearDraft(...args),
  loadProjectSubmissionDraft: (...args: unknown[]) => mockLoadDraft(...args),
  saveProjectSubmissionDraft: (...args: unknown[]) => mockSaveDraft(...args),
}));

jest.mock(
  '../src/components/VideoPlayer/projectTransition/pickers',
  () => ({
    pickProjectFilesOwned: jest.fn(),
  }),
);

import {useProjectSubmission} from '../src/components/VideoPlayer/projectTransition/useProjectSubmission';
import type {CourseProject} from '../src/components/VideoPlayer/types';

const DOCX =
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

const project = (): CourseProject => ({
  id: '41',
  sectionId: 'section-41',
  moduleId: 'module-1',
  title: 'مشروع العبور',
  requirements: 'نفذ المشروع ثم ارفعه',
  status: 'draft',
  isGraduationProject: false,
  canSubmit: true,
  canContinue: false,
  submissionTextEnabled: true,
  submissionFilesEnabled: true,
  submissionAllowedMimeTypes: [DOCX],
});

describe('project submission draft hydration', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('does not erase unsaved typing when the same API contract is remapped', async () => {
    let current!: ReturnType<typeof useProjectSubmission>;
    const Harness = ({value}: {value: CourseProject}) => {
      current = useProjectSubmission({
        appIsActive: true,
        project: value,
        status: value.status,
        submissionAllowed: value.canSubmit === true,
        onSubmit: jest.fn(),
        onOutcome: jest.fn(),
      });
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness value={project()} />);
      await Promise.resolve();
      await Promise.resolve();
    });
    expect(mockLoadDraft).toHaveBeenCalledTimes(1);

    act(() => current.changeNote('كتابة لم تصل بعد إلى مهلة الحفظ'));
    expect(current.note).toBe('كتابة لم تصل بعد إلى مهلة الحفظ');

    await act(async () => {
      renderer.update(<Harness value={project()} />);
      await Promise.resolve();
    });

    expect(mockLoadDraft).toHaveBeenCalledTimes(1);
    expect(current.note).toBe('كتابة لم تصل بعد إلى مهلة الحفظ');

    act(() => renderer.unmount());
  });
});
