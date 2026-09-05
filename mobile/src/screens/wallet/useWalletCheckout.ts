import {useCallback, useEffect, useRef, useState} from 'react';
import {
  CommonActions,
  useIsFocused,
  useNavigation,
  useRoute,
} from '@react-navigation/native';
import {Alert} from 'react-native';
import type {RootNavigation, RootRoute} from '../../navigation/types';
import type {LoginReturnTo} from '../../navigation/types';
import {
  acknowledgePendingCheckoutReturn,
  claimPendingCheckoutReturn,
} from '../../navigation/checkoutReturn';
import {
  openCoinCheckout,
  subscribeCoinCheckoutCredits,
} from '../../services/coinCheckout';
import type {CoinPackage} from '../../services/api/coinPackageMapper';
import {
  coinCheckoutFailureDisposition,
  coinCheckoutOutcome,
} from '../../services/coinCheckoutTypes';
import {CAN_START_NATIVE_CHECKOUT} from '../../constants/distribution';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {formatArabicNumber} from '../../constants/arabicFormatting';
import {errorCode} from '../../utils/errorPayload';
import type {WalletData} from './useWalletData';

const sameReturnDestination = (left: LoginReturnTo, right: LoginReturnTo) =>
  left.name === right.name &&
  JSON.stringify(left.params ?? null) === JSON.stringify(right.params ?? null);

type WalletCheckoutData = Pick<
  WalletData,
  'identityKey' | 'invalidatePackages' | 'ownsBoundary' | 'refreshAfterCurrent'
>;

export const useWalletCheckout = (data: WalletCheckoutData) => {
  const {identityKey, invalidatePackages, ownsBoundary, refreshAfterCurrent} =
    data;
  const navigation = useNavigation<RootNavigation>();
  const route = useRoute<RootRoute<'Wallet'>>();
  const focused = useIsFocused();
  const interruptedReturnTo = route.params?.returnTo;
  const [loadingPackageId, setLoadingPackageId] = useState<string | null>(null);
  const checkoutFlightRef = useRef<symbol | null>(null);
  const identityRef = useRef(identityKey);
  const focusedRef = useRef(focused);
  const returnToRef = useRef(interruptedReturnTo);
  identityRef.current = identityKey;
  focusedRef.current = focused;
  returnToRef.current = interruptedReturnTo;

  useEffect(() => {
    checkoutFlightRef.current = null;
    setLoadingPackageId(null);
  }, [identityKey]);

  const navigateToInterruptedJourney = useCallback(
    (destination: LoginReturnTo) => {
      navigation.setParams({returnTo: undefined});
      navigation.dispatch(
        CommonActions.navigate(destination.name, destination.params),
      );
    },
    [navigation],
  );

  const finishRecoveredCheckout = useCallback(async () => {
    const destination = returnToRef.current;
    if (!destination || !focusedRef.current) return;
    const claim = await claimPendingCheckoutReturn().catch(() => undefined);
    if (!claim || !sameReturnDestination(claim.returnTo, destination)) return;

    // Only the durable hand-off which was actually claimed may navigate. A
    // credit from a direct top-up must never revive an unrelated old route.
    const acknowledged = await acknowledgePendingCheckoutReturn(claim).catch(
      () => false,
    );
    if (!acknowledged || !focusedRef.current) return;
    navigateToInterruptedJourney(destination);
  }, [navigateToInterruptedJourney]);

  const handleRecoveredCredit = useCallback(
    async (creditedOwnerScope?: string) => {
      const operationIdentity = identityRef.current;
      let boundary: AccountSessionBoundary;
      try {
        boundary = await captureAccountSessionBoundary();
      } catch {
        return;
      }
      if (
        (creditedOwnerScope && boundary.scope !== creditedOwnerScope) ||
        identityRef.current !== operationIdentity
      ) {
        return;
      }
      await refreshAfterCurrent();
      if (
        identityRef.current !== operationIdentity ||
        !ownsBoundary(boundary)
      ) {
        return;
      }
      await finishRecoveredCheckout();
    },
    [finishRecoveredCheckout, ownsBoundary, refreshAfterCurrent],
  );

  useEffect(
    () =>
      subscribeCoinCheckoutCredits((_result, ownerScope) => {
        void handleRecoveredCredit(ownerScope);
      }),
    [handleRecoveredCredit],
  );

  useEffect(() => {
    if (!CAN_START_NATIVE_CHECKOUT) return undefined;
    let active = true;
    let unsubscribe: () => void = () => undefined;
    void import('../../services/nativeStoreBilling').then(storeBilling => {
      if (!active) return;
      unsubscribe = storeBilling.subscribeNativeStoreCredits(() => {
        void handleRecoveredCredit();
      });
    });
    return () => {
      active = false;
      unsubscribe();
    };
  }, [handleRecoveredCredit]);

  const startCheckout = useCallback(
    async (item: CoinPackage) => {
      let boundary: AccountSessionBoundary;
      try {
        boundary = await captureAccountSessionBoundary();
      } catch {
        Alert.alert('تعذّر فتح الدفع', 'تحقق من الاتصال\nثم حاول مرة أخرى');
        return;
      }
      if (checkoutFlightRef.current) return;

      const operation = Symbol('wallet-checkout');
      checkoutFlightRef.current = operation;
      setLoadingPackageId(item.id);
      const destination = returnToRef.current;
      try {
        assertAccountSessionBoundary(boundary);
        const result = await openCoinCheckout(item, {
          ...(destination ? {returnTo: destination} : {}),
        });
        if (!ownsBoundary(boundary)) return;

        const outcome = coinCheckoutOutcome(result);
        if (outcome === 'paid') {
          await refreshAfterCurrent();
          if (!ownsBoundary(boundary)) return;
          Alert.alert(
            'تم شحن الرصيد',
            `أضفنا ${formatArabicNumber(result.coinsAdded)} عملة ركن إلى رصيدك`,
          );
          if (destination) navigateToInterruptedJourney(destination);
        } else if (outcome === 'pending') {
          Alert.alert('لم يكتمل الدفع بعد', 'سنراجع العملية تلقائيًا');
        } else {
          Alert.alert('لم يكتمل الدفع', 'يمكنك المحاولة مرة أخرى');
        }
      } catch (error: unknown) {
        if (!ownsBoundary(boundary)) return;
        const code = errorCode(error);
        const failure = coinCheckoutFailureDisposition(code);
        const packageCatalogueChanged = failure === 'catalogue_changed';
        const checkoutUnavailable = failure === 'opening_unavailable';
        if (packageCatalogueChanged) {
          await invalidatePackages(boundary);
          await refreshAfterCurrent();
          if (!ownsBoundary(boundary)) return;
        } else if (!checkoutUnavailable) {
          void refreshAfterCurrent();
        }
        Alert.alert(
          packageCatalogueChanged
            ? 'تغيّرت تفاصيل الباقة'
            : checkoutUnavailable
            ? 'الدفع متوقف مؤقتًا'
            : 'تعذّر تأكيد حالة الدفع',
          packageCatalogueChanged
            ? 'راجع باقات الشحن\nثم اختر الباقة من جديد'
            : checkoutUnavailable
            ? 'حاول لاحقًا'
            : 'حدّث الرصيد قبل المحاولة مرة أخرى',
        );
      } finally {
        if (checkoutFlightRef.current === operation) {
          checkoutFlightRef.current = null;
          setLoadingPackageId(null);
        }
      }
    },
    [
      invalidatePackages,
      navigateToInterruptedJourney,
      ownsBoundary,
      refreshAfterCurrent,
    ],
  );

  return {checkoutLoading: loadingPackageId, startCheckout};
};
