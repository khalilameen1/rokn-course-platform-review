import {roknApiUrl} from '../constants/apiBaseUrl';

export const isCoursePaymentUrl = (value: string): boolean => {
  try {
    const url = new URL(value);
    const api = new URL(roknApiUrl);
    const prefix = api.pathname.replace(/api\/v1\/$/, '');
    return (
      url.protocol === 'https:' &&
      url.origin === api.origin &&
      !url.username &&
      !url.password &&
      !url.hash &&
      url.pathname.startsWith(`${prefix}course-payment/`) &&
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
        url.pathname.slice(`${prefix}course-payment/`.length),
      ) &&
      /^\d+$/.test(url.searchParams.get('expires') || '') &&
      /^[0-9a-f]{64}$/i.test(url.searchParams.get('signature') || '')
    );
  } catch {
    return false;
  }
};
