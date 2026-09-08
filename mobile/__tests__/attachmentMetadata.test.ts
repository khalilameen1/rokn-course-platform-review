import {
  attachmentHeaderFilename,
  attachmentPrefixIsHtml,
  attachmentResponseIsHtml,
  safeAttachmentName,
} from '../src/components/VideoPlayer/attachmentMetadata';
import {mapCourseAttachments} from '../src/components/VideoPlayer/courseLearning/coursePayload';

describe('attachment source and download metadata', () => {
  it('maps source independently of device and preserves unknown external metadata', () => {
    const [external, computer, uploaded] = mapCourseAttachments(
      [
        {
          id: 1,
          download_url: 'https://api.example/1',
          download_only: true,
          source_type: 'external',
          external: true,
          external_url: 'https://drive.google.com/file/d/1/view',
          file_type: null,
          mime_type: null,
          file_size_bytes: null,
          file_size: null,
        },
        {
          id: 2,
          download_url: 'https://api.example/2',
          download_only: true,
          source_type: 'external',
          platform: 'computer',
        },
        {
          id: 3,
          download_url: 'https://api.example/3',
          download_only: true,
          file_name: 'original.xlsx',
          mime_type:
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        },
      ],
      'any',
      '31',
    );
    expect(external).toMatchObject({
      platform: 'mobile',
      external: true,
      sourceType: 'external',
      sourceUrl: 'https://drive.google.com/file/d/1/view',
    });
    expect(external.fileType).toBeUndefined();
    expect(external.mimeType).toBeUndefined();
    expect(external.fileSizeBytes).toBeUndefined();
    expect(external.fileSize).toBeUndefined();
    expect(computer).toMatchObject({platform: 'computer', external: true});
    expect(uploaded).toMatchObject({
      platform: 'mobile',
      external: false,
      sourceType: 'upload',
      fileName: 'original.xlsx',
    });
  });

  it('honors RFC 5987 names and removes file path traversal without inventing an extension', () => {
    expect(
      attachmentHeaderFilename(
        "attachment; filename=wrong.pdf; filename*=UTF-8''lesson%20files.zip",
      ),
    ).toBe('lesson files.zip');
    expect(
      attachmentHeaderFilename('attachment; filename="workbook.xlsx"'),
    ).toBe('workbook.xlsx');
    expect(safeAttachmentName('../../lesson files.zip')).toBe(
      'lesson-files.zip',
    );
    expect(safeAttachmentName('lesson files')).toBe('lesson-files');
  });

  it('rejects HTML by MIME or bounded prefix while accepting a binary prefix', () => {
    expect(attachmentResponseIsHtml('text/html; charset=UTF-8')).toBe(true);
    expect(attachmentResponseIsHtml('application/xhtml+xml')).toBe(true);
    expect(attachmentPrefixIsHtml('\uFEFF  <!DOCTYPE html><html>Sign in')).toBe(
      true,
    );
    expect(attachmentPrefixIsHtml('%PDF-1.7')).toBe(false);
  });
});
