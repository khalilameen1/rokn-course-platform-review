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

  it('probes external headers without credentials, unbounded redirects, or a buffered file body', () => {
    const android = source(
      'android/app/src/main/java/com/rokn/downloads/AttachmentMetadataProbe.kt',
    );
    expect(android).toContain('CookieJar.NO_COOKIES');
    expect(android).toContain('Authenticator.NONE');
    expect(android).toContain('.followRedirects(false)');
    expect(android).toContain('check(++redirects <= 5)');
    expect(android).toContain('SystemClock.elapsedRealtime() + 8_000L');
    expect(android).toContain('bytes=0-511');
    expect(android).toContain('response.code in listOf(401, 403, 405, 501)');
    expect(android).not.toMatch(/\.body\??\.(bytes|string|byteStream)\(/);
    const ios = source('ios/Rokn/RoknDownloads.swift');
    expect(ios).toContain('URLSessionConfiguration.ephemeral');
    expect(ios).toContain('config.httpCookieStorage = nil');
    expect(ios).toContain('config.urlCredentialStorage = nil');
    expect(ios).toContain('config.timeoutIntervalForResource = 8');
    expect(ios).toContain('redirects <= 5');
    expect(ios).toContain('request.httpMethod = "GET"');
    expect(ios).toContain('URLSessionDataDelegate');
    expect(ios).toContain('completionHandler(.cancel)');
    expect(ios).toContain('session.dataTask(with: request).resume()');
    expect(ios).not.toContain('session.dataTask(with: request) {');
    expect(source('ios/Rokn.xcodeproj/project.pbxproj')).toContain(
      'RoknDownloadsBridge.m in Sources',
    );
  });

  it('validates completed external downloads after process death before retaining a host page', () => {
    const receiver = source(
      'android/app/src/main/java/com/rokn/downloads/AttachmentDownloadReceiver.kt',
    );
    const validation = source(
      'android/app/src/main/java/com/rokn/downloads/AttachmentFileValidation.kt',
    );
    expect(source('android/app/src/main/AndroidManifest.xml')).toContain(
      '.downloads.AttachmentDownloadReceiver',
    );
    expect(source('android/app/src/main/AndroidManifest.xml')).toMatch(
      /android:name="\.downloads\.AttachmentDownloadReceiver"\s+android:exported="true"\s+android:permission="android.permission.SEND_DOWNLOAD_COMPLETED_INTENTS"/,
    );
    expect(receiver).toContain('preferences.all.values.none');
    expect(receiver).toContain('status != DownloadManager.STATUS_SUCCESSFUL');
    expect(receiver).toContain('AttachmentFileValidation.isHtml');
    expect(receiver).toContain('manager.remove(id)');
    expect(validation).toContain('ByteArray(512)');
    expect(validation).toContain('application/xhtml+xml');
  });
});
