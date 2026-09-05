import AsyncStorage from '@react-native-async-storage/async-storage';

let mockActiveBoundary = {epoch: 1, scope: 'user-a'};
const mockCaptureBoundary = jest.fn(async () => ({...mockActiveBoundary}));
const mockLearnerDraftFileIsReadable = jest.fn(async (_file?: unknown) => true);

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (base: string, boundary?: {scope: string}) =>
      `${base}:${boundary?.scope || mockActiveBoundary.scope}`,
  ),
  captureAccountSessionBoundary: () => mockCaptureBoundary(),
  assertAccountSessionBoundary: (boundary: {epoch: number; scope: string}) => {
    if (
      boundary.epoch !== mockActiveBoundary.epoch ||
      boundary.scope !== mockActiveBoundary.scope
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));

jest.mock('../src/services/learnerDraftFiles', () => ({
  cacheLearnerDraftFile: jest.fn(),
  learnerDraftFileIsReadable: (file?: unknown) =>
    mockLearnerDraftFileIsReadable(file),
  removeLearnerDraftFile: jest.fn(async () => undefined),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
}));

import {
  loadProjectSubmissionDraft,
  saveProjectSubmissionDraft,
} from '../src/services/projectSubmissionDraft';
import {
  loadProjectFeedbackDraft,
  saveProjectFeedbackDraft,
} from '../src/services/projectFeedbackDraft';

describe('project draft ownership', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    mockActiveBoundary = {epoch: 1, scope: 'user-a'};
    mockLearnerDraftFileIsReadable.mockResolvedValue(true);
    await AsyncStorage.clear();
  });

  it('never moves a submission draft to the account active at cleanup time', async () => {
    const owner = {...mockActiveBoundary};
    await saveProjectSubmissionDraft(
      '42',
      {note: 'محاولة الحساب الأول', updatedAt: Date.now()},
      owner,
    );

    mockActiveBoundary = {epoch: 2, scope: 'user-b'};
    await expect(
      saveProjectSubmissionDraft(
        '42',
        {note: 'لا تكتب هذه', updatedAt: Date.now()},
        owner,
      ),
    ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');

    expect(
      (await AsyncStorage.getAllKeys()).filter(key => key.includes('user-b')),
    ).toEqual([]);
    expect(mockCaptureBoundary).not.toHaveBeenCalled();
  });

  it('loads and saves the feedback draft through one captured owner', async () => {
    const owner = {...mockActiveBoundary};
    const threadId = '11111111-1111-4111-8111-111111111111';
    await saveProjectFeedbackDraft(
      threadId,
      {text: 'راجع هذه النقطة', attachments: [], updatedAt: Date.now()},
      owner,
    );

    await expect(loadProjectFeedbackDraft(threadId, owner)).resolves.toEqual(
      expect.objectContaining({text: 'راجع هذه النقطة'}),
    );
    await expect(loadProjectSubmissionDraft('42', owner)).resolves.toBeNull();
    expect(mockCaptureBoundary).not.toHaveBeenCalled();
  });

  it('keeps an uploaded feedback attachment after restart without a local file', async () => {
    const owner = {...mockActiveBoundary};
    const threadId = '22222222-2222-4222-8222-222222222222';
    const attachment = {
      uri: '',
      name: 'المشروع.pdf',
      type: 'application/pdf',
      size: 1024,
      uploadId: 'local-upload',
      serverId: 'server-attachment',
    };
    mockLearnerDraftFileIsReadable.mockResolvedValue(false);

    await saveProjectFeedbackDraft(
      threadId,
      {text: 'راجع المرفق', attachments: [attachment], updatedAt: Date.now()},
      owner,
    );

    await expect(loadProjectFeedbackDraft(threadId, owner)).resolves.toEqual(
      expect.objectContaining({attachments: [attachment]}),
    );
    expect(mockLearnerDraftFileIsReadable).not.toHaveBeenCalled();
  });
});
