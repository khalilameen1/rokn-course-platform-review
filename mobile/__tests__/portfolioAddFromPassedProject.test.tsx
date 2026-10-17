import React from 'react';
import {Alert} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import TestRenderer, {act} from 'react-test-renderer';

const mockPicker = jest.fn();
const mockCacheFile = jest.fn();
const mockLoadSubmissionDraft = jest.fn();
const mockBoundary = {epoch: 1, scope: 'portfolio-handoff-owner'};

jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  assertAccountSessionBoundary: () => undefined,
  accountScopedStorageKey: async (key: string, boundary: {scope: string}) =>
    `${key}:${boundary.scope}`,
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: async () => true,
  retainLearnerDraftFiles: async () => undefined,
  removeLearnerDraftFile: async () => undefined,
  cacheLearnerDraftFile: (...args: unknown[]) => mockCacheFile(...args),
}));
jest.mock('../src/services/projectSubmissionDraft', () => ({
  loadProjectSubmissionDraft: (...args: unknown[]) =>
    mockLoadSubmissionDraft(...args),
}));
jest.mock('react-native-image-picker', () => ({
  launchImageLibrary: (...args: unknown[]) => mockPicker(...args),
}));
jest.mock('../src/services/roknApi', () => ({
  assertPortfolioUploadAccess: async () => undefined,
  getEligibleProjects: async () => [
    {
      projectId: '32',
      courseId: '3',
      title: 'هوية لمقهى',
      summary: 'نفذ هوية كاملة لمقهى محلي حسب الشروط',
      courseName: 'الهوية البصرية',
    },
  ],
  createPortfolioItem: jest.fn(),
}));
jest.mock('../src/services/portfolioMediaUpload', () => ({
  stagePortfolioMediaFiles: jest.fn(),
  uploadPortfolioMediaFiles: jest.fn(),
}));

import {usePortfolioCreateFlow} from '../src/screens/Profile/gallery/usePortfolioCreateFlow';

const flush = async () => {
  for (let index = 0; index < 40; index += 1) await Promise.resolve();
};
const captureBoundary = async () => mockBoundary;
const cancelLibraryLoad = jest.fn();
const isDetailBusy = () => false;
const onMediaUploaded = jest.fn();
const reconcileProject = jest.fn();
const setLibraryProjects = jest.fn();
const finalizeAfterUpload = jest.fn();

describe('add to portfolio after passing a project (Domestika share-project flow)', () => {
  let flow!: ReturnType<typeof usePortfolioCreateFlow>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const mountedRef = {current: true};
  const busyRef = {current: false};
  const onHandled = jest.fn();
  const Harness = ({sourceId}: {sourceId?: string}) => {
    flow = usePortfolioCreateFlow({
      appActive: true,
      busyRef,
      mountedRef,
      captureBoundary,
      cancelLibraryLoad,
      finalizeAfterUpload,
      isDetailBusy,
      onMediaUploaded,
      reconcileProject,
      serverSession: true,
      setLibraryProjects,
      addFromProjectId: sourceId,
      onAddFromProjectHandled: onHandled,
    });
    return null;
  };
  const mount = async (sourceId?: string) => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness sourceId={sourceId} />);
      await flush();
    });
    await act(async () => {
      await flush();
    });
  };

  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mountedRef.current = true;
    busyRef.current = false;
    await AsyncStorage.clear();
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    mockPicker.mockResolvedValue({didCancel: true});
    mockLoadSubmissionDraft.mockResolvedValue(null);
    mockCacheFile.mockImplementation(async (_kind, file) => ({
      uri: `file:///portfolio/${file.fileName}`,
      type: file.type,
      fileName: file.fileName,
      size: file.size,
    }));
  });
  afterEach(async () => {
    mountedRef.current = false;
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('chooses the passed project, leaves the description empty and opens Photos once the sheet is shown', async () => {
    await mount('32');
    expect(onHandled).toHaveBeenCalledTimes(1);
    expect(flow.adding).toBe(true);
    expect(flow.selectedSourceProject?.projectId).toBe('32');
    expect(flow.draftTitle).toBe('هوية لمقهى');
    expect(flow.draftSummary).toBe('');
    // iOS cannot present the picker over a sheet that is still animating.
    expect(mockPicker).not.toHaveBeenCalled();
    await act(async () => {
      flow.markAddSheetShown();
      await flush();
    });
    expect(mockPicker).toHaveBeenCalledTimes(1);
    expect(mockPicker).toHaveBeenCalledWith(
      expect.objectContaining({maxWidth: 2560, maxHeight: 2560, quality: 0.8}),
    );
  });

  it('preselects submitted images still on the device instead of asking again', async () => {
    mockLoadSubmissionDraft.mockResolvedValue({
      note: '',
      updatedAt: Date.now(),
      files: [
        {uri: 'file:///project/a.jpg', name: 'a.jpg', type: 'image/jpeg', size: 10},
        {uri: 'file:///project/brief.pdf', name: 'brief.pdf', type: 'application/pdf'},
      ],
    });
    await mount('32');
    await act(async () => {
      flow.markAddSheetShown();
      await flush();
    });
    expect(mockLoadSubmissionDraft).toHaveBeenCalledWith('32', mockBoundary);
    expect(flow.draftMediaAssets).toEqual([
      {uri: 'file:///portfolio/a.jpg', type: 'image/jpeg', fileName: 'a.jpg', size: 10},
    ]);
    expect(mockPicker).not.toHaveBeenCalled();
  });

  it('keeps what the learner wrote but never copies the teacher brief', async () => {
    await mount();
    await act(async () => {
      flow.openAddProject();
      await flush();
    });
    await act(async () => {
      flow.updateDraftSummary('صممت الشعار والألوان');
      await flush();
    });
    await act(async () => {
      flow.chooseSourceProject(flow.eligibleProjects[0]);
      await flush();
    });
    expect(flow.draftSummary).toBe('صممت الشعار والألوان');
    await act(async () => {
      flow.updateDraftSummary('نفذ هوية كاملة لمقهى محلي حسب الشروط');
      await flush();
    });
    await act(async () => {
      flow.chooseSourceProject(flow.eligibleProjects[0]);
      await flush();
    });
    expect(flow.draftSummary).toBe('');
  });
});
