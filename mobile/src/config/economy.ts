export const selectSmallestSufficientPackage = <T extends {coins: number}>(
  packages: T[],
  shortfall: number,
): T | undefined => {
  const ordered = packages
    .filter(item => Number.isFinite(item.coins) && item.coins > 0)
    .slice()
    .sort((left, right) => left.coins - right.coins);

  return ordered.find(item => item.coins >= Math.max(0, shortfall));
};
