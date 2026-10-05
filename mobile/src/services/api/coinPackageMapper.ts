import {
  DISTRIBUTION_CHANNEL,
  type DistributionChannel,
} from '../../constants/distribution';
import {nonNegativeNumber, resourceList} from './common';

export type CoinPackage = {
  id: string;
  coins: number;
  price: number;
  label: string;
  displayPrice?: string;
  currency?: string;
  storeProductIds?: {
    google?: string;
    apple?: string;
  };
};

export type CoinPackageDto = {
  id?: unknown;
  coins?: unknown;
  price?: unknown;
  direct_price?: unknown;
  name?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  store_products?: {
    google?: unknown;
    apple?: unknown;
  };
  channels?: {
    direct?: unknown;
    google?: unknown;
    apple?: unknown;
  };
};

const firstNonEmptyText = (...values: unknown[]) =>
  values.map(value => String(value ?? '').trim()).find(Boolean) ?? '';

export const mapCoinPackages = (
  value: unknown,
  invalidContractCode: string,
  channel: DistributionChannel = DISTRIBUTION_CHANNEL,
): CoinPackage[] => {
  const candidates = resourceList<CoinPackageDto>(value);
  const channelEnabled = (item: CoinPackageDto) => {
    if (channel === 'direct') {
      return item.channels?.direct;
    }
    if (channel === 'play') {
      return item.channels?.google;
    }
    return item.channels?.apple;
  };
  const seenIds = new Set<string>();
  const malformed = candidates.some(item => {
    const id = String(item.id ?? '').trim();
    const coins = nonNegativeNumber(item.coins);
    const enabled = channelEnabled(item);
    const selectedPrice = nonNegativeNumber(
      channel === 'direct' ? item.direct_price : item.price,
    );
    const productId = String(
      channel === 'play'
        ? item.store_products?.google ?? ''
        : channel === 'appstore'
        ? item.store_products?.apple ?? ''
        : '',
    ).trim();
    const label = firstNonEmptyText(item.name_ar, item.name_en, item.name);
    if (
      !/^\d+$/.test(id) ||
      !Number.isSafeInteger(Number(id)) ||
      Number(id) <= 0 ||
      seenIds.has(id) ||
      coins === null ||
      !Number.isSafeInteger(coins) ||
      coins <= 0 ||
      !label ||
      typeof enabled !== 'boolean' ||
      (enabled && (selectedPrice === null || selectedPrice <= 0)) ||
      (enabled && channel !== 'direct' && !productId)
    ) {
      return true;
    }
    seenIds.add(id);
    return false;
  });
  if (malformed) {
    throw new Error(invalidContractCode);
  }
  const eligible = candidates.filter(item => channelEnabled(item) === true);
  const packages = eligible.map(item => {
    const id = String(item.id).trim();
    const coins = Number(item.coins);
    const price = Number(channel === 'direct' ? item.direct_price : item.price);
    return {
      id,
      coins,
      price,
      label: firstNonEmptyText(item.name_ar, item.name_en, item.name),
      storeProductIds: {
        google: item.store_products?.google
          ? String(item.store_products.google).trim()
          : undefined,
        apple: item.store_products?.apple
          ? String(item.store_products.apple).trim()
          : undefined,
      },
    };
  });

  return packages;
};
