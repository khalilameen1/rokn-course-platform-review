'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const {transformNativeSources} = require('../rnfs-ios-download-lifecycle-fix');

const nativeRoot = path.dirname(require.resolve('react-native-fs'));
const names = ['Downloader.h', 'Downloader.m', 'RNFSManager.m'];
const installed = Object.fromEntries(
  names.map(name => [
    name,
    fs.readFileSync(path.join(nativeRoot, name), 'utf8').replace(/\r\n/g, '\n'),
  ]),
);
const fixed = transformNativeSources(installed);

// These are source-transform contracts, not a simulator or NSURLSession test.
test('the exact native source transform is pure, idempotent and refuses drift', () => {
  assert.deepEqual(transformNativeSources(fixed), fixed);
  for (const name of names) {
    assert.equal(
      installed[name],
      fs
        .readFileSync(path.join(nativeRoot, name), 'utf8')
        .replace(/\r\n/g, '\n'),
    );
    assert.throws(
      () =>
        transformNativeSources({
          ...installed,
          [name]: installed[name] + '\n// changed\n',
        }),
      /Unexpected RNFS iOS source/,
    );
  }
  assert.throws(() => transformNativeSources({}), /Missing RNFS iOS source/);
});

test('terminal abandonment and the destination move share a native lock and old tasks cannot complete', () => {
  const source = fixed['Downloader.m'];
  const move = source.slice(
    source.indexOf('didFinishDownloadingToURL:'),
    source.indexOf('didCompleteWithError:'),
  );
  assert.match(
    move,
    /@synchronized \(self\) \{\s*if \(_terminal \|\| downloadTask != _task\) return;/,
  );
  assert.ok(move.indexOf('if (_terminal') < move.indexOf('moveItemAtURL:'));
  assert.match(move, /\[self finishWithError:error\]/);
  const finish = source.slice(source.lastIndexOf('- (void)finishWithError:'));
  assert.match(
    finish,
    /@synchronized \(self\) \{\s*if \(_terminal\) return;\s*_terminal = YES;/,
  );
  assert.ok(
    finish.indexOf('_terminal = YES') <
      finish.indexOf('[session invalidateAndCancel]'),
  );
  for (const field of [
    '_params',
    '_task',
    '_session',
    '_resumeData',
    '_fileHandle',
  ]) {
    assert.ok(finish.includes(`${field} = nil;`));
  }
  assert.match(finish, /params\.errorCallback\(error\)/);
  assert.match(
    finish,
    /params\.completeCallback\(_statusCode, _bytesWritten\)/,
  );
});

test('resumable stop still keeps the same job live while stale stop callbacks cannot revive an abandoned job', () => {
  const source = fixed['Downloader.m'];
  const stop = source.slice(
    source.indexOf('- (void)stopDownload'),
    source.indexOf('- (void)resumeDownload'),
  );
  assert.match(stop, /cancelByProducingResumeData/);
  assert.match(
    stop,
    /if \(self->_terminal \|\| self->_task != stoppedTask\) return;/,
  );
  assert.match(stop, /self->_resumeData = resumeData;/);
  assert.match(stop, /self->_params\.resumableCallback\(\)/);
  assert.doesNotMatch(stop, /\[self cancelDownload\]/);
  const resume = source.slice(
    source.indexOf('- (void)resumeDownload'),
    source.indexOf('- (BOOL)isResumable'),
  );
  assert.match(resume, /if \(_terminal\) return;/);
  assert.match(resume, /downloadTaskWithResumeData:_resumeData/);
});

test('the manager registers before starting and retires only its exact terminal downloader', () => {
  const source = fixed['RNFSManager.m'];
  assert.ok(
    source.indexOf('[self.downloaders setObject:downloader forKey:jobKey]') <
      source.indexOf('[downloader downloadFile:params]'),
  );
  assert.equal(
    source.split(
      'if ([self.downloaders objectForKey:jobKey] == ownedDownloader)',
    ).length - 1,
    2,
  );
  assert.equal(
    source.split('[self.downloaders removeObjectForKey:jobKey]').length - 1,
    2,
  );
  const cancel = source.slice(
    source.indexOf('RCT_EXPORT_METHOD(cancelDownload:'),
    source.indexOf('RCT_EXPORT_METHOD(resumeDownload:'),
  );
  assert.match(
    cancel,
    /@synchronized \(self\) \{\s*downloader = \[self\.downloaders objectForKey:\[jobId stringValue\]\];\s*\}\s*\[downloader cancelDownload\];/,
  );
});

test('background acknowledgement consumes only its registered handler before removing UUID routing', () => {
  const source = fixed['RNFSManager.m'];
  const handler = source.slice(
    source.indexOf('RCT_EXPORT_METHOD(completeHandlerIOS:'),
    source.indexOf('RCT_EXPORT_METHOD(uploadFiles:'),
  );
  assert.match(handler, /if \(uuid\)/);
  assert.match(handler, /@synchronized \(\[RNFSManager class\]\)/);
  assert.match(
    handler,
    /if \(completionHandler\) \{[\s\S]*isEqualToString:uuid[\s\S]*removeObjectForKey:jobKey/,
  );
  assert.ok(
    handler.indexOf('[completionHandlers removeObjectForKey:uuid]') <
      handler.indexOf(
        'dispatch_async(dispatch_get_main_queue(), completionHandler)',
      ),
  );
  assert.match(
    source.slice(source.indexOf('+(void)setCompletionHandlerForIdentifier:')),
    /@synchronized \(\[RNFSManager class\]\)/,
  );
});
