import * as DocumentPicker from 'expo-document-picker';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {showMediaPickerFailure} from '../../../services/mediaPickerErrors';
import type {SelectedProjectFile} from '../types';

export const pickProjectFilesOwned = async (
  mimeTypes: string[],
): Promise<{
  files: SelectedProjectFile[];
  ownerBoundary: AccountSessionBoundary;
}> => {
  const ownerBoundary = await captureAccountSessionBoundary();
  assertAccountSessionBoundary(ownerBoundary);
  try {
    const response = await DocumentPicker.getDocumentAsync({
      type: mimeTypes.length
        ? mimeTypes
        : [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
            'text/plain',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
          ],
      multiple: true,
      copyToCacheDirectory: true,
    });
    assertAccountSessionBoundary(ownerBoundary);
    return {
      files: response.canceled
        ? []
        : response.assets
            .filter(asset => asset.uri)
            .map(asset => ({
              uri: asset.uri,
              name: asset.name || `rokn-project-${Date.now()}`,
              type: asset.mimeType || 'application/octet-stream',
              size: asset.size,
            })),
      ownerBoundary,
    };
  } catch (error) {
    if (
      error instanceof Error &&
      error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
    ) {
      throw error;
    }
    showMediaPickerFailure('document_picker_failed');
    return {files: [], ownerBoundary};
  }
};

export const pickProjectFiles = async (
  mimeTypes: string[],
): Promise<SelectedProjectFile[]> =>
  (await pickProjectFilesOwned(mimeTypes)).files;

export const pickMedia = async (): Promise<SelectedProjectFile | null> =>
  (await pickProjectFiles([]))[0] || null;
