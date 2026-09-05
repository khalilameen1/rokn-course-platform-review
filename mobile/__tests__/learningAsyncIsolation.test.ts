import fs from 'node:fs';
import path from 'node:path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('learning async ownership contracts', () => {
  it('does not let a previous project operation update the active project', () => {
    const project = source(
      'src/components/VideoPlayer/projectTransition/useProjectSubmission.ts',
    );
    expect(project).toContain('generation: identityRef.current.generation + 1');
    expect(project).toContain('if (!ownsProject(id, generation)) return;');
    expect(project).toContain('setSending(false)');
  });

  it('does not restart a new reel when an old source refresh settles', () => {
    const player = source(
      'src/components/VideoPlayer/video/useVideoController.tsx',
    );
    const recovery = source(
      'src/components/VideoPlayer/video/usePlaybackRecovery.ts',
    );
    expect(player).toContain('playbackLifecycleGenerationRef.current += 1');
    expect(recovery).toContain('reelIdentity.current !== reelId');
    expect(recovery).toContain('generation !== lifecycleGeneration.current');
    expect(player).toContain('clearTimeout(longBufferTimerRef.current)');
    expect(player).toContain('clearTimeout(recoveryTimerRef.current)');
  });

  it('isolates upgrade and notification flights across accounts', () => {
    const chat = source(
      'src/components/VideoPlayer/courseChat/useCourseChatTurn.ts',
    );
    const chatUpgrade = source(
      'src/components/VideoPlayer/courseChat/useCourseChatUpgrade.ts',
    );
    const chatPolling = source(
      'src/components/VideoPlayer/courseChat/turnPolling.ts',
    );
    const notifications = source(
      'src/screens/notifications/useNotificationsInbox.ts',
    );
    expect(chatUpgrade).toContain('generationRef.current === generation');
    expect(chatUpgrade).toContain('activeCourseIdRef.current === courseId');
    expect(chatUpgrade).toContain(
      '[accountKey, accessType, chatAvailable, courseId]',
    );
    expect(chat).toContain(
      'stopConversationGeneration !== conversationGeneration.current',
    );
    expect(chat).toMatch(
      /sendGenerationRef\.current \+= 1;[\s\S]*setSending\(false\);[\s\S]*await cancelCourseAssistantTurn/,
    );
    const stopBlock = chat.slice(
      chat.indexOf('const stop = useCallback'),
      chat.indexOf('runTurnRef.current = runTurn'),
    );
    expect(stopBlock).not.toContain('sendFlightRef.current = null');
    expect(chat).toContain('setRecoverySignal(value => value + 1)');
    expect(chatPolling).toContain(
      'response = await pollCourseAssistantTurn(clientRequestId)',
    );
    expect(chat).toContain('sendGeneration === sendGenerationRef.current');
    expect(chat).toContain('if (!ownsTurn()) return;');
    expect(chat).toContain(
      '? await pollCourseAssistantTurn(retryClientRequestId)',
    );
    expect(chat).toContain(
      'courseChatFailureCanStartFreshTurn(response.canRetry)',
    );
    const chatOverlay = source(
      'src/components/VideoPlayer/courseChat/useCourseChatAttachments.ts',
    );
    expect(chatOverlay).toContain('pickerGenerationRef.current += 1');
    expect(chatOverlay).toContain('if (!ownsPicker())');
    expect(notifications).toContain('new Map<string, symbol>()');
    expect(notifications).toContain(
      'readFlightsRef.current.get(item.id) === flight',
    );
  });

  it('does not let a completed course transaction mutate the next course route', () => {
    const checkout = source(
      'src/screens/CourseDetails/details/useCourseCheckout.ts',
    );

    expect(checkout).toContain('generationRef.current += 1;');
    expect(checkout).toContain(
      'activeScopeRef.current.courseId === expectedCourseId',
    );
    expect(checkout).toContain(
      'activeScopeRef.current.identityKey === expectedIdentity',
    );
    expect(checkout).toMatch(
      /const result = await purchaseCourse\([\s\S]*!ownsOperation\([\s\S]{0,180}operationCourseId,[\s\S]{0,180}operationIdentity,[\s\S]{0,180}operationGeneration/,
    );
    expect(checkout).toMatch(
      /const result = await openCoinCheckout\([\s\S]*!ownsOperation\([\s\S]{0,180}operationCourseId,[\s\S]{0,180}operationIdentity,[\s\S]{0,180}operationGeneration/,
    );
  });

  it('keeps project and completion writes owned by their starting account', () => {
    const projects =
      source(
        'src/components/VideoPlayer/courseLearning/projectSubmissionOutbox.ts',
      ) +
      source(
        'src/components/VideoPlayer/courseLearning/projectSubmissionOwnership.ts',
      ) +
      source(
        'src/components/VideoPlayer/courseLearning/projectSubmissionStore.ts',
      ) +
      source(
        'src/components/VideoPlayer/courseLearning/projectSubmissionTransport.ts',
      ) +
      source('src/components/VideoPlayer/courseLearning/projectRemote.ts');
    const playbackFacade = source(
      'src/components/VideoPlayer/courseLearning/playback.ts',
    );
    const playbackProgress = source(
      'src/components/VideoPlayer/courseLearning/playbackProgress.ts',
    );
    const playbackSession = source(
      'src/components/VideoPlayer/courseLearning/playbackSession.ts',
    );
    const sectionCompletion = source(
      'src/components/VideoPlayer/courseLearning/sectionCompletion.ts',
    );

    expect(projects).toContain(
      'const boundary = await captureAccountSessionBoundary();',
    );
    expect(projects).toContain('assertProjectSubmissionOwner(operation);');
    expect(projects).toContain(
      'const storageKey = projectSubmissionKey(projectId, accountScope);',
    );
    expect(projects).not.toContain('passedProjects');
    expect(projects).not.toContain('evaluatingProjects');
    expect(projects).toContain("submissionStatus: 'draft'");
    expect(projects).toContain('runForegroundSubmission(');
    expect(projects).toContain('flights.get(key) === flight');
    expect(projects).toContain('withProjectSubmissionLock(');
    expect(projects).toContain('boundary.scope,');
    expect(projects).toMatch(/openAttachment\(input, boundary\)/);

    expect(playbackFacade).not.toContain('publicRequest.');
    expect(playbackFacade).not.toContain('AsyncStorage.');
    expect(playbackProgress).toContain('assertWatchHistoryOwner(');
    expect(playbackProgress).toContain(
      'watchHistoryFlights.get(key) === flight',
    );
    expect(sectionCompletion).toContain('updatePlayerStateForScope(');
    expect(sectionCompletion).toContain('assertOwner(generation, boundary);');
    expect(sectionCompletion).toContain(
      'const key = `${boundary.scope}:${boundary.epoch}:${courseId}:${sectionId}`;',
    );
    expect(sectionCompletion).toContain('flights.get(key) === flight');
    expect(playbackSession).toContain('`${accountScope}:${playbackSessionId}`');
  });

  it('bounds a slow streaming chat without abandoning its immutable turn', () => {
    const chat = source('src/components/VideoPlayer/courseChat/turnPolling.ts');
    const chatController = source(
      'src/components/VideoPlayer/courseChat/useCourseChatTurn.ts',
    );

    expect(chat).toContain('COURSE_CHAT_DEFAULT_POLL_WINDOW_MS');
    expect(chat).toContain('Number(response.pollWindowSeconds) * 1000');
    expect(chat).toContain('Date.now() < currentDeadline()');
    expect(chatController).toContain('attemptStartedAt,');
    expect(chat).toContain('statusProbes < COURSE_CHAT_MAX_STATUS_PROBES');
    expect(chatController).toContain('resumeInterruptedTurnRef.current = true');

    const project = source(
      'src/components/VideoPlayer/projectTransition/useProjectResolution.ts',
    );
    expect(project).toContain('attempts < 30');
    expect(project).toContain("resolution.reportStatus !== 'queued'");
    expect(project).toContain("next.reportStatus === 'queued'");
  });

  it('does not apply device or paid-upgrade results after an account switch', () => {
    const devices = source('src/screens/DeviceSessions.tsx');
    const upgrade = source('src/components/FullTrackUpgradeSheet.tsx');

    expect(devices).toMatch(
      /const boundary = await captureAccountSessionBoundary\(\);[\s\S]*await getDeviceSessions\(\);[\s\S]*assertAccountSessionBoundary\(boundary\)/,
    );
    expect(devices).toMatch(
      /await revokeDeviceSession\(session\.id\);[\s\S]*assertAccountSessionBoundary\(boundary\)/,
    );
    expect(upgrade).toMatch(
      /boundary = await captureAccountSessionBoundary\(\);[\s\S]*await purchaseFullTrackUpgrade\([\s\S]*assertAccountSessionBoundary\(boundary\)/,
    );
    expect(upgrade).toContain(
      "requestError.message === 'ACCOUNT_CHANGED_DURING_REQUEST'",
    );
  });
});
