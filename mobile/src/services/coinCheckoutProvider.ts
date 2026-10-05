import {AppState, Linking, NativeModules, Platform} from 'react-native';
import * as WebBrowser from 'expo-web-browser';
import {isCoursePaymentUrl} from './coursePaymentUrl';

type CheckoutNativeModule = {
  open: (url: string) => Promise<string>;
  openBrowser?: (url: string) => Promise<void>;
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

/** Returning without a provider callback means reconcile, not cancel.
 * Listeners are installed before launch; cold starts use the durable order. */
export const openCourseBrowserCheckoutSurface = (
  url: string,
): Promise<string> => {
  if (!isCoursePaymentUrl(url))
    return Promise.reject(new Error('PAYMENT_URL_INVALID'));
  if (Platform.OS !== 'android' || !nativeCheckout?.openBrowser) {
    return Promise.reject(new Error('CHECKOUT_BROWSER_UNAVAILABLE'));
  }
  return new Promise((resolve, reject) => {
    let backgrounded = false;
    let finished = false;
    const finish = (value: string, error?: unknown) => {
      if (finished) return;
      finished = true;
      clearTimeout(timeout);
      links.remove();
      states.remove();
      if (error) reject(error);
      else resolve(value);
    };
    const links = Linking.addEventListener('url', event => {
      if (parseCoinCheckoutCallback(event.url).valid) finish(event.url);
    });
    const states = AppState.addEventListener('change', state => {
      if (state !== 'active') backgrounded = true;
      else if (backgrounded) finish('');
    });
    const timeout = setTimeout(() => finish(''), 30 * 60 * 1000);
    void nativeCheckout.openBrowser!(url).catch(error => finish('', error));
  });
};

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
