import {
  DEFAULT_REWARDS_HELP,
  mapRewardWallet,
} from '../src/services/api/rewardWalletMapper';

const row = (overrides: Record<string, unknown> = {}) => ({
  id: 'tx-1',
  amount: 50,
  reward_coins: 50,
  direction: 'credit',
  bucket: 'reward',
  label_ar: 'هدية ترحيبية',
  category: 'welcome_bonus',
  ...overrides,
});
describe('reward ledger projection', () => {
  it('reads the existing server ledger without showing purchased funds as rewards', () => {
    const result = mapRewardWallet(
      undefined,
      [
        row(),
        row({id: 'paid', amount: 200, reward_coins: 0, bucket: 'paid'}),
        row({
          id: 'mixed',
          amount: 100,
          reward_coins: 20,
          bucket: 'mixed',
          direction: 'debit',
          category: 'course_purchase',
        }),
      ],
      30,
    );
    expect(result.transactions.map(item => item.amount)).toEqual([50, -20]);
    expect(result.transactions[0]).toMatchObject({
      label: 'مكافأة الترحيب',
      category: 'welcome_bonus',
    });
    expect(result.rules).toEqual(DEFAULT_REWARDS_HELP);
  });
  it('prefers the reward-only server history and dashboard help', () => {
    const result = mapRewardWallet(
      {balance: 30, help: 'شرح مختصر\nقبل الدفع', recent_transactions: [row()]},
      [],
      30,
    );
    expect(result.transactions).toHaveLength(1);
    expect(result.rules).toEqual(['شرح مختصر', 'قبل الدفع']);
  });
  it.each([
    {balance: 99, help: 'شرح', recent_transactions: []},
    {balance: 30, help: '', recent_transactions: []},
    {balance: 30, help: 'شرح', recent_transactions: [row(), row()]},
    {balance: 30, help: 'شرح', recent_transactions: [row({amount: -1})]},
    {
      balance: 30,
      help: 'شرح',
      recent_transactions: [row({direction: 'unknown'})],
    },
  ])('rejects inconsistent financial projections', projection => {
    expect(() => mapRewardWallet(projection, [], 30)).toThrow();
  });
});
