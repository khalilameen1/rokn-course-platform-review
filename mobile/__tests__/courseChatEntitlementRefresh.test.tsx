import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetUpgradeQuote = jest.fn();
const mockPurchaseUpgrade = jest.fn();

jest.mock('../src/services/roknApi', () => ({
  getFullTrackUpgradeQuote: (...args: unknown[]) => mockGetUpgradeQuote(...args),
  purchaseFullTrackUpgrade: (...args: unknown[]) => mockPurchaseUpgrade(...args),
}));

import {useCourseChatUpgrade} from '../src/components/VideoPlayer/courseChat/useCourseChatUpgrade';

const quote = {
  courseRevision: 4,
  alreadyUpgraded: false,
  chatAvailable: false,
  certificateAvailable: false,
  aiIncluded: false,
  price: 120,
  totalBalance: 200,
  spendableBalance: 200,
  deficit: 0,
  rewardContributionCap: 0,
  packages: [],
  targetPlanCode: 'guided',
  targetPlanName: 'المتابعة',
};

describe('course chat entitlement refresh', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGetUpgradeQuote.mockResolvedValue(quote);
    mockPurchaseUpgrade.mockResolvedValue({
      ...quote,
      alreadyUpgraded: true,
      chatAvailable: true,
    });
  });

  it('keeps chat available immediately and refreshes the full course contract after upgrade', async () => {
    const onEntitlementChanged = jest.fn(async () => undefined);
    let hook!: ReturnType<typeof useCourseChatUpgrade>;
    const Harness = () => {
      hook = useCourseChatUpgrade({
        accountKey: 'account-a',
        accessType: 'paid',
        chatAvailable: false,
        courseId: '3',
        onEntitlementChanged,
        onOpenWallet: jest.fn(),
      });
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      await hook.loadUpgradeQuote();
    });
    await act(async () => {
      await hook.confirmUpgrade();
    });

    expect(hook.upgraded).toBe(true);
    expect(onEntitlementChanged).toHaveBeenCalledTimes(1);
    expect(mockPurchaseUpgrade).toHaveBeenCalledWith('3', 'guided', 120, 4);

    await act(async () => renderer.unmount());
  });

  it('does not refresh the next account from an upgrade that settled late', async () => {
    let finishPurchase!: (value: typeof quote) => void;
    mockPurchaseUpgrade.mockReturnValue(
      new Promise(resolve => {
        finishPurchase = resolve;
      }),
    );
    const onEntitlementChanged = jest.fn();
    let hook!: ReturnType<typeof useCourseChatUpgrade>;
    const Harness = ({accountKey}: {accountKey: string}) => {
      hook = useCourseChatUpgrade({
        accountKey,
        accessType: 'paid',
        chatAvailable: false,
        courseId: '3',
        onEntitlementChanged,
        onOpenWallet: jest.fn(),
      });
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness accountKey="account-a" />);
    });
    await act(async () => {
      await hook.loadUpgradeQuote();
    });
    let purchase!: Promise<void>;
    await act(async () => {
      purchase = hook.confirmUpgrade();
      await Promise.resolve();
    });
    await act(async () => {
      renderer.update(<Harness accountKey="account-b" />);
    });
    await act(async () => {
      finishPurchase({...quote, alreadyUpgraded: true, chatAvailable: true});
      await purchase;
    });

    expect(hook.upgraded).toBe(false);
    expect(onEntitlementChanged).not.toHaveBeenCalled();

    await act(async () => renderer.unmount());
  });

  it('refreshes the course contract when a lost purchase reply is recovered by the quote', async () => {
    mockGetUpgradeQuote
      .mockResolvedValueOnce(quote)
      .mockResolvedValueOnce({
        ...quote,
        alreadyUpgraded: true,
        chatAvailable: true,
      });
    mockPurchaseUpgrade.mockRejectedValue(new Error('timeout'));
    const onEntitlementChanged = jest.fn();
    let hook!: ReturnType<typeof useCourseChatUpgrade>;
    const Harness = () => {
      hook = useCourseChatUpgrade({
        accountKey: 'account-a',
        accessType: 'paid',
        chatAvailable: false,
        courseId: '3',
        onEntitlementChanged,
        onOpenWallet: jest.fn(),
      });
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      await hook.loadUpgradeQuote();
    });
    await act(async () => {
      await hook.confirmUpgrade();
    });

    expect(hook.upgraded).toBe(true);
    expect(hook.upgradeError).toBe('');
    expect(onEntitlementChanged).toHaveBeenCalledTimes(1);

    await act(async () => renderer.unmount());
  });
});
