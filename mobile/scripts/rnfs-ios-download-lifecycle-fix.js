'use strict';

const {createHash} = require('node:crypto');

// Audited RNFS 2.20.0 native sources. This is an opt-in terminal cancellation,
// not a change to the library's resumable stopDownload contract. Windows gates
// inspect this transform; they do not compile or execute NSURLSession/UIKit.
const hashes = {
  'Downloader.h': {
    original:
      'ec14ca8cdfd4cdf1c5fda2c82bc81ab381a83d9e068355c1ef23247b366ecf96',
    priorFixed:
      '15d824c98510cd689543a707f019c26b62ea62537520c57494345f9ad8651c26',
    fixed: 'e8a5a3e0703fee23a16c14d8e4545bd5567bbf538a9f2aeedebd99ccd2cad72d',
  },
  'Downloader.m': {
    original:
      '9d90b19ddcf1ca87be792b1d7aece0697009154253c682b0175b2a41841ab13b',
    priorFixed:
      '3ea9d21fe5cd91eac059c4bfaa339dd2b2f8e70e3ca1829cdb36d796a11b84ba',
    fixed: '2088172721ebb889a94228acf9e448c29914ea08e5f08b5f373e645e4a3fd209',
  },
  'RNFSManager.h': {
    original:
      '39db66aa8c557655c8a2bbe3d569e553bc8fe66b70ca7c236095b094812dac03',
    fixed: 'adba1f4800b07197b2accd8c31d150f0639afcbb31a2e64125411a3db9fc4e8e',
  },
  'RNFSManager.m': {
    original:
      '1f198866bd424f97cd4d183e0c4d81b19b7a0e4cfd6e000ccb9fd3118f867c9c',
    priorFixed:
      '12e5e79d2b6df77e49a841e8a6226def741632b562961af639d8bdc86baf53cf',
    fixed: 'a375876b39cc276539517b7decd783f5ca8b599e1a3b3f259c421bfac425d48a',
  },
};

const digest = value => createHash('sha256').update(value).digest('hex');

function replaceOnce(source, before, after) {
  if (source.split(before).length !== 2) {
    throw new Error('Unexpected RNFS iOS native patch site');
  }
  return source.replace(before, after);
}

function replaceMethod(source, signature, change) {
  const start = source.indexOf(signature);
  if (start < 0 || source.indexOf(signature, start + signature.length) >= 0) {
    throw new Error('Unexpected RNFS iOS method');
  }
  const nextMethod = source.indexOf('\n- (', start + signature.length);
  const end = nextMethod < 0 ? source.indexOf('\n@end', start) : nextMethod;
  if (end < 0) throw new Error('Unterminated RNFS iOS method');
  return (
    source.slice(0, start) +
    change(source.slice(start, end)) +
    source.slice(end)
  );
}

function synchronizedMethod(method, guard) {
  const open = method.indexOf('{');
  const close = method.lastIndexOf('}');
  const body = method
    .slice(open + 1, close)
    .split('\n')
    .map(line => (line ? '  ' + line : line))
    .join('\n');
  return (
    method.slice(0, open + 1) +
    '\n  @synchronized (self) {\n    ' +
    guard +
    body +
    '  }\n' +
    method.slice(close)
  );
}

function downloaderSource(source) {
  source = replaceOnce(
    source,
    '@property (retain) NSFileHandle* fileHandle;',
    '@property (retain) NSFileHandle* fileHandle;\n@property (assign) BOOL terminal;\n\n- (void)finishWithError:(NSError *)error;',
  );
  source = replaceMethod(source, '- (NSString *)downloadFile:', method =>
    synchronizedMethod(
      replaceOnce(
        method,
        '_params.errorCallback(error);',
        '[self finishWithError:error];',
      ),
      'if (_terminal) return nil;\n',
    ),
  );
  source = replaceMethod(
    source,
    '- (void)URLSession:(NSURLSession *)session downloadTask:(NSURLSessionDownloadTask *)downloadTask didWriteData:',
    method =>
      synchronizedMethod(
        method,
        'if (_terminal || downloadTask != _task) return;\n',
      ),
  );
  source = replaceMethod(
    source,
    '- (void)URLSession:(NSURLSession *)session downloadTask:(NSURLSessionDownloadTask *)downloadTask didFinishDownloadingToURL:',
    method => {
      const tailStart = method.indexOf('  // When numerous downloads');
      if (tailStart < 0) throw new Error('Unexpected RNFS iOS completion');
      return synchronizedMethod(
        method.slice(0, tailStart) + '  [self finishWithError:error];\n}\n',
        'if (_terminal || downloadTask != _task) return;\n',
      );
    },
  );
  source = replaceMethod(
    source,
    '- (void)URLSession:(NSURLSession *)session task:(NSURLSessionTask *)task didCompleteWithError:',
    method =>
      synchronizedMethod(
        replaceOnce(
          method,
          '_params.errorCallback(error);',
          '[self finishWithError:error];',
        ),
        'if (_terminal || task != _task) return;\n',
      ),
  );
  source = replaceMethod(
    source,
    '- (void)stopDownload',
    () => `- (void)stopDownload
{
  @synchronized (self) {
    if (_terminal || _task.state != NSURLSessionTaskStateRunning) return;
    NSURLSessionDownloadTask *stoppedTask = _task;
    [_task cancelByProducingResumeData:^(NSData * _Nullable resumeData) {
      @synchronized (self) {
        if (self->_terminal || self->_task != stoppedTask) return;
        if (resumeData != nil) {
          self->_resumeData = resumeData;
          if (self->_params.resumableCallback) self->_params.resumableCallback();
        } else {
          NSError *error = [NSError errorWithDomain:@"RNFS" code:0
            userInfo:@{NSLocalizedDescriptionKey: @"Download has been aborted"}];
          [self finishWithError:error];
        }
      }
    }];
  }
}
`,
  );
  source = replaceMethod(source, '- (void)resumeDownload', method =>
    synchronizedMethod(method, 'if (_terminal) return;\n'),
  );
  source = replaceMethod(
    source,
    '- (BOOL)isResumable',
    () => `- (BOOL)isResumable
{
  @synchronized (self) {
    return !_terminal && _resumeData != nil;
  }
}

- (void)cancelDownload
{
  NSError *error = [NSError errorWithDomain:NSURLErrorDomain code:NSURLErrorCancelled
    userInfo:@{NSLocalizedDescriptionKey: @"Download has been cancelled"}];
  [self finishWithError:error];
}

// All terminal paths share the same lock as the destination-file move. A
// cancelled job cannot later move a temporary file or retain new resumeData.
- (void)finishWithError:(NSError *)error
{
  @synchronized (self) {
    if (_terminal) return;
    _terminal = YES;
    RNFSDownloadParams *params = _params;
    NSURLSession *session = _session;
    _params = nil;
    _task = nil;
    _session = nil;
    _resumeData = nil;
    _fileHandle = nil;
    if (error) {
      [session invalidateAndCancel];
      if (params.errorCallback) params.errorCallback(error);
    } else {
      [session flushWithCompletionHandler:^{ [session finishTasksAndInvalidate]; }];
      if (params.completeCallback) params.completeCallback(_statusCode, _bytesWritten);
    }
  }
}
`,
  );
  return source;
}

function managerSource(source) {
  source = replaceOnce(
    source,
    '  __block BOOL callbackFired = NO;',
    `  NSString *jobKey = [jobId stringValue];
  RNFSDownloader *downloader = [RNFSDownloader alloc];
  __weak RNFSDownloader *ownedDownloader = downloader;
  __block BOOL callbackFired = NO;`,
  );
  const originalClaim = `    if (callbackFired) {
      return;
    }
    callbackFired = YES;`;
  const terminalClaim = `    @synchronized (self) {
      if (callbackFired) return;
      callbackFired = YES;
      if ([self.downloaders objectForKey:jobKey] == ownedDownloader) {
        [self.downloaders removeObjectForKey:jobKey];
      }
    }`;
  if (source.split(originalClaim).length !== 3)
    throw new Error('Unexpected RNFS terminal callbacks');
  source = source
    .replace(originalClaim, terminalClaim)
    .replace(originalClaim, terminalClaim);
  source = replaceOnce(
    source,
    `  if (!self.downloaders) self.downloaders = [[NSMutableDictionary alloc] init];

  RNFSDownloader* downloader = [RNFSDownloader alloc];

  NSString *uuid = [downloader downloadFile:params];

  [self.downloaders setValue:downloader forKey:[jobId stringValue]];
    if (uuid) {
        if (!self.uuids) self.uuids = [[NSMutableDictionary alloc] init];
        [self.uuids setValue:uuid forKey:[jobId stringValue]];
    }`,
    `  // Register before start: a local destination failure can reject synchronously.
  @synchronized (self) {
    if (!self.downloaders) self.downloaders = [[NSMutableDictionary alloc] init];
    [self.downloaders setObject:downloader forKey:jobKey];
  }
  NSString *uuid = [downloader downloadFile:params];
  if (uuid) {
    @synchronized (self) {
      if (!self.uuids) self.uuids = [[NSMutableDictionary alloc] init];
      [self.uuids setObject:uuid forKey:jobKey];
    }
  }`,
  );
  const lookup =
    'RNFSDownloader* downloader = [self.downloaders objectForKey:[jobId stringValue]];';
  if (source.split(lookup).length !== 4)
    throw new Error('Unexpected RNFS downloader lookups');
  source = source.split(lookup).join(`RNFSDownloader* downloader;
  @synchronized (self) {
    downloader = [self.downloaders objectForKey:[jobId stringValue]];
  }`);
  source = replaceOnce(
    source,
    'RCT_EXPORT_METHOD(resumeDownload:',
    `// Explicit abandonment is distinct from the resumable stopDownload API.
RCT_EXPORT_METHOD(cancelDownload:(nonnull NSNumber *)jobId)
{
  RNFSDownloader *downloader;
  @synchronized (self) {
    downloader = [self.downloaders objectForKey:[jobId stringValue]];
  }
  [downloader cancelDownload];
}

RCT_EXPORT_METHOD(resumeDownload:`,
  );
  const handlerStart = source.indexOf('RCT_EXPORT_METHOD(completeHandlerIOS:');
  const handlerEnd = source.indexOf(
    'RCT_EXPORT_METHOD(uploadFiles:',
    handlerStart,
  );
  if (handlerStart < 0 || handlerEnd < 0)
    throw new Error('Unexpected RNFS background handler');
  source =
    source.slice(0, handlerStart) +
    `RCT_EXPORT_METHOD(completeHandlerIOS:(nonnull NSNumber *)jobId
                  resolver:(RCTPromiseResolveBlock)resolve
                  rejecter:(RCTPromiseRejectBlock)reject)
{
  NSString *jobKey = [jobId stringValue];
  NSString *uuid;
  @synchronized (self) { uuid = [self.uuids objectForKey:jobKey]; }
  if (uuid) {
    CompletionHandler completionHandler;
    @synchronized ([RNFSManager class]) {
      completionHandler = [completionHandlers objectForKey:uuid];
      if (completionHandler) [completionHandlers removeObjectForKey:uuid];
    }
    // Keep routing when iOS has not delivered its handler yet. A repeated
    // acknowledgement can consume it later; no other job's handler is touched.
    if (completionHandler) {
      @synchronized (self) {
        if ([[self.uuids objectForKey:jobKey] isEqualToString:uuid]) {
          [self.uuids removeObjectForKey:jobKey];
        }
      }
      dispatch_async(dispatch_get_main_queue(), completionHandler);
    }
  }
  resolve(nil);
}

` +
    source.slice(handlerEnd);
  source = replaceOnce(
    source,
    `    if (!completionHandlers) completionHandlers = [[NSMutableDictionary alloc] init];
    [completionHandlers setValue:completionHandler forKey:identifier];`,
    `    @synchronized ([RNFSManager class]) {
      if (!completionHandlers) completionHandlers = [[NSMutableDictionary alloc] init];
      [completionHandlers setValue:completionHandler forKey:identifier];
    }`,
  );
  return source;
}

function backgroundHandoffSource(name, source) {
  if (name === 'Downloader.h') {
    return replaceOnce(
      source,
      '- (void)cancelDownload;',
      `- (void)cancelDownload;
+ (BOOL)handleBackgroundEventsForIdentifier:(NSString *)identifier completionHandler:(void (^)(void))completionHandler;`,
    );
  }
  if (name === 'RNFSManager.h') {
    return replaceOnce(
      source,
      '@end',
      `+ (BOOL)handleBackgroundEventsForIdentifier:(NSString *)identifier completionHandler:(CompletionHandler)completionHandler NS_SWIFT_NAME(handleBackgroundEvents(identifier:completionHandler:));

@end`,
    );
  }
  if (name === 'RNFSManager.m') {
    source = replaceOnce(
      source,
      '@property (retain) NSMutableDictionary* uuids;\n',
      '',
    );
    source = replaceOnce(
      source,
      'static NSMutableDictionary *completionHandlers;\n',
      '',
    );
    source = replaceOnce(
      source,
      `  NSString *uuid = [downloader downloadFile:params];
  if (uuid) {
    @synchronized (self) {
      if (!self.uuids) self.uuids = [[NSMutableDictionary alloc] init];
      [self.uuids setObject:uuid forKey:jobKey];
    }
  }`,
      '  [downloader downloadFile:params];',
    );
    const start = source.indexOf('RCT_EXPORT_METHOD(completeHandlerIOS:');
    const end = source.indexOf('RCT_EXPORT_METHOD(uploadFiles:', start);
    if (start < 0 || end < 0)
      throw new Error('Unexpected RNFS legacy acknowledgement');
    source =
      source.slice(0, start) +
      `RCT_EXPORT_METHOD(completeHandlerIOS:(nonnull NSNumber *)jobId
                  resolver:(RCTPromiseResolveBlock)resolve
                  rejecter:(RCTPromiseRejectBlock)reject)
{
  // Compatibility API: native session events now own the UIKit completion.
  // A JS acknowledgement cannot finish it early or wait for a Save sheet.
  resolve(nil);
}

` +
      source.slice(end);
    const setter = source.indexOf('+(void)setCompletionHandlerForIdentifier:');
    const close = source.indexOf('\n@end', setter);
    if (setter < 0 || close < 0)
      throw new Error('Unexpected RNFS legacy registration');
    return (
      source.slice(0, setter) +
      `+(void)setCompletionHandlerForIdentifier: (NSString *)identifier completionHandler: (CompletionHandler)completionHandler
{
  // Preserve the public legacy registration API, including an already-retired
  // identifier, without maintaining a second completion-handler registry.
  if (![self handleBackgroundEventsForIdentifier:identifier completionHandler:completionHandler] && completionHandler) {
    dispatch_async(dispatch_get_main_queue(), completionHandler);
  }
}

+ (BOOL)handleBackgroundEventsForIdentifier:(NSString *)identifier completionHandler:(CompletionHandler)completionHandler
{
  return [RNFSDownloader handleBackgroundEventsForIdentifier:identifier completionHandler:completionHandler];
}
` +
      source.slice(close)
    );
  }
  source = replaceOnce(
    source,
    '@property (assign) BOOL terminal;',
    `@property (assign) BOOL terminal;
@property (copy) NSString *backgroundIdentifier;
@property (retain) NSMutableArray *backgroundCompletionHandlers;
@property (retain) NSHashTable *backgroundSeenHandlers;
@property (assign) BOOL backgroundEventsFinished;

- (BOOL)registerBackgroundCompletionForIdentifier:(NSString *)identifier completionHandler:(void (^)(void))completionHandler;
- (void)finishBackgroundEvents;
- (void)drainBackgroundCompletionHandlers;`,
  );
  source = replaceOnce(
    source,
    '@implementation RNFSDownloader\n',
    `@implementation RNFSDownloader

// Only live sessions are routable. NSURLSession retains its delegate through
// invalidation; this weak index neither retains finished jobs nor restores a
// job from a previous process without its destination/account ownership.
static NSMapTable<NSString *, RNFSDownloader *> *backgroundDownloaders;
`,
  );
  source = replaceOnce(
    source,
    '      uuid = [[NSUUID UUID] UUIDString];',
    `      uuid = [[NSUUID UUID] UUIDString];
      _backgroundIdentifier = uuid;
      @synchronized ([RNFSDownloader class]) {
        if (!backgroundDownloaders) backgroundDownloaders = [NSMapTable strongToWeakObjectsMapTable];
        [backgroundDownloaders setObject:self forKey:uuid];
      }`,
  );
  const end = source.lastIndexOf('\n@end');
  if (end < 0) throw new Error('Unexpected RNFS downloader implementation');
  return (
    source.slice(0, end) +
    `
+ (BOOL)handleBackgroundEventsForIdentifier:(NSString *)identifier completionHandler:(void (^)(void))completionHandler
{
  if (!identifier.length || !completionHandler) return NO;
  RNFSDownloader *downloader;
  @synchronized ([RNFSDownloader class]) {
    downloader = [backgroundDownloaders objectForKey:identifier];
  }
  return [downloader registerBackgroundCompletionForIdentifier:identifier completionHandler:completionHandler];
}

- (BOOL)registerBackgroundCompletionForIdentifier:(NSString *)identifier completionHandler:(void (^)(void))completionHandler
{
  @synchronized (self) {
    if (![_backgroundIdentifier isEqualToString:identifier]) return NO;
    void (^ownedHandler)(void) = [completionHandler copy];
    if (!_backgroundSeenHandlers) {
      _backgroundSeenHandlers = [NSHashTable hashTableWithOptions:NSPointerFunctionsWeakMemory | NSPointerFunctionsObjectPointerPersonality];
    }
    // Only duplicate registrations of the same block are suppressed. A later
    // authentication/completion wake for this session has its own handler.
    // Weak identities do not retain historical UIKit handlers or their owners.
    if ([_backgroundSeenHandlers containsObject:ownedHandler]) return YES;
    [_backgroundSeenHandlers addObject:ownedHandler];
    if (!_backgroundCompletionHandlers) _backgroundCompletionHandlers = [NSMutableArray array];
    [_backgroundCompletionHandlers addObject:ownedHandler];
    [self drainBackgroundCompletionHandlers];
    return YES;
  }
}

- (void)finishBackgroundEvents
{
  @synchronized (self) {
    _backgroundEventsFinished = YES;
    [self drainBackgroundCompletionHandlers];
  }
}

- (void)drainBackgroundCompletionHandlers
{
  NSArray *completionHandlers;
  @synchronized (self) {
    if (!_backgroundEventsFinished || !_backgroundCompletionHandlers.count) return;
    // Readiness is consumed by this batch. Resume does not erase an earlier
    // undelivered event, and the next wake must wait for its own native drain.
    _backgroundEventsFinished = NO;
    completionHandlers = [_backgroundCompletionHandlers copy];
    _backgroundCompletionHandlers = nil;
  }
  dispatch_async(dispatch_get_main_queue(), ^{
    for (void (^completionHandler)(void) in completionHandlers) completionHandler();
  });
}

- (void)URLSessionDidFinishEventsForBackgroundURLSession:(NSURLSession *)session
{
  @synchronized (self) {
    if (![_backgroundIdentifier isEqualToString:session.configuration.identifier]) return;
    [self finishBackgroundEvents];
  }
}

- (void)URLSession:(NSURLSession *)session didBecomeInvalidWithError:(NSError *)error
{
  @synchronized (self) {
    NSString *identifier = session.configuration.identifier;
    if (![_backgroundIdentifier isEqualToString:identifier]) return;
    // Invalidation is the final delegate event, including foreground finish,
    // terminal error and cancellation where no background event batch follows.
    [self finishBackgroundEvents];
    @synchronized ([RNFSDownloader class]) {
      if ([backgroundDownloaders objectForKey:identifier] == self) {
        [backgroundDownloaders removeObjectForKey:identifier];
      }
    }
    _backgroundIdentifier = nil;
    _backgroundSeenHandlers = nil;
  }
}
` +
    source.slice(end)
  );
}

function transformNativeSources(sources) {
  const transformed = {...sources};
  for (const [name, expected] of Object.entries(hashes)) {
    const source = sources[name];
    if (typeof source !== 'string')
      throw new Error(`Missing RNFS iOS source: ${name}`);
    const current = digest(source);
    if (current === expected.fixed) continue;
    if (current !== expected.original && current !== expected.priorFixed)
      throw new Error(
        `Unexpected RNFS iOS source: ${name}; re-audit lifecycle patch.`,
      );
    const base =
      current === expected.priorFixed
        ? source
        : name === 'Downloader.h'
        ? replaceOnce(
            source,
            '- (void)stopDownload;',
            '- (void)stopDownload;\n- (void)cancelDownload;',
          )
        : name === 'Downloader.m'
        ? downloaderSource(source)
        : name === 'RNFSManager.m'
        ? managerSource(source)
        : source;
    transformed[name] = backgroundHandoffSource(name, base);
    if (digest(transformed[name]) !== expected.fixed) {
      throw new Error(`Unexpected RNFS iOS patch output: ${name}`);
    }
  }
  return transformed;
}

module.exports = {transformNativeSources};
