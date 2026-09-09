import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Alert} from 'react-native';

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
  clearProjectSubmissionDraft: (...args: unknown[]) => mockClearDraft(...args),
  loadProjectSubmissionDraft: (...args: unknown[]) => mockLoadDraft(...args),
  saveProjectSubmissionDraft: (...args: unknown[]) => mockSaveDraft(...args),
}));

jest.mock('../src/components/VideoPlayer/projectTransition/pickers', () => ({
  pickProjectFilesOwned: jest.fn(),
}));

import {useProjectSubmission} from '../src/components/VideoPlayer/projectTransition/useProjectSubmission';
import {pickProjectFilesOwned} from '../src/components/VideoPlayer/projectTransition/pickers';
import {cacheProjectDraftFile} from '../src/services/projectSubmissionDraft';
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

  it.each([
    'matching acceptance',
    'previous pass',
    'previous pending',
    'rate limited',
  ])('keeps editor ownership correct for %s', async outcomeKind => {
    const file = {
      uri: 'file:///new-work.docx',
      name: 'new-work.docx',
      type: DOCX,
      size: 100,
    };
    const onOutcome = jest.fn();
    const onSubmit = jest.fn(async () => {
      if (outcomeKind === 'previous pending')
        throw new Error('PROJECT_SUBMISSION_PREVIOUS_ATTEMPT_PENDING');
      if (outcomeKind === 'rate limited') {
        throw Object.assign(new Error('PROJECT_SUBMISSION_RATE_LIMITED'), {
          status: 429,
          retryAfterSeconds: 37,
        });
      }
      return {
        accepted: true,
        submissionStatus: 'passed' as const,
        canContinue: true,
        ...(outcomeKind === 'previous pass' ? {preserveDraft: true} : {}),
      };
    });
    jest.mocked(pickProjectFilesOwned).mockResolvedValueOnce({
      files: [file],
      ownerBoundary: {scope: 'user-a', epoch: 1},
    });
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    let current!: ReturnType<typeof useProjectSubmission>;
    function Harness({value}: {value: CourseProject}) {
      current = useProjectSubmission({
        appIsActive: true,
        project: value,
        status: value.status,
        submissionAllowed: value.canSubmit === true,
        onSubmit,
        onOutcome,
      });
      return null;
    }
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness value={project()} />);
      });
      await act(async () => {
        await current.chooseProjectFile();
      });
      act(() => current.changeNote('هذه تعديلات جديدة محفوظة'));
      // Submit before the debounce fires, as a quick real learner tap can.
      await act(async () => {
        await current.submit();
      });
      expect(mockSaveDraft).toHaveBeenCalledWith(
        '41',
        expect.objectContaining({
          files: [file],
          note: 'هذه تعديلات جديدة محفوظة',
        }),
        {scope: 'user-a', epoch: 1},
      );
      expect(mockSaveDraft.mock.invocationCallOrder[0]).toBeLessThan(
        onSubmit.mock.invocationCallOrder[0],
      );
      if (outcomeKind === 'matching acceptance') {
        expect(current.selectedFiles).toEqual([]);
        expect(current.note).toBe('');
        expect(mockClearDraft).toHaveBeenCalledWith('41', [file], {
          scope: 'user-a',
          epoch: 1,
        });
      } else {
        if (outcomeKind === 'previous pass') {
          await act(async () => {
            renderer.update(
              <Harness
                value={{...project(), status: 'passed', canSubmit: false}}
              />,
            );
          });
        }
        expect(current.selectedFiles).toEqual([file]);
        expect(current.note).toBe('هذه تعديلات جديدة محفوظة');
        expect(mockClearDraft).not.toHaveBeenCalled();
      }
      if (outcomeKind === 'rate limited') {
        expect(alert).toHaveBeenCalledWith(
          'انتظر قليلًا قبل الإرسال',
          expect.stringContaining('٣٧ ثانية'),
        );
        expect(JSON.stringify(alert.mock.calls)).not.toContain(
          'استقرار الاتصال',
        );
      }
      if (outcomeKind === 'previous pending') {
        expect(alert).toHaveBeenCalledWith(
          'نتحقق من المحاولة السابقة',
          expect.stringContaining('تعديلاتك الجديدة محفوظة'),
        );
      }
    } finally {
      act(() => renderer.unmount());
      alert.mockRestore();
    }
  });

  it.each([9, 8])(
    'enforces the project server limit before caching or upload for a %s MiB file',
    async mebibytes => {
      const value = {
        ...project(),
        submissionAllowedMimeTypes: ['application/pdf'],
        submissionMaxFileBytes: 8 * 1024 * 1024,
      };
      const selected = {
        uri: 'file:///project.pdf',
        name: 'project.pdf',
        type: 'application/pdf',
        size: mebibytes * 1024 * 1024,
      };
      jest.mocked(pickProjectFilesOwned).mockResolvedValueOnce({
        files: [selected],
        ownerBoundary: {scope: 'user-a', epoch: 1},
      });
      const onSubmit = jest.fn(async () => ({
        accepted: true,
        submissionStatus: 'evaluating' as const,
        canContinue: false,
      }));
      const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
      let current!: ReturnType<typeof useProjectSubmission>;
      function Harness() {
        current = useProjectSubmission({
          appIsActive: true,
          project: value,
          status: 'draft',
          submissionAllowed: true,
          onSubmit,
          onOutcome: jest.fn(),
        });
        return null;
      }
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Harness />);
        });
        expect(current.maximumFileSizeLabel).toBe('٨ ميجابايت');
        await act(async () => {
          await current.chooseProjectFile();
        });
        if (mebibytes > 8) {
          expect(current.selectedFiles).toEqual([]);
          expect(alert).toHaveBeenCalledWith(
            'حجم الملف كبير',
            'الحد الأقصى ٨ ميجابايت\nاختر نسخة أصغر',
          );
          expect(onSubmit).not.toHaveBeenCalled();
          expect(cacheProjectDraftFile).not.toHaveBeenCalled();
        } else {
          expect(current.selectedFiles).toEqual([selected]);
          expect(cacheProjectDraftFile).toHaveBeenCalledTimes(1);
          await act(async () => {
            await current.submit();
          });
          expect(onSubmit).toHaveBeenCalledTimes(1);
          expect(alert).not.toHaveBeenCalled();
        }
      } finally {
        act(() => renderer.unmount());
        alert.mockRestore();
      }
    },
  );

  it('keeps the next known draft non-submittable while the accepted result or access disallows another attempt', async () => {
    const onSubmit = jest.fn(async () => ({
      accepted: true,
      submissionStatus: 'evaluating' as const,
      canContinue: false,
    }));
    let current!: ReturnType<typeof useProjectSubmission>;
    const Harness = ({value}: {value: CourseProject}) => {
      current = useProjectSubmission({
        appIsActive: true,
        project: value,
        status: value.status,
        submissionAllowed: value.canSubmit === true,
        onSubmit,
        onOutcome: jest.fn(),
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness value={project()} />);
      });
      act(() => current.changeNote('محاولتي الأولى لهذا المشروع'));
      await act(async () => {
        await current.submit();
      });
      for (const status of [
        'evaluating',
        'review_unavailable',
        'passed',
        'needs_changes',
      ] as const) {
        await act(async () => {
          renderer.update(
            <Harness value={{...project(), status, canSubmit: false}} />,
          );
        });
        act(() => current.changeNote('هذا النص لا يسمح بتجاوز إذن الخادم'));
        expect(current.submitDisabled).toBe(true);
        expect(current.filePickerDisabled).toBe(true);
        await act(async () => {
          await current.submit();
        });
        expect(onSubmit).toHaveBeenCalledTimes(1);
      }
      expect(mockLoadDraft).toHaveBeenCalledTimes(1);
    } finally {
      act(() => renderer.unmount());
    }
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

  it('opens a fresh editor after another submission receives the same retry result', async () => {
    const rejected = {...project(), status: 'needs_changes' as const};
    const onSubmit = jest.fn(async () => ({
      accepted: true,
      submissionStatus: 'needs_changes' as const,
      canContinue: false,
      reviewFeedback: 'أضف نتيجة التطبيق إلى التسليم',
    }));
    let current!: ReturnType<typeof useProjectSubmission>;
    const Harness = ({value}: {value: CourseProject}) => {
      current = useProjectSubmission({
        appIsActive: true,
        project: value,
        status: value.status,
        submissionAllowed: value.canSubmit === true,
        onSubmit,
        onOutcome: jest.fn(),
      });
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness value={rejected} />);
    });
    act(() => current.editRetry());
    act(() => current.changeNote('أضفت وصفًا جديدًا لمحاولة المشروع'));
    expect(current.submitDisabled).toBe(false);

    await act(async () => {
      await current.submit();
      // The parent remaps the server response without changing its status.
      renderer.update(<Harness value={{...rejected}} />);
    });
    expect(current.journeyState).toBe('needs_changes');
    expect(current.note).toBe('');
    expect(current.selectedFiles).toEqual([]);

    act(() => current.editRetry());
    expect(current.journeyState).toBe('draft');
    act(() => current.changeNote('أرفقت هذه المرة نتيجة تنفيذ المشروع'));
    expect(current.submitDisabled).toBe(false);
    await act(async () => {
      await current.submit();
    });
    expect(onSubmit).toHaveBeenCalledTimes(2);

    act(() => renderer.unmount());
  });

  it.each([
    ['text', undefined, undefined],
    ['image', 'image/png', 'png'],
    ['document', DOCX, 'docx'],
    ['pdf', 'application/pdf', 'pdf'],
  ])(
    'opens the next %s attempt when an accepted pending submission is later rejected',
    async (_kind, mimeType, extension) => {
      const value = {
        ...project(),
        submissionAllowedMimeTypes: mimeType ? [mimeType] : [],
      };
      const onSubmit = jest.fn(async () => ({
        accepted: true,
        submissionStatus: 'evaluating' as const,
        canContinue: false,
      }));
      if (mimeType) {
        jest.mocked(pickProjectFilesOwned).mockResolvedValueOnce({
          files: [
            {
              uri: `file:///work.${extension}`,
              name: `work.${extension}`,
              type: mimeType,
              size: 100,
            },
          ],
          ownerBoundary: {scope: 'user-a', epoch: 1},
        });
      }
      let current!: ReturnType<typeof useProjectSubmission>;
      const Harness = ({input}: {input: CourseProject}) => {
        current = useProjectSubmission({
          appIsActive: true,
          project: input,
          status: input.status,
          submissionAllowed: input.canSubmit === true,
          onSubmit,
          onOutcome: jest.fn(),
        });
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Harness input={value} />);
        });
        if (mimeType) {
          await act(async () => {
            await current.chooseProjectFile();
          });
          expect(current.selectedFiles).toEqual([
            expect.objectContaining({
              name: `work.${extension}`,
              type: mimeType,
            }),
          ]);
        }
        act(() => current.changeNote('هذه محاولة المشروع الأولى'));
        await act(async () => {
          await current.submit();
          renderer.update(
            <Harness
              input={{...value, status: 'evaluating', canSubmit: false}}
            />,
          );
        });
        expect(current.journeyState).toBe('reviewing');
        expect(current.note).toBe('');
        expect(current.selectedFiles).toEqual([]);
        await act(async () => {
          await current.submit();
        });
        expect(onSubmit).toHaveBeenCalledTimes(1);
        await act(async () => {
          renderer.update(
            <Harness
              input={{...value, status: 'needs_changes', canSubmit: true}}
            />,
          );
        });
        expect(current.journeyState).toBe('needs_changes');
        act(() => current.editRetry());
        expect(current.journeyState).toBe('draft');
        act(() => current.changeNote('أضفت التعديلات المطلوبة في المراجعة'));
        expect(current.submitDisabled).toBe(false);
        await act(async () => {
          await current.submit();
        });
        expect(onSubmit).toHaveBeenCalledTimes(2);
        expect(onSubmit).toHaveBeenLastCalledWith(
          [],
          'أضفت التعديلات المطلوبة في المراجعة',
        );
        expect(mockLoadDraft).toHaveBeenCalledTimes(1);
      } finally {
        act(() => renderer.unmount());
      }
    },
  );
});
