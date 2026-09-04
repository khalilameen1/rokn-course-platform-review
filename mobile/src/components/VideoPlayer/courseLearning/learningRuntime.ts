import {getCurrentAccountStorageScope} from '../../../constants/helpers';
import {clearAccountLearnerDraftFiles} from '../../../services/learnerDraftFiles';
import {resetPlayerStateRuntime} from './persistence';
import {resetPlaybackRuntimeState} from './playback';
import {quiesceProjectSubmissionRuntime} from './projectSubmissionOutbox';

export const quiesceLearningRuntime = () => {
  resetPlaybackRuntimeState();
  resetPlayerStateRuntime();
  quiesceProjectSubmissionRuntime();
};

export const clearCurrentAccountLearningFiles = async (
  accountScope?: string,
) => {
  quiesceLearningRuntime();
  const scope = accountScope || (await getCurrentAccountStorageScope());
  if (!/^[a-z0-9_-]+$/i.test(scope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }
  await clearAccountLearnerDraftFiles(scope);
};
