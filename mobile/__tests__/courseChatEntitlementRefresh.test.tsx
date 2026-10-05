import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetUpgradeQuote = jest.fn();
jest.mock('../src/services/roknApi', () => ({
  getFullTrackUpgradeQuote: (...args: unknown[]) =>
    mockGetUpgradeQuote(...args),
}));

import {useCourseChatUpgrade} from '../src/components/VideoPlayer/courseChat/useCourseChatUpgrade';

let hook!: ReturnType<typeof useCourseChatUpgrade>;
const Harness = ({
  accountKey = 'account-a',
  courseId = '3',
  chatAvailable = false,
  revision = 'captured-basic',
  active = true,
  accessType = 'paid',
}: {
  accountKey?: string;
  courseId?: string;
  chatAvailable?: boolean;
  revision?: string;
  active?: boolean;
  accessType?: string;
}) => {
  hook = useCourseChatUpgrade({
    accountKey,
    courseId,
    chatAvailable,
    active,
    accessType,
    chatEntitlementRevision: revision,
  });
  return null;
};
const unavailable = {
  upgradeAvailable: false,
  availablePlanCodes: [],
  alreadyUpgraded: false,
  chatAvailable: false,
};
const available = {
  upgradeAvailable: true,
  availablePlanCodes: ['mentor'],
  alreadyUpgraded: false,
  chatAvailable: false,
};

describe('course chat authoritative upgrade offers', () => {
  it('retires a closed visit and discovers availability again when reopened', async () => {
    let finish!: (value: typeof available) => void;
    mockGetUpgradeQuote.mockReset()
      .mockReturnValueOnce(new Promise(resolve => {finish = resolve;}))
      .mockResolvedValueOnce(unavailable);
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {renderer = TestRenderer.create(<Harness />);});
      await act(async () => {renderer.update(<Harness active={false} />);});
      expect(hook.upgradeStatus).toBe('idle');
      await act(async () => {renderer.update(<Harness />);});
      await act(async () => {finish(available);});
      expect(mockGetUpgradeQuote).toHaveBeenCalledTimes(2);
      expect(hook.upgradeStatus).toBe('unavailable');
    } finally {
      if (renderer) await act(async () => renderer.unmount());
    }
  });
  beforeEach(() => {
    jest.resetAllMocks();
    mockGetUpgradeQuote.mockResolvedValue(unavailable);
  });

  it('does not expose a second purchase path or infer rights from a highest-tier quote', async () => {
    mockGetUpgradeQuote.mockResolvedValue({
      ...unavailable,
      alreadyUpgraded: true,
      chatAvailable: true,
    });
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness chatAvailable revision="captured-mentor" />,
      );
    });
    await act(async () => {
      hook.recordServerBlock('chat_plan_limit_reached');
    });
    expect(hook.upgradeStatus).toBe('unavailable');
    expect(hook.serverBlockCode).toBe('chat_plan_limit_reached');
    expect(hook).not.toHaveProperty('confirmUpgrade');
    expect(hook).not.toHaveProperty('upgraded');
    await act(async () => renderer.unmount());
  });

  it('offers a real higher tier without clearing the exhausted gate before purchase', async () => {
    mockGetUpgradeQuote.mockResolvedValue(available);
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness chatAvailable revision="captured-guided" />,
      );
    });
    await act(async () => {
      hook.recordServerBlock('chat_plan_limit_reached');
    });
    expect(mockGetUpgradeQuote).toHaveBeenCalledWith('3', {
      requiredFeature: 'chat',
    });
    expect(hook.upgradeStatus).toBe('available');
    expect(hook.serverBlockCode).toBe('chat_plan_limit_reached');
    await act(async () => renderer.unmount());
  });

  it('retires exhaustion only after the captured entitlement changes even when paid/chat flags stay the same', async () => {
    let finish!: (value: typeof available) => void;
    mockGetUpgradeQuote.mockReturnValue(
      new Promise(resolve => {
        finish = resolve;
      }),
    );
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness chatAvailable revision="captured-guided" />,
      );
    });
    await act(async () => {
      hook.recordServerBlock('chat_plan_limit_reached');
    });
    await act(async () => {
      renderer.update(<Harness chatAvailable revision="captured-mentor" />);
    });
    expect(hook.serverBlockCode).toBe('');
    expect(hook.upgradeStatus).toBe('idle');
    await act(async () => {
      finish(available);
    });
    expect(hook.serverBlockCode).toBe('');
    expect(hook.upgradeStatus).toBe('idle');
    await act(async () => renderer.unmount());
  });

  it('ignores an offer that arrived for the previous account', async () => {
    let finish!: (value: typeof available) => void;
    mockGetUpgradeQuote
      .mockReturnValueOnce(
        new Promise(resolve => {
          finish = resolve;
        }),
      )
      .mockResolvedValueOnce(unavailable);
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      renderer.update(<Harness accountKey="account-b" />);
    });
    await act(async () => {
      finish(available);
    });
    expect(hook.upgradeStatus).toBe('unavailable');
    await act(async () => renderer.unmount());
  });

  it('offers retry on a failed eligibility read rather than fabricating an available upgrade', async () => {
    mockGetUpgradeQuote
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValueOnce(available);
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    expect(hook.upgradeStatus).toBe('error');
    await act(async () => {
      hook.retryUpgradeQuote();
    });
    expect(hook.upgradeStatus).toBe('available');
    await act(async () => renderer.unmount());
  });

  it.each(['none', 'free'])(
    'does not ask for an upgrade quote without upgradeable learning access (%s)',
    async accessType => {
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<Harness accessType={accessType} />);
      });
      expect(mockGetUpgradeQuote).not.toHaveBeenCalled();
      expect(hook.upgradeStatus).toBe('idle');
      await act(async () => renderer.unmount());
    },
  );

  it('rejects a missing authoritative availability contract', async () => {
    mockGetUpgradeQuote.mockResolvedValue({
      alreadyUpgraded: false,
      targetPlanCode: 'mentor',
    });
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    expect(hook.upgradeStatus).toBe('error');
    await act(async () => renderer.unmount());
  });
});
