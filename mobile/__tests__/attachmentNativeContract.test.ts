import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('course attachment native delivery', () => {
  it('uses Android DownloadManager and the system viewer on every supported Android', () => {
    const gradle = source('android/build.gradle');
    const manifest = source('android/app/src/main/AndroidManifest.xml');
    const native = source(
      'android/app/src/main/java/com/rokn/downloads/RoknDownloadsModule.kt',
    );

    expect(gradle).toContain('minSdkVersion = 24');
    expect(manifest).toContain('android:maxSdkVersion="28"');
    expect(native).toContain('DownloadManager.Request(uri)');
    expect(native).toContain('Environment.DIRECTORY_DOWNLOADS');
    expect(native).toContain('Intent(Intent.ACTION_VIEW)');
    expect(native).toContain('Intent.FLAG_GRANT_READ_URI_PERMISSION');
  });

  it('maps only the renewable download-only contract', () => {
    const mapping = source(
      'src/components/VideoPlayer/courseLearning/coursePayload.ts',
    );
    expect(mapping).toContain('const url = raw.download_url');
    expect(mapping).toContain('!valueAsBoolean(raw.download_only)');
    expect(mapping).not.toContain('module?.attachments_link');

    const courseMapping = source(
      'src/components/VideoPlayer/courseLearning/mapping.ts',
    );
    expect(courseMapping).toContain(
      "mapCourseAttachments(rawCourse.attachments, 'any', courseId)",
    );
    expect(courseMapping).not.toContain('module.attachments');
    expect(courseMapping).not.toContain('section.attachments');
    expect(courseMapping).not.toContain('module.attachment_platform');
    expect(courseMapping).not.toContain('module.attachments_link');

    const promptOwner = source(
      'src/components/VideoPlayer/feedSideBar/useAttachmentPrompt.ts',
    );
    const downloader = source(
      'src/components/VideoPlayer/attachmentActions.ts',
    );
    expect(promptOwner).toContain('const attachments = course.attachments');
    expect(promptOwner).toContain("const scope = 'course'");
    expect(downloader).toContain(
      'course.attachments.find(item => item.id === attachment.id)',
    );
  });
});
