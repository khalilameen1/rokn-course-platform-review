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
    const upgradeOffer = source('src/hooks/useCourseUpgradeOffer.ts');
    expect(upgradeOffer).toContain('ownerRef.current === owner');
    expect(upgradeOffer).toContain('!cancelled');
    expect(chatUpgrade).toContain(
      'ownerKey: JSON.stringify([scope, serverBlockCode])',
    );
    expect(chatUpgrade).toContain('chatEntitlementRevision');
    expect(chat).toMatch(
      /sendGenerationRef\.current \+= 1;[\s\S]*setSending\(false\);[\s\S]*await cancelCourseAssistantTurn/,
    );
    const stopBlock = chat.slice(
      chat.indexOf('const stop = useCallback'),
      chat.indexOf('runTurnRef.current = runTurn'),
    );
    expect(stopBlock).toContain('generation: conversationGeneration.current');
    expect(stopBlock).toMatch(
      /const ownsStop = \(\) =>\s*noticeMountedRef\.current &&\s*stopFlightRef\.current === stopFlight &&\s*stopFlight\.generation === conversationGeneration\.current &&\s*activeConversation\.current === conversationScope/,
    );
    expect(stopBlock).toMatch(
      /await cancelCourseAssistantTurn\([\s\S]*?assertAccountSessionBoundary\(boundary\);\s*if \(!ownsStop\(\)\) return;/,
    );
    expect(stopBlock).toContain(
      'if (cancelledAtServer && sendFlightRef.current === stoppedSendFlight)',
    );
    expect(stopBlock).toContain(
      'resumeInterruptedTurnRef.current = !cancelledAtServer',
    );
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
    // Native picker ownership is covered by the real-hook behavioral cases in
    // courseChatPickerOwnership rather than matching implementation variable names.
    expect(notifications).toContain('new Map<string, symbol>()');
    expect(notifications).toContain(
      'readFlightsRef.current.get(item.id) === flight',
    );
  });

  it('does not let a completed course transaction mutate the next course route', () => {
    // The live course checkout is the subscription sheet hook.
    const checkout = source('src/hooks/useCourseSubscriptionCheckout.ts');

    expect(checkout).toContain('generation.current += 1;');
    expect(checkout).toContain('current.current.courseId === courseId');
    expect(checkout).toMatch(
      /next = await authorizeCourseCheckout\(quote\.id\);[\s\S]{0,1600}if \(!owns\(token\)\) return;/,
    );
    expect(checkout).toMatch(
      /const payment = await openCoinCheckout\([\s\S]{0,600}assertAccountSessionBoundary\(boundary\);\s*if \(!owns\(token\)\) return;/,
    );
  });

  // Project submission, playback evidence and section completion ownership
  // are exercised through their durable outbox adapters instead of source
  // text: projectSubmissionOutboxOwnership, playbackEvidenceStorageOrdering,
  // sectionCompletionOwnership and sectionCompletionAcknowledgement.

  it('bounds chat polling while keeping one owned project report read alive at a reduced cadence', () => {
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
    expect(project).toMatch(
      /if \(\s*!active \|\|\s*!appIsActive[\s\S]*resolution\.reportStatus !== 'queued'/,
    );
    expect(project).toMatch(
      /const next = await loadProjectResolution\(projectId\);[\s\S]*if \(cancelled \|\| !ownsProject\(projectId\)\) return;[\s\S]*if \(next\.reportStatus === 'queued'\) schedule\(2200\)/,
    );
    expect(project).toMatch(/attempts > 30\s*\? 30000/);
    expect(project).not.toContain('attempts < 30');
    expect(project).toContain("resolution.reportStatus !== 'queued'");
    expect(project).toContain("next.reportStatus === 'queued'");
  });

  it('does not apply device or paid-upgrade results after an account switch', () => {
    const devices = source('src/screens/DeviceSessions.tsx');
    const upgrade = source('src/hooks/useCourseSubscriptionCheckout.ts');

    expect(devices).toMatch(
      /const boundary = await captureAccountSessionBoundary\(\);[\s\S]*await getDeviceSessions\(\);[\s\S]*assertAccountSessionBoundary\(boundary\)/,
    );
    expect(devices).toMatch(
      /await revokeDeviceSession\(session\.id\);[\s\S]*assertAccountSessionBoundary\(boundary\)/,
    );
    expect(upgrade).toMatch(
      /boundary = await captureAccountSessionBoundary\(\);[\s\S]*await authorizeCourseCheckout\([\s\S]*assertAccountSessionBoundary\(boundary\)/,
    );
    expect(upgrade).toContain('if (!owns(token)) return;');
  });
});
