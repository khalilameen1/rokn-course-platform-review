import {
  courseChatFailureCanStartFreshTurn,
  courseChatFailureHasRetryAction,
  courseChatTurnIsActuallyStreaming,
  courseChatTurnHasRetryAction,
  courseChatTurnIsPolling,
  courseChatTurnIsUnresolved,
  courseChatTurnPhase,
  courseChatTurnShowsActivity,
} from '../src/components/VideoPlayer/courseChat/policy';

describe('course chat terminal retry policy', () => {
  it('starts a fresh turn only after the backend explicitly allows it', () => {
    expect(courseChatFailureCanStartFreshTurn(true)).toBe(true);
    expect(courseChatFailureCanStartFreshTurn(false)).toBe(false);
    expect(courseChatFailureCanStartFreshTurn()).toBe(false);
  });

  it('offers recovery for interrupted work but no dead button for unknown provider outcomes', () => {
    expect(courseChatFailureHasRetryAction('interrupted_turn')).toBe(true);
    expect(courseChatFailureHasRetryAction('chat_answer_in_progress')).toBe(
      true,
    );
    expect(
      courseChatFailureHasRetryAction('chat_provider_outcome_unknown'),
    ).toBe(false);
    expect(
      courseChatFailureHasRetryAction('ai_temporarily_unavailable', true),
    ).toBe(true);
    expect(
      courseChatFailureHasRetryAction('chat_attachment_unreadable', false),
    ).toBe(false);
  });

  it('keeps accepted turns active without pretending queued work is typing', () => {
    expect(courseChatTurnIsPolling('queued')).toBe(true);
    expect(courseChatTurnIsPolling('streaming')).toBe(true);
    expect(courseChatTurnIsPolling('completed')).toBe(false);
    expect(courseChatTurnIsActuallyStreaming('queued')).toBe(false);
    expect(courseChatTurnIsActuallyStreaming('streaming')).toBe(true);
  });

  it('uses one delivery state for an accepted turn whose foreground wait ended', () => {
    expect(courseChatTurnPhase('submitting')).toBe('submitting');
    expect(courseChatTurnShowsActivity('submitting')).toBe(true);
    expect(courseChatTurnIsPolling('submitting')).toBe(false);
    expect(courseChatTurnIsUnresolved('submitting')).toBe(true);
    expect(courseChatTurnPhase('checking')).toBe('checking');
    expect(courseChatTurnShowsActivity('checking')).toBe(true);
    expect(courseChatTurnIsPolling('checking')).toBe(false);
    expect(courseChatTurnIsUnresolved('checking')).toBe(true);
    expect(courseChatTurnPhase('interrupted')).toBe('interrupted');
    expect(courseChatTurnIsPolling('interrupted')).toBe(false);
    expect(courseChatTurnIsUnresolved('interrupted')).toBe(true);
    expect(
      courseChatTurnHasRetryAction('interrupted', 'interrupted_turn'),
    ).toBe(true);
    expect(courseChatTurnHasRetryAction('queued', 'interrupted_turn')).toBe(
      false,
    );
    expect(courseChatTurnHasRetryAction('completed', 'client_timeout')).toBe(
      false,
    );
    expect(
      courseChatTurnHasRetryAction(
        'failed',
        'ai_temporarily_unavailable',
        true,
      ),
    ).toBe(true);
    expect(
      courseChatTurnHasRetryAction(
        'failed',
        'chat_provider_outcome_unknown',
        false,
      ),
    ).toBe(false);
  });
});
