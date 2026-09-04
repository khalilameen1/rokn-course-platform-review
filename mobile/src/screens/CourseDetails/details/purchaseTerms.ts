import type {CoinPackage} from '../../../services/api/coinPackageMapper';

export type PurchaseTermsInput = {
  balance: number;
  minimumPaidCoins: number;
  packages: CoinPackage[];
  paidBalance: number | null;
  price: number;
  rewardBalance: number | null;
  rewardContributionLimit: number;
};

/**
 * One calculation for every course checkout surface.
 * The server remains authoritative; this only presents its balance and plan
 * snapshot without letting coupon, top-up and confirm screens drift apart.
 */
export const derivePurchaseTerms = ({
  balance,
  minimumPaidCoins,
  packages,
  paidBalance,
  price,
  rewardBalance,
  rewardContributionLimit,
}: PurchaseTermsInput) => {
  const paid = paidBalance ?? 0;
  const reward = rewardBalance ?? Math.max(0, balance - paid);
  const allowedReward = Math.min(
    rewardContributionLimit,
    Math.max(0, price - minimumPaidCoins),
  );
  const spendable = paid + Math.min(reward, allowedReward);
  const shortfall = Math.max(0, price - spendable);
  const orderedPackages = packages
    .slice()
    .sort((left, right) => left.coins - right.coins);
  const sufficientPackages = orderedPackages.filter(
    item => item.coins >= shortfall,
  );

  return {
    paidBalance: paid,
    rewardBalance: reward,
    rewardContributionLimit: allowedReward,
    rewardContributionPercent:
      price > 0 ? Math.floor((allowedReward / price) * 100) : 0,
    shortfall,
    spendableBalance: spendable,
    sufficientPackage: sufficientPackages[0],
    sufficientPackages,
    packages: orderedPackages,
    usableCurrentBalance: Math.min(price, spendable),
  };
};
