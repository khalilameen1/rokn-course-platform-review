import {isApiRecord, requireNonNegativeNumber} from './common';

export const DEFAULT_REWARDS_HELP = [
  'اكسب عملات من المهام واستخدمها للحصول على خصم على اشتراك الكورس',
  'قيمة الخصم والمبلغ المطلوب يظهران لك قبل الدفع',
];

export type RewardTransaction = {
  id: string;
  amount: number;
  label: string;
  category?: string;
  occurred_at?: string;
};

// An additive projection: older servers still supply reward_coins on ledger
// entries. Never count paid coins as rewards, even in a mixed course debit.
export const mapRewardWallet = (
  projection: unknown,
  ledger: unknown[],
  rewardBalance: number,
) => {
  if (
    projection !== undefined &&
    (!isApiRecord(projection) ||
      requireNonNegativeNumber(projection.balance, 'REWARDS_BALANCE') !==
        rewardBalance ||
      typeof projection.help !== 'string' ||
      !projection.help.trim() ||
      !Array.isArray(projection.recent_transactions))
  ) {
    throw new Error('API_CONTRACT_INVALID_REWARDS');
  }
  const current = isApiRecord(projection) ? projection : null;
  const rows = current
    ? (current.recent_transactions as unknown[])
    : ledger.filter(
        item =>
          isApiRecord(item) &&
          (Number(item.reward_coins) > 0 || item.bucket === 'reward'),
      );
  const seen = new Set<string>();
  const transactions: RewardTransaction[] = rows.map(item => {
    if (
      !isApiRecord(item) ||
      typeof item.label_ar !== 'string' ||
      !item.label_ar.trim() ||
      !['credit', 'debit'].includes(String(item.direction)) ||
      (item.category != null && typeof item.category !== 'string') ||
      (item.occurred_at != null && typeof item.occurred_at !== 'string')
    ) {
      throw new Error('API_CONTRACT_INVALID_REWARD_TRANSACTION');
    }
    const id = String(item.id ?? '').trim();
    const amount = requireNonNegativeNumber(
      current ? item.amount : item.reward_coins ?? item.amount,
      'REWARD_TRANSACTION_AMOUNT',
    );
    if (!id || seen.has(id) || !Number.isSafeInteger(amount) || amount <= 0) {
      throw new Error('API_CONTRACT_INVALID_REWARD_TRANSACTION');
    }
    seen.add(id);
    const category =
      typeof item.category === 'string' ? item.category : undefined;
    return {
      id,
      amount: item.direction === 'debit' ? -amount : amount,
      label:
        category === 'welcome_bonus'
          ? 'مكافأة الترحيب'
          : category === 'course_purchase' && item.direction === 'debit'
          ? 'خصم على اشتراك كورس'
          : item.label_ar.trim(),
      category,
      occurred_at:
        typeof item.occurred_at === 'string' ? item.occurred_at : undefined,
    };
  });
  return {
    transactions,
    rules: current
      ? (current.help as string)
          .split(/\r?\n/)
          .map(line => line.trim())
          .filter(Boolean)
      : DEFAULT_REWARDS_HELP,
  };
};
