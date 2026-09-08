import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockLoadSubmission = jest.fn();
const mockSaveSubmission = jest.fn(async () => undefined);
const mockClearSubmission = jest.fn(async () => undefined);
const mockLoadFeedback = jest.fn();
const mockSaveFeedback = jest.fn(async () => undefined);
const mockClearFeedback = jest.fn(async () => undefined);
let mockBoundary = {scope: 'learner-a', epoch: 1};

jest.mock('expo-document-picker', () => ({getDocumentAsync: jest.fn()}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => mockBoundary,
  assertAccountSessionBoundary: jest.fn(boundary => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }),
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));
jest.mock('../src/services/projectSubmissionDraft', () => ({
  loadProjectSubmissionDraft: (...args: unknown[]) =>
    mockLoadSubmission(...args),
  saveProjectSubmissionDraft: () => mockSaveSubmission(),
  clearProjectSubmissionDraft: () => mockClearSubmission(),
}));
jest.mock('../src/services/projectFeedbackDraft', () => ({
  loadProjectFeedbackDraft: (...args: unknown[]) => mockLoadFeedback(...args),
  saveProjectFeedbackDraft: () => mockSaveFeedback(),
  clearProjectFeedbackDraft: () => mockClearFeedback(),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  loadProjectFeedbackThread: jest.fn(),
  sendProjectFeedbackMessage: jest.fn(),
  uploadProjectFeedbackAttachment: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/projectTransition/pickers', () => ({
  pickProjectFilesOwned: jest.fn(),
}));

import {useProjectSubmission} from '../src/components/VideoPlayer/projectTransition/useProjectSubmission';
import {useProjectFeedback} from '../src/components/VideoPlayer/projectTransition/useProjectFeedback';
import {
  sendProjectFeedbackMessage,
  uploadProjectFeedbackAttachment,
} from '../src/components/VideoPlayer/courseLearningApi';
import type {
  CourseProject,
  ProjectFeedbackThread,
} from '../src/components/VideoPlayer/types';

const project: CourseProject = {
  id: '41',
  sectionId: '410',
  moduleId: '8',
  title: 'المشروع',
  requirements: 'المطلوب',
  status: 'draft',
  isGraduationProject: false,
};
const thread: ProjectFeedbackThread = {
  id: 'thread-41',
  feedbackLevel: 'enhanced',
  canReply: true,
  remainingMessages: 5,
  status: 'ready',
  messages: [
    {id: 'report-41', role: 'assistant', status: 'completed', text: 'التقرير'},
  ],
};

describe('project draft restoration ownership', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockBoundary = {scope: 'learner-a', epoch: 1};
    mockLoadSubmission.mockReset().mockRejectedValue(new Error('READ_FAILED'));
    mockLoadFeedback.mockReset().mockRejectedValue(new Error('READ_FAILED'));
  });
  afterEach(() => jest.useRealTimers());

  it('does not clear or overwrite a submission draft after failed restore, background or unmount', async () => {
    let current!: ReturnType<typeof useProjectSubmission>;
    const onSubmit = jest.fn();
    const Harness = ({foreground = true}: {foreground?: boolean}) => {
      current = useProjectSubmission({
        active: true,
        appIsActive: foreground,
        project,
        status: 'draft',
        submissionAllowed: true,
        onSubmit,
        onOutcome: jest.fn(),
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    try {
      await act(async () => {
        jest.advanceTimersByTime(300);
      });
      expect(mockClearSubmission).not.toHaveBeenCalled();
      expect(mockSaveSubmission).not.toHaveBeenCalled();
      await act(async () => {
        renderer.update(<Harness foreground={false} />);
      });
      expect(mockClearSubmission).not.toHaveBeenCalled();
      expect(mockSaveSubmission).not.toHaveBeenCalled();
      expect(current.submitDisabled).toBe(true);
      act(() => current.changeNote('لا يجوز استبدال النسخة التي لم تُقرأ'));
      await act(async () => {
        await current.submit();
      });
      expect(current.note).toBe('');
      expect(onSubmit).not.toHaveBeenCalled();
    } finally {
      await act(async () => renderer.unmount());
    }
    expect(mockSaveSubmission).not.toHaveBeenCalled();
    expect(mockClearSubmission).not.toHaveBeenCalled();
  });

  it('does not overwrite a report reply draft after failed restore or enable a new send', async () => {
    let current!: ReturnType<typeof useProjectFeedback>;
    const Harness = ({foreground = true}: {foreground?: boolean}) => {
      current = useProjectFeedback({
        active: true,
        appIsActive: foreground,
        projectId: '41',
        seedThread: thread,
        feedbackLevel: 'enhanced',
        replyEnabled: true,
        reportStatus: 'ready',
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    try {
      await act(async () => {
        jest.advanceTimersByTime(300);
      });
      expect(mockSaveFeedback).not.toHaveBeenCalled();
      expect(current.canReply).toBe(false);
      act(() => current.changeDraft('لا يجوز الكتابة فوق مسودة لم تُقرأ'));
      expect(current.draft).toBe('');
      await act(async () =>
        current.send({text: 'لا يجوز إرسال بديل قبل الاستعادة'}),
      );
      expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
      expect(uploadProjectFeedbackAttachment).not.toHaveBeenCalled();
      await act(async () => {
        renderer.update(<Harness foreground={false} />);
      });
      expect(mockSaveFeedback).not.toHaveBeenCalled();
    } finally {
      await act(async () => renderer.unmount());
    }
    expect(mockSaveFeedback).not.toHaveBeenCalled();
    expect(mockClearFeedback).not.toHaveBeenCalled();
  });

  it.each([true, false])(
    'restores submission work through explicit retry (existing=%s)',
    async existing => {
      let current!: ReturnType<typeof useProjectSubmission>;
      const onSubmit = jest.fn();
      const Harness = () => {
        current = useProjectSubmission({
          active: true,
          appIsActive: true,
          project,
          status: 'draft',
          submissionAllowed: true,
          onSubmit,
          onOutcome: jest.fn(),
        });
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      try {
        expect(current.draftRestoreError).toBe(true);
        mockLoadSubmission.mockResolvedValue(
          existing
            ? {
                note: 'هذا هو عملي الأصلي المحفوظ',
                files: [],
                updatedAt: Date.now(),
              }
            : null,
        );
        await act(async () => {
          current.retryDraftRestore();
        });
        expect(current.draftRestoreError).toBe(false);
        expect(current.journeyState).toBe('draft');
        expect(current.note).toBe(existing ? 'هذا هو عملي الأصلي المحفوظ' : '');
        act(() =>
          current.changeNote('يمكنني التعديل بعد قراءة النسخة المحفوظة'),
        );
        expect(current.note).toBe('يمكنني التعديل بعد قراءة النسخة المحفوظة');
        expect(onSubmit).not.toHaveBeenCalled();
      } finally {
        await act(async () => renderer.unmount());
      }
    },
  );

  it('restores the report reply after explicit retry without a new server request', async () => {
    let current!: ReturnType<typeof useProjectFeedback>;
    const Harness = () => {
      current = useProjectFeedback({
        active: true,
        appIsActive: true,
        projectId: '41',
        seedThread: thread,
        feedbackLevel: 'enhanced',
        replyEnabled: true,
        reportStatus: 'ready',
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    try {
      expect(current.draftRestoreError).toBe(true);
      mockLoadFeedback.mockResolvedValue({
        text: 'سؤالي المحفوظ عن المشروع',
        attachments: [],
        requestId: 'original-request',
        fingerprint: 'original-fingerprint',
        updatedAt: Date.now(),
      });
      await act(async () => {
        current.retryDraftRestore();
      });
      expect(current.draftRestoreError).toBe(false);
      expect(current.canReply).toBe(true);
      expect(current.draft).toBe('سؤالي المحفوظ عن المشروع');
      expect(mockClearFeedback).not.toHaveBeenCalled();
      expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
      expect(uploadProjectFeedbackAttachment).not.toHaveBeenCalled();
    } finally {
      await act(async () => renderer.unmount());
    }
  });

  describe.each(['submission', 'feedback'] as const)('%s retry owner', kind => {
    let current:
      | ReturnType<typeof useProjectSubmission>
      | ReturnType<typeof useProjectFeedback>;
    let renderer: TestRenderer.ReactTestRenderer;
    const onSubmit = jest.fn();
    const loader =
      kind === 'submission' ? mockLoadSubmission : mockLoadFeedback;
    const secondThread = {...thread, id: 'thread-42'};
    const saved =
      kind === 'submission'
        ? {note: 'المسودة الأصلية', files: [], updatedAt: Date.now()}
        : {
            text: 'المسودة الأصلية',
            attachments: [],
            requestId: 'same-request',
            fingerprint: 'same-fingerprint',
            updatedAt: Date.now(),
          };
    const Harness =
      kind === 'submission'
        ? function SubmissionHarness({id = '41'}: {id?: string}) {
            current = useProjectSubmission({
              active: true,
              appIsActive: true,
              project: {...project, id},
              status: 'draft',
              submissionAllowed: true,
              onSubmit,
              onOutcome: jest.fn(),
            });
            return null;
          }
        : function FeedbackHarness({id = '41'}: {id?: string}) {
            current = useProjectFeedback({
              active: true,
              appIsActive: true,
              projectId: id,
              seedThread: id === '41' ? thread : secondThread,
              feedbackLevel: 'enhanced',
              replyEnabled: true,
              reportStatus: 'ready',
            });
            return null;
          };
    beforeEach(async () => {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
    });
    afterEach(async () => {
      await act(async () => renderer.unmount());
    });

    it('renews a changed epoch for the same account without sending automatically', async () => {
      expect(current.draftRestoreError).toBe(true);
      mockBoundary = {scope: 'learner-a', epoch: 2};
      loader.mockResolvedValue(saved);
      await act(async () => current.retryDraftRestore());
      expect(loader).toHaveBeenCalledTimes(2);
      expect(loader).toHaveBeenLastCalledWith(
        kind === 'submission' ? '41' : 'thread-41',
        mockBoundary,
      );
      expect(current.draftRestoreError).toBe(false);
      expect('note' in current ? current.note : current.draft).toBe(
        'المسودة الأصلية',
      );
      expect(onSubmit).not.toHaveBeenCalled();
      expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
      expect(uploadProjectFeedbackAttachment).not.toHaveBeenCalled();
    });

    it('retains the original owner when repeated retries observe another account', async () => {
      mockBoundary = {scope: 'learner-b', epoch: 2};
      loader.mockResolvedValue(saved);
      await act(async () => current.retryDraftRestore());
      await act(async () => current.retryDraftRestore());
      expect(loader).toHaveBeenCalledTimes(1);
      expect(current.draftRestoreError).toBe(true);
      expect('note' in current ? current.note : current.draft).toBe('');
      expect(mockSaveSubmission).not.toHaveBeenCalled();
      expect(mockSaveFeedback).not.toHaveBeenCalled();
      expect(onSubmit).not.toHaveBeenCalled();
      expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
      mockBoundary = {scope: 'learner-a', epoch: 3};
      await act(async () => current.retryDraftRestore());
      expect(loader).toHaveBeenCalledTimes(2);
      expect(loader).toHaveBeenLastCalledWith(
        kind === 'submission' ? '41' : 'thread-41',
        mockBoundary,
      );
      expect(current.draftRestoreError).toBe(false);
    });

    it('ignores an old retry callback after a different project has restored', async () => {
      const oldRetry = current.retryDraftRestore;
      loader.mockResolvedValue(saved);
      await act(async () => renderer.update(<Harness id="42" />));
      expect(current.draftRestoreError).toBe(false);
      const calls = loader.mock.calls.length;
      await act(async () => oldRetry());
      expect(loader).toHaveBeenCalledTimes(calls);
      expect('note' in current ? current.note : current.draft).toBe(
        'المسودة الأصلية',
      );
    });

    it('does not adopt a retry read that finishes after switching projects', async () => {
      let resolve!: (value: typeof saved) => void;
      const pending = new Promise<typeof saved>(done => {
        resolve = done;
      });
      loader.mockReturnValueOnce(pending);
      await act(async () => current.retryDraftRestore());
      loader.mockResolvedValue({
        ...saved,
        note: 'عمل المشروع الآخر',
        text: 'عمل المشروع الآخر',
      });
      await act(async () => renderer.update(<Harness id="42" />));
      await act(async () => resolve(saved));
      expect('note' in current ? current.note : current.draft).toBe(
        'عمل المشروع الآخر',
      );
      expect(onSubmit).not.toHaveBeenCalled();
      expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
    });
  });
});
