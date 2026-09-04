import {Alert} from 'react-native';
import {launchImageLibrary, PhotoQuality} from 'react-native-image-picker';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {
  cacheLearnerDraftFile,
  removeLearnerDraftFile,
} from '../../services/learnerDraftFiles';
import {showMediaPickerFailure} from '../../services/mediaPickerErrors';
import type {FeedbackAttachment} from '../../services/productFeedback';

const MAX_SCREENSHOT_BYTES = 4 * 1024 * 1024;

export const pickFeedbackScreenshot = async (): Promise<
  FeedbackAttachment | undefined
> => {
  let cachedSelection: FeedbackAttachment | undefined;
  try {
    const boundary = await captureAccountSessionBoundary();
    assertAccountSessionBoundary(boundary);
    const result = await launchImageLibrary({
      mediaType: 'photo',
      quality: 0.8 as PhotoQuality,
      selectionLimit: 1,
    });
    assertAccountSessionBoundary(boundary);
    if (result.didCancel) return undefined;
    if (result.errorCode) {
      showMediaPickerFailure(result.errorCode);
      return undefined;
    }

    const asset = result.assets?.[0];
    if (!asset?.uri) return undefined;
    if (Number(asset.fileSize || 0) > MAX_SCREENSHOT_BYTES) {
      Alert.alert('الصورة كبيرة', 'اختر صورة أصغر من ٤ ميجابايت');
      return undefined;
    }

    cachedSelection = await cacheLearnerDraftFile(
      'feedback',
      {
        fileName: asset.fileName,
        size: asset.fileSize,
        type: asset.type,
        uri: asset.uri,
      },
      MAX_SCREENSHOT_BYTES,
      boundary,
    );
    assertAccountSessionBoundary(boundary);

    return cachedSelection;
  } catch (error: unknown) {
    await removeLearnerDraftFile(cachedSelection).catch(() => undefined);
    if (
      error instanceof Error &&
      error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
    ) {
      return undefined;
    }
    showMediaPickerFailure(
      typeof error === 'object' && error && 'errorCode' in error
        ? String(error.errorCode)
        : undefined,
    );
    return undefined;
  }
};
