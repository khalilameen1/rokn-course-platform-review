import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {Alert} from 'react-native';

let mockBoundary = {scope: 'learner-a', epoch: 1};
const mockRetainFiles = jest.fn(async (..._args: unknown[]) => undefined);
const mockRemoveFile = jest.fn(async (..._args: unknown[]) => undefined);
const mockGet = jest.fn();
const storageSetItem = jest
  .mocked(AsyncStorage.setItem)
  .getMockImplementation()!;

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (base: string, boundary: {scope: string}) =>
    `${base}:${boundary.scope}`,
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: {scope: string; epoch: number}) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  cacheLearnerDraftFile: jest.fn(async (_kind, file) => file),
  learnerDraftFileIsReadable: jest.fn(async () => true),
  retainLearnerDraftFiles: (...args: unknown[]) => mockRetainFiles(...args),
  removeLearnerDraftFile: (...args: unknown[]) => mockRemoveFile(...args),
}));
jest.mock('../src/components/VideoPlayer/projectTransition/pickers', () => ({
  pickProjectFilesOwned: jest.fn(),
}));

import {
  copyProjectSubmissionDraft,
  loadProjectSubmissionDraft,
  saveProjectSubmissionDraft,
} from '../src/services/projectSubmissionDraft';
import {useProjectSubmission} from '../src/components/VideoPlayer/projectTransition/useProjectSubmission';
import type {
  CourseProject,
  SelectedProjectFile,
} from '../src/components/VideoPlayer/types';
import {subscribeCourseRevisionChanges} from '../src/components/VideoPlayer/courseLearning/playbackRevision';

const file: SelectedProjectFile = {
  uri: 'file:///tmp/rokn-cache/rokn_learner_drafts/learner-a/project/attempt.pdf',
  name: 'attempt.pdf',
  type: 'application/pdf',
  size: 100,
};
const note = 'تفسير أصلي لما نفذته في المشروع';
const project = (overrides: Partial<CourseProject> = {}): CourseProject => ({
  id: '41',
  sectionId: '410',
  moduleId: '8',
  title: 'المشروع',
  requirements: 'متطلبات المشروع',
  status: 'draft',
  isGraduationProject: false,
  canSubmit: true,
  canContinue: false,
  submissionTextEnabled: true,
  submissionFilesEnabled: true,
  submissionAllowedMimeTypes: ['application/pdf'],
  ...overrides,
});

const updatedProject = (currentProjectId: number | null = 42) => ({
  status: 409,
  data: {
    code: 'course_revision_changed',
    data: {
      source_project_id: 41,
      current_project_id: currentProjectId,
      current_section_id: currentProjectId === null ? null : 420,
      course_id: 7,
      published_revision: 2,
      reload_endpoint: '/api/v1/courses/7/details',
      submission_admission_closed: true,
    },
  },
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => {
    resolve = yes;
  });
  return {promise, resolve};
};
const flush = async () => {
  for (let tick = 0; tick < 18; tick += 1) await Promise.resolve();
};
const draftKey = (id: string) =>
  `@rokn/project-editor-draft/v1:learner-a:${id}`;

const mountEditor = async (rejection: unknown = updatedProject()) => {
  mockGet.mockReset().mockRejectedValue(rejection);
  const onSubmit = jest.fn(async () => {
    throw rejection;
  });
  const onOutcome = jest.fn();
  let current!: ReturnType<typeof useProjectSubmission>;
  const Harness = ({
    value = project(),
    active = true,
  }: {
    value?: CourseProject;
    active?: boolean;
  }) => {
    current = useProjectSubmission({
      appIsActive: true,
      active,
      project: value,
      status: value.status,
      submissionAllowed: value.canSubmit === true,
      onSubmit,
      onOutcome,
    });
    return null;
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
    await flush();
  });
  return {
    renderer,
    onSubmit,
    onOutcome,
    current: () => current,
    replace: (value: CourseProject) =>
      renderer.update(<Harness value={value} />),
    setActive: (active: boolean) =>
      renderer.update(<Harness active={active} />),
  };
};

describe('project editor draft across published requirements', () => {
  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    jest.mocked(AsyncStorage.setItem).mockImplementation(storageSetItem);
    mockBoundary = {scope: 'learner-a', epoch: 1};
    await AsyncStorage.clear();
    mockRetainFiles.mockResolvedValue(undefined);
  });
  afterEach(() => jest.useRealTimers());

  it('retains incompatible text and files when the new authored contract is hydrated and backgrounded', async () => {
    await saveProjectSubmissionDraft(
      '41',
      {files: [file], note, updatedAt: Date.now()},
      mockBoundary,
    );
    const replacement = project({
      submissionTextEnabled: false,
      submissionAllowedMimeTypes: ['image/png'],
    });
    const onSubmit = jest.fn();
    let current!: ReturnType<typeof useProjectSubmission>;
    const Harness = ({active}: {active: boolean}) => {
      current = useProjectSubmission({
        appIsActive: active,
        project: replacement,
        status: 'draft',
        submissionAllowed: true,
        onSubmit,
        onOutcome: jest.fn(),
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness active />);
      });
      expect(current.note).toBe(note);
      expect(current.selectedFiles).toEqual([file]);
      expect(current.submitDisabled).toBe(true);
      expect(mockRemoveFile).not.toHaveBeenCalled();
      await act(async () => {
        renderer.update(<Harness active={false} />);
      });
      expect(
        await loadProjectSubmissionDraft('41', mockBoundary),
      ).toMatchObject({note, files: [file]});
      expect(onSubmit).not.toHaveBeenCalled();
    } finally {
      await act(async () => renderer.unmount());
    }
  });

  it('copies the complete editor only on explicit review and signals navigation after destination persistence', async () => {
    await saveProjectSubmissionDraft(
      '41',
      {files: [file], note, updatedAt: Date.now()},
      mockBoundary,
    );
    const observer = jest.fn();
    const unsubscribe = subscribeCourseRevisionChanges(observer);
    const journey = await mountEditor();
    const write = deferred<void>();
    const setItem = storageSetItem;
    const writes = jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementation(async (key, value) => {
        if (key === draftKey('42')) await write.promise;
        return setItem(key, value);
      });
    try {
      await act(async () => {
        await journey.current().submit();
      });
      expect(journey.current().revisionMessage).toContain(
        'تغيّرت متطلبات المشروع',
      );
      expect(journey.current().submitDisabled).toBe(true);
      expect(observer).not.toHaveBeenCalled();
      await act(async () => {
        await journey.current().submit();
      });
      expect(journey.onSubmit).toHaveBeenCalledTimes(1);
      let preparing!: Promise<void>;
      await act(async () => {
        preparing = journey.current().reviewUpdatedProject();
        void journey.current().reviewUpdatedProject();
        await flush();
      });
      expect(observer).not.toHaveBeenCalled();
      expect(mockRetainFiles).toHaveBeenCalledWith(
        'project-submission:42',
        [file],
        'learner-a',
      );
      expect(
        writes.mock.calls.filter(call => call[0] === draftKey('42')),
      ).toHaveLength(1);
      expect(
        JSON.parse((await AsyncStorage.getItem(draftKey('41'))) || '{}'),
      ).toMatchObject({note, files: [file]});
      await act(async () => {
        write.resolve();
        await preparing;
      });
      expect(observer).toHaveBeenCalledTimes(1);
      expect(observer).toHaveBeenCalledWith(
        expect.objectContaining({courseId: '7'}),
      );
      expect(
        await loadProjectSubmissionDraft('42', mockBoundary),
      ).toMatchObject({note, files: [file]});
      await act(async () => {
        journey.replace(
          project({
            id: '42',
            submissionTextEnabled: false,
            submissionAllowedMimeTypes: ['image/png'],
          }),
        );
        await flush();
      });
      expect(journey.current().note).toBe(note);
      expect(journey.current().selectedFiles).toEqual([file]);
      expect(journey.current().submitDisabled).toBe(true);
      expect(journey.current().draftCompatibilityMessage).not.toBe('');
      expect(journey.onSubmit).toHaveBeenCalledTimes(1);
      expect(journey.onOutcome).not.toHaveBeenCalled();
    } finally {
      write.resolve();
      writes.mockRestore();
      unsubscribe();
      await act(async () => journey.renderer.unmount());
    }
  });

  it('renews an aged 41 to 42 revision before copying the draft to the current project 43', async () => {
    await saveProjectSubmissionDraft(
      '41',
      {note, files: [file], updatedAt: Date.now()},
      mockBoundary,
    );
    const observer = jest.fn();
    const unsubscribe = subscribeCourseRevisionChanges(observer);
    const journey = await mountEditor();
    try {
      await act(async () => {
        await journey.current().submit();
      });
      mockGet.mockRejectedValue(updatedProject(43));
      await act(async () => {
        await journey.current().reviewUpdatedProject();
      });
      expect(mockGet).toHaveBeenCalledWith('projects/41', expect.anything());
      expect(await loadProjectSubmissionDraft('42', mockBoundary)).toBeNull();
      expect(
        await loadProjectSubmissionDraft('43', mockBoundary),
      ).toMatchObject({note, files: [file]});
      expect(observer).toHaveBeenCalledWith(
        expect.objectContaining({
          sourceProjectId: '41',
          currentProjectId: '43',
        }),
      );
      expect(journey.onSubmit).toHaveBeenCalledTimes(1);
    } finally {
      unsubscribe();
      await act(async () => journey.renderer.unmount());
    }
  });

  it('does not apply a destination 42 confirmation to a newly published destination 43 with identical saved content', async () => {
    await saveProjectSubmissionDraft(
      '41',
      {note, files: [file], updatedAt: Date.now()},
      mockBoundary,
    );
    const destination = {note: 'عمل آخر', files: [], updatedAt: Date.now()};
    await saveProjectSubmissionDraft('42', destination, mockBoundary);
    await saveProjectSubmissionDraft('43', destination, mockBoundary);
    const observer = jest.fn();
    const unsubscribe = subscribeCourseRevisionChanges(observer);
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    const journey = await mountEditor();
    try {
      await act(async () => {
        await journey.current().submit();
      });
      await act(async () => {
        await journey.current().reviewUpdatedProject();
      });
      expect(alert).toHaveBeenCalledTimes(1);
      mockGet.mockRejectedValue(updatedProject(43));
      await act(async () => {
        alert.mock.calls[0][2]?.[1].onPress?.();
        await flush();
      });
      expect(observer).not.toHaveBeenCalled();
      expect(alert).toHaveBeenCalledTimes(2);
      expect(
        await loadProjectSubmissionDraft('42', mockBoundary),
      ).toMatchObject(destination);
      expect(
        await loadProjectSubmissionDraft('43', mockBoundary),
      ).toMatchObject(destination);
      await act(async () => {
        alert.mock.calls[1][2]?.[1].onPress?.();
        await flush();
      });
      expect(
        await loadProjectSubmissionDraft('43', mockBoundary),
      ).toMatchObject({note, files: [file]});
      expect(observer).toHaveBeenCalledWith(
        expect.objectContaining({currentProjectId: '43'}),
      );
      expect(journey.onSubmit).toHaveBeenCalledTimes(1);
    } finally {
      alert.mockRestore();
      unsubscribe();
      await act(async () => journey.renderer.unmount());
    }
  });

  it.each([
    'network',
    'not-a-revision',
    'account-changed',
    'inactive',
  ] as const)(
    'keeps the source without copying or navigation when revision renewal is %s',
    async outcome => {
      await saveProjectSubmissionDraft(
        '41',
        {note, files: [file], updatedAt: Date.now()},
        mockBoundary,
      );
      const observer = jest.fn();
      const unsubscribe = subscribeCourseRevisionChanges(observer);
      const journey = await mountEditor();
      const read = deferred<void>();
      try {
        await act(async () => {
          await journey.current().submit();
        });
        mockGet.mockImplementationOnce(() =>
          read.promise.then(() => {
            if (outcome === 'network') throw new Error('offline');
            if (outcome === 'not-a-revision')
              return {status: 200, data: {data: {id: 41}}};
            throw updatedProject(43);
          }),
        );
        let renewal!: Promise<void>;
        await act(async () => {
          renewal = journey.current().reviewUpdatedProject();
          await flush();
        });
        expect(mockGet).toHaveBeenCalledTimes(1);
        if (outcome === 'account-changed')
          mockBoundary = {scope: 'learner-b', epoch: 2};
        if (outcome === 'inactive')
          await act(async () => {
            journey.setActive(false);
          });
        await act(async () => {
          read.resolve();
          await renewal;
        });
        expect(observer).not.toHaveBeenCalled();
        expect(await AsyncStorage.getItem(draftKey('42'))).toBeNull();
        expect(await AsyncStorage.getItem(draftKey('43'))).toBeNull();
        expect(
          JSON.parse((await AsyncStorage.getItem(draftKey('41'))) || '{}'),
        ).toMatchObject({note, files: [file]});
        expect(journey.onSubmit).toHaveBeenCalledTimes(1);
      } finally {
        read.resolve();
        unsubscribe();
        await act(async () => journey.renderer.unmount());
      }
    },
  );

  it('asks before replacing a destination and asks again if that confirmed snapshot changes', async () => {
    const source = {note, files: [file], updatedAt: Date.now()};
    await saveProjectSubmissionDraft('41', source, mockBoundary);
    await saveProjectSubmissionDraft(
      '42',
      {note: 'مسودة أخرى', files: [], updatedAt: Date.now()},
      mockBoundary,
    );
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    const observer = jest.fn();
    const unsubscribe = subscribeCourseRevisionChanges(observer);
    const journey = await mountEditor();
    try {
      await act(async () => {
        await journey.current().submit();
      });
      await act(async () => {
        await journey.current().reviewUpdatedProject();
      });
      expect(alert).toHaveBeenCalledWith(
        'توجد مسودة للمشروع المحدّث',
        expect.any(String),
        expect.any(Array),
      );
      expect(
        await loadProjectSubmissionDraft('42', mockBoundary),
      ).toMatchObject({note: 'مسودة أخرى'});
      expect(
        await loadProjectSubmissionDraft('41', mockBoundary),
      ).toMatchObject(source);
      expect(observer).not.toHaveBeenCalled();
      // Cancel has no destructive callback. A later explicit confirm owns only
      // the exact destination snapshot originally shown, not subsequent edits.
      expect(alert.mock.calls[0][2]?.[0]).toMatchObject({
        text: 'إلغاء',
        style: 'cancel',
      });
      await saveProjectSubmissionDraft(
        '42',
        {note: 'تعديلات أحدث', files: [], updatedAt: Date.now()},
        mockBoundary,
      );
      await act(async () => {
        alert.mock.calls[0][2]?.[1].onPress?.();
        await flush();
      });
      expect(alert).toHaveBeenCalledTimes(2);
      expect(
        await loadProjectSubmissionDraft('42', mockBoundary),
      ).toMatchObject({note: 'تعديلات أحدث'});
      expect(observer).not.toHaveBeenCalled();
      await act(async () => {
        alert.mock.calls[1][2]?.[1].onPress?.();
        await flush();
      });
      expect(
        await loadProjectSubmissionDraft('42', mockBoundary),
      ).toMatchObject({note, files: [file]});
      expect(
        await loadProjectSubmissionDraft('41', mockBoundary),
      ).toMatchObject({note, files: [file]});
      expect(observer).toHaveBeenCalledTimes(1);
      expect(journey.onSubmit).toHaveBeenCalledTimes(1);
    } finally {
      alert.mockRestore();
      unsubscribe();
      await act(async () => journey.renderer.unmount());
    }
  });

  it.each(['destination write', 'file references'])(
    'keeps the source and retries %s failure locally without another POST',
    async failure => {
      await saveProjectSubmissionDraft(
        '41',
        {note, files: [file], updatedAt: Date.now()},
        mockBoundary,
      );
      const observer = jest.fn();
      const unsubscribe = subscribeCourseRevisionChanges(observer);
      const journey = await mountEditor();
      const setItem = storageSetItem;
      let failed = false;
      const writes = jest
        .spyOn(AsyncStorage, 'setItem')
        .mockImplementation(async (key, value) => {
          if (
            failure === 'destination write' &&
            key === draftKey('42') &&
            !failed
          ) {
            failed = true;
            throw new Error('disk');
          }
          return setItem(key, value);
        });
      mockRetainFiles.mockImplementation(async (...args) => {
        if (
          failure === 'file references' &&
          args[0] === 'project-submission:42' &&
          !failed
        ) {
          failed = true;
          throw new Error('disk');
        }
      });
      try {
        await act(async () => {
          await journey.current().submit();
        });
        await act(async () => {
          await journey.current().reviewUpdatedProject();
        });
        expect(journey.current().revisionMessage).toContain(
          'تعذّر تجهيز المسودة',
        );
        expect(observer).not.toHaveBeenCalled();
        expect(
          await loadProjectSubmissionDraft('41', mockBoundary),
        ).toMatchObject({note, files: [file]});
        expect(await AsyncStorage.getItem(draftKey('42'))).toBeNull();
        await act(async () => {
          await journey.current().reviewUpdatedProject();
        });
        expect(observer).toHaveBeenCalledTimes(1);
        expect(journey.onSubmit).toHaveBeenCalledTimes(1);
        expect(
          await loadProjectSubmissionDraft('42', mockBoundary),
        ).toMatchObject({note, files: [file]});
      } finally {
        writes.mockRestore();
        unsubscribe();
        await act(async () => journey.renderer.unmount());
      }
    },
  );

  it('retains removed-project work without guessing a destination or resubmitting', async () => {
    await saveProjectSubmissionDraft(
      '41',
      {note, files: [file], updatedAt: Date.now()},
      mockBoundary,
    );
    const observer = jest.fn();
    const unsubscribe = subscribeCourseRevisionChanges(observer);
    const journey = await mountEditor(updatedProject(null));
    try {
      await act(async () => {
        await journey.current().submit();
      });
      expect(journey.current().revisionMessage).toContain(
        'لم يعد هذا المشروع ضمن الكورس',
      );
      expect(journey.current().canReviewUpdatedProject).toBe(true);
      expect(journey.current().revisionActionLabel).toBe('افتح الكورس المحدّث');
      expect(observer).not.toHaveBeenCalled();
      expect(journey.current().submitDisabled).toBe(true);
      await act(async () => {
        await journey.current().reviewUpdatedProject();
        await journey.current().submit();
      });
      expect(journey.current().note).toBe(note);
      expect(journey.current().selectedFiles).toEqual([file]);
      expect(journey.onSubmit).toHaveBeenCalledTimes(1);
      expect(observer).toHaveBeenCalledWith(
        expect.objectContaining({courseId: '7'}),
      );
      expect(observer.mock.calls[0][0].currentProjectId).toBeUndefined();
      expect(
        await loadProjectSubmissionDraft('41', mockBoundary),
      ).toMatchObject({note, files: [file]});
    } finally {
      unsubscribe();
      await act(async () => journey.renderer.unmount());
    }
  });

  it('keeps an uncertain previous attempt out of draft migration', async () => {
    await saveProjectSubmissionDraft(
      '41',
      {note, files: [file], updatedAt: Date.now()},
      mockBoundary,
    );
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    const journey = await mountEditor(
      new Error('PROJECT_SUBMISSION_PREVIOUS_ATTEMPT_PENDING'),
    );
    const observer = jest.fn();
    const unsubscribe = subscribeCourseRevisionChanges(observer);
    try {
      await act(async () => {
        await journey.current().submit();
        await journey.current().reviewUpdatedProject();
      });
      expect(journey.current().revisionMessage).toBe('');
      expect(alert).toHaveBeenCalledWith(
        'نتحقق من المحاولة السابقة',
        expect.any(String),
      );
      expect(await AsyncStorage.getItem(draftKey('42'))).toBeNull();
      expect(journey.current().note).toBe(note);
      expect(observer).not.toHaveBeenCalled();
    } finally {
      alert.mockRestore();
      unsubscribe();
      await act(async () => journey.renderer.unmount());
    }
  });

  it('registers both draft owners without releasing or removing the original source', async () => {
    const draft = {note, files: [file], updatedAt: Date.now()};
    expect(
      await copyProjectSubmissionDraft('41', '42', draft, mockBoundary),
    ).toEqual({kind: 'copied'});
    expect(await loadProjectSubmissionDraft('41', mockBoundary)).toMatchObject(
      draft,
    );
    expect(mockRetainFiles).toHaveBeenCalledWith(
      'project-submission:41',
      [file],
      'learner-a',
    );
    expect(mockRetainFiles).toHaveBeenCalledWith(
      'project-submission:42',
      [file],
      'learner-a',
    );
    expect(mockRemoveFile).not.toHaveBeenCalled();
  });

  it.each(['account replacement', 'unmount'])(
    'does not navigate after %s while the destination is being saved',
    async change => {
      await saveProjectSubmissionDraft(
        '41',
        {note, files: [file], updatedAt: Date.now()},
        mockBoundary,
      );
      const observer = jest.fn();
      const unsubscribe = subscribeCourseRevisionChanges(observer);
      const journey = await mountEditor();
      const write = deferred<void>();
      const writes = jest
        .spyOn(AsyncStorage, 'setItem')
        .mockImplementation(async (key, value) => {
          if (key === draftKey('42')) await write.promise;
          return storageSetItem(key, value);
        });
      let unmounted = false;
      try {
        await act(async () => {
          await journey.current().submit();
        });
        let preparing!: Promise<void>;
        await act(async () => {
          preparing = journey.current().reviewUpdatedProject();
          await flush();
        });
        expect(writes.mock.calls.some(call => call[0] === draftKey('42'))).toBe(
          true,
        );
        if (change === 'account replacement')
          mockBoundary = {scope: 'learner-b', epoch: 2};
        else {
          await act(async () => journey.renderer.unmount());
          unmounted = true;
        }
        await act(async () => {
          write.resolve();
          await preparing;
        });
        expect(observer).not.toHaveBeenCalled();
        expect(
          (await AsyncStorage.getAllKeys()).some(key =>
            key.includes('learner-b'),
          ),
        ).toBe(false);
        expect(
          JSON.parse((await AsyncStorage.getItem(draftKey('41'))) || '{}'),
        ).toMatchObject({note, files: [file]});
        expect(journey.onSubmit).toHaveBeenCalledTimes(1);
      } finally {
        write.resolve();
        writes.mockRestore();
        unsubscribe();
        if (!unmounted) await act(async () => journey.renderer.unmount());
      }
    },
  );

  it.each([false, true])(
    'retires a mounted editor conflict confirmation after leaving its active visit (returned: %s)',
    async returned => {
      await saveProjectSubmissionDraft(
        '41',
        {note, files: [file], updatedAt: Date.now()},
        mockBoundary,
      );
      await saveProjectSubmissionDraft(
        '42',
        {note: 'مسودة الوجهة', files: [], updatedAt: Date.now()},
        mockBoundary,
      );
      const observer = jest.fn();
      const unsubscribe = subscribeCourseRevisionChanges(observer);
      const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
      const journey = await mountEditor();
      try {
        await act(async () => {
          await journey.current().submit();
        });
        await act(async () => {
          await journey.current().reviewUpdatedProject();
        });
        expect(alert).toHaveBeenCalledTimes(1);
        const oldConfirmation = alert.mock.calls[0][2]?.[1].onPress;
        await act(async () => {
          journey.setActive(false);
        });
        if (returned)
          await act(async () => {
            journey.setActive(true);
          });
        await act(async () => {
          oldConfirmation?.();
          await flush();
        });
        expect(observer).not.toHaveBeenCalled();
        expect(
          await loadProjectSubmissionDraft('42', mockBoundary),
        ).toMatchObject({note: 'مسودة الوجهة'});
        expect(
          await loadProjectSubmissionDraft('41', mockBoundary),
        ).toMatchObject({note, files: [file]});
        expect(journey.onSubmit).toHaveBeenCalledTimes(1);
        if (returned) {
          await act(async () => {
            await journey.current().reviewUpdatedProject();
          });
          expect(alert).toHaveBeenCalledTimes(2);
          await act(async () => {
            alert.mock.calls[1][2]?.[1].onPress?.();
            await flush();
          });
          expect(observer).toHaveBeenCalledTimes(1);
          expect(
            await loadProjectSubmissionDraft('42', mockBoundary),
          ).toMatchObject({note, files: [file]});
          expect(journey.onSubmit).toHaveBeenCalledTimes(1);
        }
      } finally {
        alert.mockRestore();
        unsubscribe();
        await act(async () => journey.renderer.unmount());
      }
    },
  );

  it('does not apply a conflict confirmation after the account was replaced', async () => {
    await saveProjectSubmissionDraft(
      '41',
      {note, files: [file], updatedAt: Date.now()},
      mockBoundary,
    );
    await saveProjectSubmissionDraft(
      '42',
      {note: 'مسودة الوجهة', files: [], updatedAt: Date.now()},
      mockBoundary,
    );
    const observer = jest.fn();
    const unsubscribe = subscribeCourseRevisionChanges(observer);
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    const journey = await mountEditor();
    try {
      await act(async () => {
        await journey.current().submit();
      });
      await act(async () => {
        await journey.current().reviewUpdatedProject();
      });
      expect(alert).toHaveBeenCalledTimes(1);
      mockBoundary = {scope: 'learner-b', epoch: 2};
      await act(async () => {
        alert.mock.calls[0][2]?.[1].onPress?.();
        await flush();
      });
      expect(observer).not.toHaveBeenCalled();
      expect(
        JSON.parse((await AsyncStorage.getItem(draftKey('42'))) || '{}'),
      ).toMatchObject({note: 'مسودة الوجهة'});
    } finally {
      alert.mockRestore();
      unsubscribe();
      await act(async () => journey.renderer.unmount());
    }
  });

  it('does not leave a removed project until its latest editor snapshot is durable', async () => {
    await saveProjectSubmissionDraft(
      '41',
      {note, files: [file], updatedAt: Date.now()},
      mockBoundary,
    );
    const observer = jest.fn();
    const unsubscribe = subscribeCourseRevisionChanges(observer);
    const journey = await mountEditor(updatedProject(null));
    try {
      await act(async () => {
        await journey.current().submit();
      });
      act(() =>
        journey.current().changeNote('تعديل أخير قبل فتح الكورس المحدّث'),
      );
      jest
        .mocked(AsyncStorage.setItem)
        .mockRejectedValueOnce(new Error('disk'));
      await act(async () => {
        await journey.current().reviewUpdatedProject();
      });
      expect(observer).not.toHaveBeenCalled();
      expect(journey.current().revisionMessage).toContain(
        'تعذّر تجهيز المسودة',
      );
      await act(async () => {
        await journey.current().reviewUpdatedProject();
      });
      expect(observer).toHaveBeenCalledTimes(1);
      expect(
        await loadProjectSubmissionDraft('41', mockBoundary),
      ).toMatchObject({
        note: 'تعديل أخير قبل فتح الكورس المحدّث',
        files: [file],
      });
    } finally {
      unsubscribe();
      await act(async () => journey.renderer.unmount());
    }
  });
});
