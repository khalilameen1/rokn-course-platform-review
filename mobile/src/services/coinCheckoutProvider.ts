import {NativeModules, Platform} from 'react-native';
import * as WebBrowser from 'expo-web-browser';

type CheckoutNativeModule = {
  open: (url: string) => Promise<string>;
};

export type CoinCheckoutCallback = {
  valid: boolean;
  status?: string;
  orderRef?: string;
  coins: number;
};

const nativeCheckout = NativeModules.RoknCheckout as
  | CheckoutNativeModule
  | undefined;

export const parseCoinCheckoutCallback = (
  value: string,
): CoinCheckoutCallback => {
  try {
    const callback = String(value || '').trim();
    const match = callback.match(/^rokn:\/\/payment-result(?:\?([^#]*))?$/i);
    if (!match) return {valid: false, coins: 0};

    const params = (match[1] || '')
      .split('&')
      .filter(Boolean)
      .reduce<Record<string, string>>((result, pair) => {
        const [rawKey, ...rawValue] = pair.split('=');
        if (!rawKey) return result;
        const key = decodeURIComponent(rawKey);
        if (Object.prototype.hasOwnProperty.call(result, key)) {
          throw new Error('PAYMENT_CALLBACK_DUPLICATE_FIELD');
        }
        result[key] = decodeURIComponent(rawValue.join('=') || '');
        return result;
      }, {});

    const status = params.status?.toLowerCase();
    const orderRef = params.order_ref || '';
    const coins = Number(params.coins || 0);
    if (
      !['success', 'pending', 'failed'].includes(status || '') ||
      !/^[a-zA-Z0-9_-]{8,100}$/.test(orderRef) ||
      !Number.isSafeInteger(coins) ||
      coins < 0
    ) {
      return {valid: false, coins: 0};
    }
    return {valid: true, status, orderRef, coins};
  } catch {
    return {valid: false, coins: 0};
  }
};

export const openCoinCheckoutSurface = async (url: string): Promise<string> => {
  if (nativeCheckout?.open) return nativeCheckout.open(url);
  if (/^https:\/\/checkout\.kashier\.io(?:\/|\?|$)/i.test(url)) {
    const result = await WebBrowser.openAuthSessionAsync(
      url,
      'rokn://payment-result',
      {showInRecents: true},
    );
    if (result.type === 'success') return result.url;
    if (result.type === 'cancel' || result.type === 'dismiss') {
      const cancelled = new Error('Checkout cancelled') as Error & {
        code?: string;
      };
      cancelled.code = 'CHECKOUT_CANCELLED';
      throw cancelled;
    }
    return '';
  }
  throw new Error(`In-app checkout is unavailable on ${Platform.OS}`);
};
