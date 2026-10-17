import * as DocumentPicker from 'expo-document-picker';
import {ActionSheetIOS, Alert, Platform} from 'react-native';
import {
  launchCamera,
  launchImageLibrary,
  type Asset,
  type CameraOptions,
  type ImageLibraryOptions,
  type ImagePickerResponse,
} from 'react-native-image-picker';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {showMediaPickerFailure} from '../../../services/mediaPickerErrors';
import type {SelectedProjectFile} from '../types';

/** Every format the backend project catalogue accepts (config/projects.php). */
export const PROJECT_SUBMISSION_MIME_TYPES = [
  'image/jpeg',
  'image/png',
  'image/webp',
  'application/pdf',
  'text/plain',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];
const PROJECT_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export type ProjectFileSource = 'photos' | 'camera' | 'files';

/**
 * The backend inspects images up to 12 MP and 8 MB and excludes HEIC. Like
 * the iOS PHPicker / Android photo picker attach flows in Mail and WhatsApp,
 * photos are delivered as compatible JPEG and downscaled before upload:
 * `assetRepresentationMode: 'compatible'` and quality < 1 make
 * react-native-image-picker transcode HEIC to JPEG on iOS, and Android
 * converts HEIC/HEIF to JPEG while resizing.
 */
export const PROJECT_PHOTO_OPTIONS = {
  mediaType: 'photo',
  maxWidth: 2560,
  maxHeight: 2560,
  quality: 0.8,
  assetRepresentationMode: 'compatible',
} as const satisfies Partial<ImageLibraryOptions & CameraOptions>;

const effectiveMimeTypes = (mimeTypes: string[]) =>
  mimeTypes.length ? mimeTypes : PROJECT_SUBMISSION_MIME_TYPES;

/** Photos and camera only when the project accepts images. */
export const projectFileSources = (
  mimeTypes: string[],
): ProjectFileSource[] => {
  const accepted = effectiveMimeTypes(mimeTypes).map(type => {
    const mime = type.trim().toLowerCase();
    return mime === 'image/jpg' ? 'image/jpeg' : mime;
  });
  const images = accepted.some(type => PROJECT_IMAGE_MIME_TYPES.includes(type));
  return images ? ['photos', 'camera', 'files'] : ['files'];
};

const SOURCE_LABELS: Record<ProjectFileSource, string> = {
  photos: 'الصور',
  camera: 'الكاميرا',
  files: 'الملفات',
};

/**
 * The platform attach menu: an iOS action sheet, an Android dialog with the
 * same three choices (Android dialogs dismiss with Back or an outside tap).
 */
export const askProjectFileSource = (
  sources: ProjectFileSource[],
): Promise<ProjectFileSource | null> => {
  if (sources.length <= 1) return Promise.resolve(sources[0] ?? null);
  return new Promise(resolve => {
    let settled = false;
    const finish = (source: ProjectFileSource | null) => {
      if (settled) return;
      settled = true;
      resolve(source);
    };
    if (Platform.OS === 'ios') {
      ActionSheetIOS.showActionSheetWithOptions(
        {
          title: 'ضيف من',
          options: [...sources.map(source => SOURCE_LABELS[source]), 'إلغاء'],
          cancelButtonIndex: sources.length,
        },
        index => finish(sources[index] ?? null),
      );
      return;
    }
    Alert.alert(
      'ضيف من',
      undefined,
      sources.map(source => ({
        text: SOURCE_LABELS[source],
        onPress: () => finish(source),
      })),
      {cancelable: true, onDismiss: () => finish(null)},
    );
  });
};

const imageAssetFile = (asset: Asset, index: number): SelectedProjectFile => {
  const type = String(asset.type || 'image/jpeg').toLowerCase();
  const extension =
    type === 'image/png' ? 'png' : type === 'image/webp' ? 'webp' : 'jpg';
  return {
    uri: String(asset.uri),
    name: asset.fileName || `rokn-project-${Date.now()}-${index}.${extension}`,
    type,
    size: asset.fileSize,
  };
};

const pickImages = async (
  source: 'photos' | 'camera',
  mimeTypes: string[],
  limit: number,
): Promise<SelectedProjectFile[]> => {
  const response: ImagePickerResponse =
    source === 'camera'
      ? await launchCamera({...PROJECT_PHOTO_OPTIONS, saveToPhotos: false})
      : await launchImageLibrary({
          ...PROJECT_PHOTO_OPTIONS,
          selectionLimit: Math.max(1, limit),
          restrictMimeTypes: effectiveMimeTypes(mimeTypes).filter(type =>
            type.startsWith('image/'),
          ),
        });
  if (response.errorCode) {
    throw Object.assign(new Error('PROJECT_PICKER_FAILED'), {
      errorCode: response.errorCode,
    });
  }
  return (response.assets || [])
    .filter(asset => asset.uri)
    .slice(0, Math.max(1, limit))
    .map(imageAssetFile);
};

const pickDocuments = async (
  mimeTypes: string[],
): Promise<SelectedProjectFile[]> => {
  const response = await DocumentPicker.getDocumentAsync({
    type: effectiveMimeTypes(mimeTypes),
    multiple: true,
    copyToCacheDirectory: true,
  });
  return response.canceled
    ? []
    : response.assets
        .filter(asset => asset.uri)
        .map(asset => ({
          uri: asset.uri,
          name: asset.name || `rokn-project-${Date.now()}`,
          type: asset.mimeType || 'application/octet-stream',
          size: asset.size,
        }));
};

export const pickProjectFilesOwned = async (
  mimeTypes: string[],
  isActive: () => boolean = () => true,
  {limit = 5}: {limit?: number} = {},
): Promise<{
  files: SelectedProjectFile[];
  ownerBoundary: AccountSessionBoundary;
}> => {
  const ownerBoundary = await captureAccountSessionBoundary();
  assertAccountSessionBoundary(ownerBoundary);
  if (!isActive()) return {files: [], ownerBoundary};
  let source: ProjectFileSource | null = 'files';
  try {
    source = await askProjectFileSource(projectFileSources(mimeTypes));
    assertAccountSessionBoundary(ownerBoundary);
    if (!source || !isActive()) return {files: [], ownerBoundary};
    const files =
      source === 'files'
        ? await pickDocuments(mimeTypes)
        : await pickImages(source, mimeTypes, limit);
    assertAccountSessionBoundary(ownerBoundary);
    if (!isActive()) return {files: [], ownerBoundary};
    return {files, ownerBoundary};
  } catch (error) {
    if (
      error instanceof Error &&
      error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
    ) {
      throw error;
    }
    if (!isActive()) return {files: [], ownerBoundary};
    const errorCode =
      error && typeof error === 'object' && 'errorCode' in error
        ? String((error as {errorCode?: unknown}).errorCode)
        : undefined;
    showMediaPickerFailure(
      source === 'files' ? 'document_picker_failed' : errorCode,
    );
    return {files: [], ownerBoundary};
  }
};
