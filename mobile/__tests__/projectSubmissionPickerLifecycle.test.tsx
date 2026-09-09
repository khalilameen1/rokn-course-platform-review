import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import * as DocumentPicker from 'expo-document-picker';

let mockBoundary = {scope: 'user-a', epoch: 1};
const mockCapture = jest.fn(async () => ({...mockBoundary}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: () => mockCapture(),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('expo-document-picker', () => ({getDocumentAsync: jest.fn()}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));
jest.mock('../src/services/projectSubmissionDraft', () => ({
  cacheProjectDraftFile: jest.fn(async file => file),
  clearProjectSubmissionDraft: jest.fn(async () => undefined),
  loadProjectSubmissionDraft: jest.fn(async () => null),
  saveProjectSubmissionDraft: jest.fn(async () => undefined),
}));
jest.mock('../src/services/mediaPickerErrors', () => ({
  showMediaPickerFailure: jest.fn(),
}));

import {useProjectSubmission} from '../src/components/VideoPlayer/projectTransition/useProjectSubmission';
import type {CourseProject} from '../src/components/VideoPlayer/types';
import {cacheProjectDraftFile} from '../src/services/projectSubmissionDraft';
import {removeLearnerDraftFile} from '../src/services/learnerDraftFiles';
import {showMediaPickerFailure} from '../src/services/mediaPickerErrors';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const project = (id = '41'): CourseProject => ({
  id,
  sectionId: `section-${id}`,
  moduleId: 'module-1',
  title: 'مشروع العبور',
  requirements: 'نفذ المشروع ثم ارفعه',
  status: 'draft',
  isGraduationProject: false,
  canSubmit: true,
  canContinue: false,
  submissionTextEnabled: true,
  submissionFilesEnabled: true,
  submissionAllowedMimeTypes: ['application/pdf'],
});

describe('project submission native picker visit ownership', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let current: ReturnType<typeof useProjectSubmission>;
  const onSubmit = jest.fn();

  function Harness({
    id = '41',
    active = true,
  }: {
    id?: string;
    active?: boolean;
  }) {
    current = useProjectSubmission({
      active,
      appIsActive: true,
      project: project(id),
      status: 'draft',
      submissionAllowed: true,
      onSubmit,
      onOutcome: jest.fn(),
    });
    return null;
  }

  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-a', epoch: 1};
    mockCapture.mockReset().mockImplementation(async () => ({...mockBoundary}));
    jest
      .mocked(cacheProjectDraftFile)
      .mockReset()
      .mockImplementation(async file => file);
    jest.mocked(DocumentPicker.getDocumentAsync).mockResolvedValue({
      canceled: true,
      assets: null,
    });
  });

  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it.each(['unmount', 'different project', 'leave card', 'leave and return'])(
    'does not open the picker after %s while session capture is pending',
    async transition => {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      const capture = deferred<typeof mockBoundary>();
      mockCapture.mockReturnValueOnce(capture.promise);
      let picking!: Promise<void>;
      await act(async () => {
        picking = current.chooseProjectFile();
      });
      await act(async () => {
        if (transition === 'unmount') renderer!.unmount();
        else if (transition === 'different project')
          renderer!.update(<Harness id="42" />);
        else renderer!.update(<Harness active={false} />);
      });
      if (transition === 'leave and return') {
        await act(async () => renderer!.update(<Harness />));
      }
      await act(async () => {
        capture.resolve({...mockBoundary});
        await picking;
      });

      expect(DocumentPicker.getDocumentAsync).not.toHaveBeenCalled();
      expect(onSubmit).not.toHaveBeenCalled();
    },
  );

  it('opens once for a current explicit tap and allows retry after cancellation', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    const capture = deferred<typeof mockBoundary>();
    mockCapture.mockReturnValueOnce(capture.promise);
    let picking!: Promise<void>;
    await act(async () => {
      picking = current.chooseProjectFile();
      void current.chooseProjectFile();
    });
    await act(async () => {
      capture.resolve({...mockBoundary});
      await picking;
    });
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(1);
    await act(async () => current.chooseProjectFile());
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(2);
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('does not cache a native result belonging to a card already left', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    const selection = deferred<DocumentPicker.DocumentPickerResult>();
    jest
      .mocked(DocumentPicker.getDocumentAsync)
      .mockReturnValueOnce(selection.promise);
    let picking!: Promise<void>;
    await act(async () => {
      picking = current.chooseProjectFile();
    });
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(1);
    await act(async () => renderer!.update(<Harness active={false} />));
    await act(async () => {
      selection.resolve({
        canceled: false,
        assets: [
          {
            uri: 'file:///work.pdf',
            name: 'work.pdf',
            mimeType: 'application/pdf',
            size: 100,
            lastModified: 1,
          },
        ],
      });
      await picking;
    });
    expect(cacheProjectDraftFile).not.toHaveBeenCalled();
    expect(current.selectedFiles).toEqual([]);
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('does not open a picker when the original account changes during capture', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    const original = {...mockBoundary};
    const capture = deferred<typeof mockBoundary>();
    mockCapture.mockReturnValueOnce(capture.promise);
    let picking!: Promise<void>;
    await act(async () => {
      picking = current.chooseProjectFile();
    });
    mockBoundary = {scope: 'user-b', epoch: 2};
    await act(async () => {
      capture.resolve(original);
      await picking;
    });
    expect(DocumentPicker.getDocumentAsync).not.toHaveBeenCalled();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('keeps a current selection on native failure and permits another explicit attempt', async () => {
    const selected = {
      uri: 'file:///work.pdf',
      name: 'work.pdf',
      type: 'application/pdf',
      size: 100,
    };
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    jest.mocked(DocumentPicker.getDocumentAsync).mockResolvedValueOnce({
      canceled: false,
      assets: [{...selected, mimeType: selected.type, lastModified: 1}],
    });
    await act(async () => current.chooseProjectFile());
    expect(current.selectedFiles).toEqual([selected]);
    jest
      .mocked(DocumentPicker.getDocumentAsync)
      .mockRejectedValueOnce(new Error('Native picker failed'));
    await act(async () => current.chooseProjectFile());
    expect(current.selectedFiles).toEqual([selected]);
    expect(showMediaPickerFailure).toHaveBeenCalledWith(
      'document_picker_failed',
    );
    await act(async () => current.chooseProjectFile());
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(3);
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('retires a late copied file without adopting it after leaving and returning', async () => {
    const selected = {
      uri: 'file:///work.pdf',
      name: 'work.pdf',
      type: 'application/pdf',
      size: 100,
    };
    const copy = deferred<typeof selected>();
    jest.mocked(cacheProjectDraftFile).mockReturnValueOnce(copy.promise);
    jest.mocked(DocumentPicker.getDocumentAsync).mockResolvedValueOnce({
      canceled: false,
      assets: [{...selected, mimeType: selected.type, lastModified: 1}],
    });
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    let picking!: Promise<void>;
    await act(async () => {
      picking = current.chooseProjectFile();
    });
    expect(cacheProjectDraftFile).toHaveBeenCalledTimes(1);
    await act(async () => renderer!.update(<Harness active={false} />));
    await act(async () => renderer!.update(<Harness />));
    const cached = {...selected, uri: 'file:///drafts/work.pdf'};
    await act(async () => {
      copy.resolve(cached);
      await picking;
    });
    expect(current.selectedFiles).toEqual([]);
    expect(jest.mocked(removeLearnerDraftFile).mock.calls[0]?.[0]).toEqual(
      cached,
    );
    await act(async () => current.chooseProjectFile());
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(2);
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('does not open an inactive project card through a retained action', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness active={false} />);
    });
    await act(async () => current.chooseProjectFile());
    expect(DocumentPicker.getDocumentAsync).not.toHaveBeenCalled();
  });
});
