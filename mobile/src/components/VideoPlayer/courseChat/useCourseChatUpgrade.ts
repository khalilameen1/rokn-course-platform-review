import {useEffect, useRef, useState} from 'react';
import {
  getFullTrackUpgradeQuote,
  purchaseFullTrackUpgrade,
  type CourseChatUpgradeQuote,
} from '../../../services/roknApi';
import {courseChatErrorCode} from './policy';

export const useCourseChatUpgrade = ({
  accountKey,
  accessType,
  chatAvailable,
  courseId,
  onEntitlementChanged,
  onOpenWallet,
}: {
  accountKey: string;
  accessType?: string;
  chatAvailable?: boolean;
  courseId: string;
  onEntitlementChanged: () => void | Promise<void>;
  onOpenWallet: () => void;
}) => {
  const [serverBlockCode, setServerBlockCode] = useState('');
  const [upgraded, setUpgraded] = useState(false);
  const [upgradeQuote, setUpgradeQuote] =
    useState<CourseChatUpgradeQuote | null>(null);
  const [upgradeLoading, setUpgradeLoading] = useState(false);
  const [upgradeError, setUpgradeError] = useState('');
  const activeCourseIdRef = useRef(courseId);
  const flightRef = useRef<symbol | null>(null);
  const generationRef = useRef(0);
  activeCourseIdRef.current = courseId;

  useEffect(() => {
    generationRef.current += 1;
    flightRef.current = null;
    setServerBlockCode('');
    setUpgraded(false);
    setUpgradeQuote(null);
    setUpgradeError('');
    setUpgradeLoading(false);
    return () => {
      flightRef.current = null;
    };
  }, [accountKey, accessType, chatAvailable, courseId]);

  const ownsRequest = (generation: number) =>
    activeCourseIdRef.current === courseId &&
    generationRef.current === generation;

  const recordServerBlock = (code?: string) => {
    setServerBlockCode(code || 'chat_upgrade_required');
  };

  const acceptAvailableChat = () => {
    setUpgraded(true);
    setServerBlockCode('');
    setUpgradeQuote(null);
    // The upgrade response is intentionally small. Refresh the authoritative
    // course aggregate so attachments and every project feedback capability
    // move to the purchased plan without waiting for a relaunch.
    void Promise.resolve(onEntitlementChanged()).catch(() => undefined);
  };

  const loadUpgradeQuote = async () => {
    if (upgradeLoading || flightRef.current) return;
    const flight = Symbol('course-chat-upgrade-quote');
    const generation = generationRef.current;
    flightRef.current = flight;
    setUpgradeLoading(true);
    setUpgradeError('');
    try {
      const quote = await getFullTrackUpgradeQuote(courseId);
      if (!ownsRequest(generation)) return;
      if (quote.alreadyUpgraded || quote.chatAvailable) {
        acceptAvailableChat();
        return;
      }
      setUpgradeQuote(quote);
    } catch (error: unknown) {
      if (!ownsRequest(generation)) return;
      const code = courseChatErrorCode(error);
      setUpgradeError(
        code === 'chat_upgrade_not_priced' ||
          code === 'full_track_upgrade_not_priced'
          ? 'الترقية غير متاحة لهذا الكورس الآن'
          : code === 'course_access_required'
          ? 'افتح الكورس أولًا ثم عد إلى الاستفسارات'
          : 'تعذّر تحميل تفاصيل الترقية\nحاول مرة أخرى',
      );
    } finally {
      if (
        generationRef.current === generation &&
        flightRef.current === flight
      ) {
        flightRef.current = null;
        setUpgradeLoading(false);
      }
    }
  };

  const confirmUpgrade = async () => {
    if (!upgradeQuote || upgradeLoading || flightRef.current) return;
    if (upgradeQuote.deficit > 0) {
      onOpenWallet();
      return;
    }
    const flight = Symbol('course-chat-upgrade-purchase');
    const generation = generationRef.current;
    flightRef.current = flight;
    setUpgradeLoading(true);
    setUpgradeError('');
    try {
      const result = await purchaseFullTrackUpgrade(
        courseId,
        upgradeQuote.targetPlanCode,
        upgradeQuote.price,
        upgradeQuote.courseRevision,
      );
      if (!ownsRequest(generation)) return;
      if (result.alreadyUpgraded || result.chatAvailable) {
        acceptAvailableChat();
        return;
      }
      setUpgradeQuote(result);
    } catch (error: unknown) {
      if (!ownsRequest(generation)) return;
      const code = courseChatErrorCode(error);
      setUpgradeError(
        code === 'course_price_changed' || code === 'course_terms_changed'
          ? 'تغيرت تفاصيل الفئة\nراجعها قبل الترقية'
          : code === 'insufficient_coins'
          ? 'تغيّر الرصيد المتاح\nراجع المبلغ الناقص قبل الترقية'
          : 'تعذّر تأكيد النتيجة\nنتحقق منها الآن',
      );
      try {
        const refreshedQuote = await getFullTrackUpgradeQuote(courseId);
        if (!ownsRequest(generation)) return;
        if (refreshedQuote.alreadyUpgraded || refreshedQuote.chatAvailable) {
          acceptAvailableChat();
          setUpgradeError('');
          return;
        }
        setUpgradeQuote(refreshedQuote);
        setUpgradeError(
          code === 'course_price_changed' || code === 'course_terms_changed'
            ? 'تغيرت تفاصيل الفئة\nراجعها قبل الترقية'
            : code === 'insufficient_coins'
            ? 'تغيّر الرصيد المتاح\nراجع المبلغ الناقص قبل الترقية'
            : 'لم تكتمل العملية\nحاول مرة أخرى',
        );
      } catch {
        if (!ownsRequest(generation)) return;
        setUpgradeError(
          'تعذّر تأكيد النتيجة\nحدّث الصفحة قبل المحاولة مرة أخرى',
        );
      }
    } finally {
      if (
        generationRef.current === generation &&
        flightRef.current === flight
      ) {
        flightRef.current = null;
        setUpgradeLoading(false);
      }
    }
  };

  return {
    confirmUpgrade,
    loadUpgradeQuote,
    recordServerBlock,
    serverBlockCode,
    upgraded,
    upgradeError,
    upgradeLoading,
    upgradeQuote,
  };
};
