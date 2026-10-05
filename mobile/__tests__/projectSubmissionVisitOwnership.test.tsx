import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Alert, NativeModules, Platform} from 'react-native';
import RNFS from 'react-native-fs';

let mockBoundary = {scope: 'user-a', epoch: 1};
const mockLoad = jest.fn();
const mockSave = jest.fn();
const mockClear = jest.fn();
const mockConsent = jest.fn();
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({...mockBoundary})),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary.scope !== mockBoundary.scope || boundary.epoch !== mockBoundary.epoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/services/aiConsent', () => ({
  requestAiConsent: (...args: unknown[]) => mockConsent(...args),
  isAiConsentRequired: () => false,
}));
jest.mock('../src/services/projectSubmissionDraft', () => ({
  cacheProjectDraftFile: jest.fn(async file => file),
  loadProjectSubmissionDraft: (...args: unknown[]) => mockLoad(...args),
  saveProjectSubmissionDraft: (...args: unknown[]) => mockSave(...args),
  clearProjectSubmissionDraft: (...args: unknown[]) => mockClear(...args),
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/projectTransition/pickers', () => ({
  pickProjectFilesOwned: jest.fn(),
}));

import {useProjectSubmission} from '../src/components/VideoPlayer/projectTransition/useProjectSubmission';
import type {CourseProject, SelectedProjectFile} from '../src/components/VideoPlayer/types';
import type {ProjectSubmissionOutcome} from '../src/components/VideoPlayer/courseLearningApi';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: Error) => void;
  const promise = new Promise<T>((yes, no) => {resolve = yes; reject = no;});
  return {promise, resolve, reject};
};
const file: SelectedProjectFile = {
  uri: 'file:///work.png', name: 'work.png', type: 'image/png', size: 100,
};
const note = 'نفذت المشروع وهذه محاولتي';
const outcome: ProjectSubmissionOutcome = {
  accepted: true, submissionStatus: 'evaluating', canContinue: false,
};
const project = (id: string): CourseProject => ({
  id, sectionId: `section-${id}`, moduleId: 'module-1',
  title: 'مشروع العبور', requirements: 'نفذ المشروع', status: 'draft',
  isGraduationProject: false, canSubmit: true, canContinue: false,
  submissionTextEnabled: true, submissionFilesEnabled: true,
  submissionAllowedMimeTypes: ['image/png'],
});

describe('project submission preparation versus durable delivery', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let current: ReturnType<typeof useProjectSubmission>;
  const onSubmit = jest.fn<Promise<ProjectSubmissionOutcome>, [SelectedProjectFile[], string?]>();
  const onOutcome = jest.fn();
  const originalInspector = NativeModules.RoknMediaInspector;

  function Harness({id = '41', active = true, appIsActive = true}: {
    id?: string; active?: boolean; appIsActive?: boolean;
  }) {
    current = useProjectSubmission({
      active, appIsActive, project: project(id), status: 'draft',
      submissionAllowed: true, onSubmit, onOutcome,
    });
    return null;
  }
  const mount = async () => {
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
  };
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-a', epoch: 1};
    mockLoad.mockReset().mockResolvedValue({files: [file], note, updatedAt: 1});
    mockSave.mockReset().mockResolvedValue(undefined);
    mockClear.mockReset().mockResolvedValue(undefined);
    mockConsent.mockReset().mockResolvedValue(true);
    onSubmit.mockReset().mockResolvedValue(outcome);
    jest.replaceProperty(Platform, 'OS', 'android');
    NativeModules.RoknMediaInspector = {inspect: jest.fn(async () => ({isBlank: false}))};
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
  });
  afterEach(async () => {
    await act(async () => {renderer?.unmount();});
    renderer = undefined;
    NativeModules.RoknMediaInspector = originalInspector;
    jest.restoreAllMocks();
    jest.useRealTimers();
  });

  it.each(['validation', 'inspection', 'draft save', 'consent'])(
    'retires %s on departure without sending or reopening it on return', async stage => {
      const held = deferred<unknown>();
      if (stage === 'validation') {
        mockLoad.mockResolvedValue({files: [{...file, size: undefined}], note, updatedAt: 1});
        jest.spyOn(RNFS, 'stat').mockImplementation(() => held.promise as ReturnType<typeof RNFS.stat>);
      } else if (stage === 'inspection') {
        NativeModules.RoknMediaInspector.inspect.mockReturnValueOnce(held.promise);
      } else if (stage === 'draft save') mockSave.mockReturnValueOnce(held.promise);
      else mockConsent.mockReturnValueOnce(held.promise);
      await mount();
      let sending!: Promise<void>;
      await act(async () => {sending = current.submit();});
      expect(current.sending).toBe(true);
      await act(async () => {renderer!.update(<Harness active={false} />);});
      expect(current.sending).toBe(false);
      await act(async () => {renderer!.update(<Harness />);});
      await act(async () => {
        held.resolve(stage === 'validation' ? {size: 100} : stage === 'inspection' ? {isBlank: true} : true);
        await sending;
      });
      expect(onSubmit).not.toHaveBeenCalled();
      expect(onOutcome).not.toHaveBeenCalled();
      expect(mockClear).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalled();
      expect(current.note).toBe(note);
      expect(current.selectedFiles).toHaveLength(1);
      // A new explicit tap belongs to the new visit, never to the old continuation.
      await act(async () => {await current.submit();});
      expect(onSubmit).toHaveBeenCalledTimes(1);
    },
  );

  it.each(['background', 'unmount', 'different project', 'different account'])(
    'does not dispatch after %s while consent is pending', async departure => {
      const held = deferred<boolean>();
      mockConsent.mockReturnValueOnce(held.promise);
      await mount();
      let sending!: Promise<void>;
      await act(async () => {sending = current.submit();});
      const owns = mockConsent.mock.calls[0][1] as () => boolean;
      expect(owns()).toBe(true);
      await act(async () => {
        if (departure === 'background') renderer!.update(<Harness appIsActive={false} />);
        else if (departure === 'unmount') renderer!.unmount();
        else if (departure === 'different project') renderer!.update(<Harness id="42" />);
        else mockBoundary = {scope: 'user-b', epoch: 2};
      });
      expect(owns()).toBe(false);
      await act(async () => {held.resolve(true); await sending;});
      expect(onSubmit).not.toHaveBeenCalled();
      expect(mockClear).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalled();
    },
  );

  it('an old preparation finally cannot unlock a new visit delivery', async () => {
    const preparation = deferred<boolean>();
    const receipt = deferred<ProjectSubmissionOutcome>();
    mockConsent.mockReturnValueOnce(preparation.promise);
    onSubmit.mockReturnValueOnce(receipt.promise);
    await mount();
    let oldSending!: Promise<void>;
    await act(async () => {oldSending = current.submit();});
    await act(async () => {renderer!.update(<Harness active={false} />);});
    await act(async () => {renderer!.update(<Harness />);});
    let newSending!: Promise<void>;
    await act(async () => {newSending = current.submit();});
    expect(onSubmit).toHaveBeenCalledTimes(1);
    await act(async () => {preparation.resolve(true); await oldSending;});
    expect(current.sending).toBe(true);
    await act(async () => {await current.submit();});
    expect(onSubmit).toHaveBeenCalledTimes(1);
    await act(async () => {receipt.resolve(outcome); await newSending;});
    expect(current.sending).toBe(false);
    expect(mockClear).toHaveBeenCalledTimes(1);
  });

  it('retains the delivery flight across a leave and return and consumes its real receipt', async () => {
    const receipt = deferred<ProjectSubmissionOutcome>();
    onSubmit.mockReturnValueOnce(receipt.promise);
    await mount();
    let sending!: Promise<void>;
    await act(async () => {sending = current.submit();});
    await act(async () => {renderer!.update(<Harness active={false} />);});
    expect(current.sending).toBe(true);
    await act(async () => {renderer!.update(<Harness />);});
    await act(async () => {await current.submit();});
    expect(onSubmit).toHaveBeenCalledTimes(1);
    await act(async () => {receipt.resolve(outcome); await sending;});
    expect(onOutcome).toHaveBeenCalledWith(outcome);
    expect(mockClear).toHaveBeenCalledWith('41', [file], {scope: 'user-a', epoch: 1});
    expect(current.note).toBe('');
    expect(current.sending).toBe(false);
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it.each(['uncertain receipt', 'transport failure'])(
    'preserves the draft without a late alert for %s after departure', async result => {
      const receipt = deferred<ProjectSubmissionOutcome>();
      onSubmit.mockReturnValueOnce(receipt.promise);
      await mount();
      let sending!: Promise<void>;
      await act(async () => {sending = current.submit();});
      await act(async () => {renderer!.update(<Harness active={false} />);});
      await act(async () => {
        if (result === 'uncertain receipt') receipt.resolve({...outcome, accepted: false, submissionStatus: 'draft'});
        else receipt.reject(new Error('NETWORK_ERROR'));
        await sending;
      });
      expect(mockClear).not.toHaveBeenCalled();
      expect(current.note).toBe(note);
      expect(Alert.alert).not.toHaveBeenCalled();
      expect(current.sending).toBe(false);
    },
  );

  it.each([false, true])(
    'applies an accepted receipt while away respecting preserveDraft=%s', async preserveDraft => {
      const receipt = deferred<ProjectSubmissionOutcome>();
      onSubmit.mockReturnValueOnce(receipt.promise);
      await mount();
      let sending!: Promise<void>;
      await act(async () => {sending = current.submit();});
      await act(async () => {renderer!.update(<Harness active={false} />);});
      const accepted = {...outcome, preserveDraft};
      await act(async () => {receipt.resolve(accepted); await sending;});
      expect(onOutcome).toHaveBeenCalledWith(accepted);
      expect(current.note).toBe(preserveDraft ? note : '');
      expect(mockClear).toHaveBeenCalledTimes(preserveDraft ? 0 : 1);
      expect(current.sending).toBe(false);
      expect(Alert.alert).not.toHaveBeenCalled();
    },
  );

  it('rejects an inactive initial action and a replacement account receipt', async () => {
    const receipt = deferred<ProjectSubmissionOutcome>();
    onSubmit.mockReturnValueOnce(receipt.promise);
    await mount();
    await act(async () => {renderer!.update(<Harness appIsActive={false} />);});
    expect(current.submitDisabled).toBe(true);
    await act(async () => {await current.submit();});
    expect(mockConsent).not.toHaveBeenCalled();
    await act(async () => {renderer!.update(<Harness />);});
    let sending!: Promise<void>;
    await act(async () => {sending = current.submit();});
    mockBoundary = {scope: 'user-b', epoch: 2};
    await act(async () => {receipt.resolve(outcome); await sending;});
    expect(onOutcome).not.toHaveBeenCalled();
    expect(mockClear).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  });
});
