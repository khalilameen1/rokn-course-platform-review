import {ActionSheetIOS, Alert, Platform} from 'react-native';
import * as DocumentPicker from 'expo-document-picker';
import {launchCamera, launchImageLibrary} from 'react-native-image-picker';

const mockBoundary = {scope: 'user-a', epoch: 1};
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: () => undefined,
}));
jest.mock('expo-document-picker', () => ({getDocumentAsync: jest.fn()}));
jest.mock('react-native-image-picker', () => ({
  launchCamera: jest.fn(),
  launchImageLibrary: jest.fn(),
}));
jest.mock('../src/services/mediaPickerErrors', () => ({
  showMediaPickerFailure: jest.fn(),
}));

import {
  pickProjectFilesOwned,
  projectFileSources,
} from '../src/components/VideoPlayer/projectTransition/pickers';
import {showMediaPickerFailure} from '../src/services/mediaPickerErrors';

const IMAGES_AND_PDF = ['image/jpeg', 'image/png', 'application/pdf'];
const chooseSheetIndex = (index: number | undefined) =>
  jest
    .spyOn(ActionSheetIOS, 'showActionSheetWithOptions')
    .mockImplementation((_options, callback) => callback(index as number));

describe('project submission attach menu (Photos / Camera / Files)', () => {
  const originalOS = Platform.OS;
  beforeEach(() => {
    jest.clearAllMocks();
    jest.restoreAllMocks();
    Object.defineProperty(Platform, 'OS', {value: 'ios', configurable: true});
  });
  afterAll(() => {
    Object.defineProperty(Platform, 'OS', {
      value: originalOS,
      configurable: true,
    });
  });

  it('offers photos and camera only when the project accepts images', () => {
    expect(projectFileSources(IMAGES_AND_PDF)).toEqual([
      'photos',
      'camera',
      'files',
    ]);
    expect(projectFileSources([])).toEqual(['photos', 'camera', 'files']);
    expect(projectFileSources(['application/pdf'])).toEqual(['files']);
  });

  it('opens Files directly without a menu for document-only projects', async () => {
    const sheet = chooseSheetIndex(0);
    jest.mocked(DocumentPicker.getDocumentAsync).mockResolvedValue({
      canceled: false,
      assets: [
        {
          uri: 'file:///brief.pdf',
          name: 'brief.pdf',
          mimeType: 'application/pdf',
          size: 900,
          lastModified: 1,
        },
      ],
    } as never);
    const {files} = await pickProjectFilesOwned(['application/pdf']);
    expect(sheet).not.toHaveBeenCalled();
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledWith(
      expect.objectContaining({type: ['application/pdf'], multiple: true}),
    );
    expect(files).toEqual([
      {
        uri: 'file:///brief.pdf',
        name: 'brief.pdf',
        type: 'application/pdf',
        size: 900,
      },
    ]);
  });

  it('picks several photos as downscaled compatible JPEG within the remaining slots', async () => {
    chooseSheetIndex(0);
    jest.mocked(launchImageLibrary).mockResolvedValue({
      assets: [
        {uri: 'file:///a.jpg', type: 'image/jpeg', fileName: 'a.jpg', fileSize: 800_000},
        {uri: 'file:///b.jpg', type: 'image/jpeg', fileSize: 700_000},
      ],
    });
    const {files} = await pickProjectFilesOwned(IMAGES_AND_PDF, () => true, {
      limit: 2,
    });
    expect(launchImageLibrary).toHaveBeenCalledWith(
      expect.objectContaining({
        mediaType: 'photo',
        selectionLimit: 2,
        maxWidth: 2560,
        maxHeight: 2560,
        quality: 0.8,
        // PHPicker transcodes HEIC; the backend refuses it.
        assetRepresentationMode: 'compatible',
        restrictMimeTypes: ['image/jpeg', 'image/png'],
      }),
    );
    expect(files).toHaveLength(2);
    expect(files[0]).toEqual({
      uri: 'file:///a.jpg',
      name: 'a.jpg',
      type: 'image/jpeg',
      size: 800_000,
    });
    expect(files[1].name).toMatch(/^rokn-project-\d+-1\.jpg$/);
  });

  it('takes a photo with the camera using the same JPEG contract', async () => {
    chooseSheetIndex(1);
    jest.mocked(launchCamera).mockResolvedValue({
      assets: [{uri: 'file:///shot.jpg', type: 'image/jpeg', fileSize: 500}],
    });
    const {files} = await pickProjectFilesOwned(IMAGES_AND_PDF);
    expect(launchCamera).toHaveBeenCalledWith(
      expect.objectContaining({mediaType: 'photo', maxWidth: 2560, quality: 0.8}),
    );
    expect(files).toHaveLength(1);
  });

  it('opens Files for documents when chosen from the menu', async () => {
    chooseSheetIndex(2);
    jest.mocked(DocumentPicker.getDocumentAsync).mockResolvedValue({
      canceled: true,
      assets: null,
    } as never);
    await pickProjectFilesOwned(IMAGES_AND_PDF);
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(1);
    expect(launchImageLibrary).not.toHaveBeenCalled();
  });

  it('opens nothing when the menu is cancelled', async () => {
    chooseSheetIndex(3);
    const {files} = await pickProjectFilesOwned(IMAGES_AND_PDF);
    expect(files).toEqual([]);
    expect(launchImageLibrary).not.toHaveBeenCalled();
    expect(launchCamera).not.toHaveBeenCalled();
    expect(DocumentPicker.getDocumentAsync).not.toHaveBeenCalled();
  });

  it('does not open the picker when the visit ended while the menu was open', async () => {
    chooseSheetIndex(0);
    let active = true;
    jest
      .spyOn(ActionSheetIOS, 'showActionSheetWithOptions')
      .mockImplementation((_options, callback) => {
        active = false;
        callback(0);
      });
    await pickProjectFilesOwned(IMAGES_AND_PDF, () => active);
    expect(launchImageLibrary).not.toHaveBeenCalled();
  });

  it('uses a three-choice dialog on Android and reports picker permission errors', async () => {
    Object.defineProperty(Platform, 'OS', {value: 'android', configurable: true});
    const alert = jest
      .spyOn(Alert, 'alert')
      .mockImplementation((_title, _message, buttons) => {
        buttons?.[1]?.onPress?.();
      });
    jest.mocked(launchCamera).mockResolvedValue({errorCode: 'permission'});
    const {files} = await pickProjectFilesOwned(IMAGES_AND_PDF);
    expect(alert.mock.calls[0][2]?.map(button => button.text)).toEqual([
      'الصور',
      'الكاميرا',
      'الملفات',
    ]);
    expect(files).toEqual([]);
    expect(showMediaPickerFailure).toHaveBeenCalledWith('permission');
  });
});
