import AsyncStorage from '@react-native-async-storage/async-storage';
import {Alert, Linking} from 'react-native';
import {publicRequest} from '../constants/api';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {privacyPolicyUrl} from './publicLinks';

export const AI_CONSENT_VERSION = 'third-party-ai-v1';
const STORAGE_KEY = '@rokn/ai-consent/v1';
type ConsentRequest = {
  owners: Set<() => boolean>;
  result: Promise<boolean>;
};
const requests = new Map<string, ConsentRequest>();

const record = (value: unknown): Record<string, unknown> =>
  value !== null && typeof value === 'object'
    ? (value as Record<string, unknown>)
    : {};

export const isAiConsentRequired = (error: unknown) => {
  const root = record(error);
  return (
    root.code === 'ai_consent_required' ||
    record(root.data).code === 'ai_consent_required' ||
    record(record(root.response).data).code === 'ai_consent_required'
  );
};

const readConsent = (response: unknown) => {
  const payload = record(record(response).data);
  const data = record(payload.data);
  if (payload.success !== true || data.version !== AI_CONSENT_VERSION ||
      typeof data.accepted !== 'boolean') {
    throw new Error('AI_CONSENT_CONTRACT_INVALID');
  }
  return data;
};

const persist = async (data: Record<string, unknown>, boundary: AccountSessionBoundary) => {
  assertAccountSessionBoundary(boundary);
  const key = await accountScopedStorageKey(STORAGE_KEY, boundary);
  assertAccountSessionBoundary(boundary);
  // Local storage is only a receipt, never authority to share data. Server
  // consent survives reinstall; a guest receipt cannot migrate to an account.
  await AsyncStorage.setItem(key, JSON.stringify(data)).catch(() => undefined);
  assertAccountSessionBoundary(boundary);
};

export const hasAiConsent = async (boundary: AccountSessionBoundary) => {
  assertAccountSessionBoundary(boundary);
  if (!boundary.scope.startsWith('user-')) return false;
  const response = await publicRequest.get('ai-consent', {timeout: 12000});
  assertAccountSessionBoundary(boundary);
  const data = readConsent(response);
  await persist(data, boundary);
  return data.accepted === true;
};

const askPermission = (boundary: AccountSessionBoundary, canPresent: () => boolean) =>
  new Promise<boolean>(resolve => {
    Alert.alert(
      'الاستفسارات ومراجعة المشاريع',
      'تستخدم الاستفسارات ومراجعة المشاريع الذكاء الاصطناعي\nنرسل ما تكتبه ومرفقاتك وسياق الكورس والمحادثة إلى OpenRouter ومزودي النماذج للإجابة وتقييم مشروعك\nهل توافق؟',
      [
        {text: 'ليس الآن', style: 'cancel', onPress: () => resolve(false)},
        {text: 'سياسة الخصوصية', onPress: () => {
          resolve(false);
          if (!canPresent()) return;
          void Linking.openURL(privacyPolicyUrl).catch(() => undefined);
        }},
        {text: 'أوافق وأتابع', onPress: () => {
          try { assertAccountSessionBoundary(boundary); resolve(canPresent()); }
          catch { resolve(false); }
        }},
      ],
      {cancelable: true, onDismiss: () => resolve(false)},
    );
  });

/** One shared affirmative gate, only on a learner-initiated AI action. */
export const requestAiConsent = async (
  owner?: AccountSessionBoundary,
  isCurrent: () => boolean = () => true,
): Promise<boolean> => {
  const boundary = owner || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  if (!boundary.scope.startsWith('user-') || !isCurrent()) return false;
  const identity = `${boundary.scope}:${boundary.epoch}`;
  let request = requests.get(identity);
  if (!request) {
    const owners = new Set<() => boolean>();
    const canPresent = () => Array.from(owners).some(owns => owns());
    const flight = (async () => {
      try {
        if (await hasAiConsent(boundary)) return true;
        if (!canPresent() || !(await askPermission(boundary, canPresent))) return false;
        assertAccountSessionBoundary(boundary);
        const response = await publicRequest.put('ai-consent', {
          version: AI_CONSENT_VERSION, accepted: true,
        }, {timeout: 12000});
        assertAccountSessionBoundary(boundary);
        const data = readConsent(response);
        await persist(data, boundary);
        return data.accepted === true;
      } catch {
        try { assertAccountSessionBoundary(boundary); }
        catch { return false; }
        if (canPresent()) Alert.alert('لم يكتمل التأكيد', 'تأكد من الاتصال وحاول مرة أخرى');
        return false;
      }
    })().finally(() => {
      if (requests.get(identity)?.result === flight) requests.delete(identity);
    });
    request = {owners, result: flight};
    requests.set(identity, request);
  }
  // Same-account actions still share one affirmative prompt. Each caller owns
  // its own continuation; retiring one must not retire another visible action.
  const ownsPresentation = () => isCurrent();
  request.owners.add(ownsPresentation);
  try {
    if (!(await request.result) || !isCurrent()) return false;
    try { assertAccountSessionBoundary(boundary); return true; }
    catch { return false; }
  } finally {
    request.owners.delete(ownsPresentation);
  }
};

/** Background retries cannot open a consent dialog or infer acceptance. */
export const requireAiConsent = async (boundary: AccountSessionBoundary) => {
  if (!(await hasAiConsent(boundary))) {
    throw Object.assign(new Error('AI_CONSENT_REQUIRED'), {
      code: 'ai_consent_required', status: 403,
    });
  }
};

export const manageAiConsent = async () => {
  const boundary = await captureAccountSessionBoundary();
  try {
    if (!(await hasAiConsent(boundary))) {
      await requestAiConsent(boundary);
      return;
    }
    Alert.alert('مشاركة البيانات للمراجعة والاستفسارات',
      'المشاركة مفعّلة لحسابك\nيمكنك إيقاف إرسال أسئلة أو مشاريع جديدة للذكاء الاصطناعي دون حذف كورساتك أو ردودك المحفوظة', [
        {text: 'رجوع', style: 'cancel'},
        {text: 'إيقاف المشاركة', onPress: () => {
          void (async () => {
            assertAccountSessionBoundary(boundary);
            const response = await publicRequest.put('ai-consent', {
              version: AI_CONSENT_VERSION, accepted: false,
            }, {timeout: 12000});
            assertAccountSessionBoundary(boundary);
            const data = readConsent(response);
            if (data.accepted !== false) throw new Error('AI_CONSENT_REVOKE_FAILED');
            await persist(data, boundary);
            Alert.alert('تم إيقاف المشاركة', 'لن نرسل طلبات جديدة\nقد يكتمل طلب بدأ قبل الإيقاف');
          })().catch(() => {
            try { assertAccountSessionBoundary(boundary); }
            catch { return; }
            Alert.alert('لم يتغير اختيارك', 'تأكد من الاتصال وحاول مرة أخرى');
          });
        }},
      ]);
  } catch {
    try { assertAccountSessionBoundary(boundary); }
    catch { return; }
    Alert.alert('تعذّر تحميل اختيارك', 'تأكد من الاتصال وحاول مرة أخرى');
  }
};
