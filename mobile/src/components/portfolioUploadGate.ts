import {Alert} from 'react-native';
import {subscriptionMessages} from '../constants/subscriptionMessages';
import {errorCode, errorPayload} from '../utils/errorPayload';

export type PortfolioSubscriptionAction = (hasSubscription: boolean) => void;

/** Returns false for unrelated errors so the caller keeps its normal recovery. */
export const showPortfolioUploadGate = (
  error: unknown,
  onSubscriptions?: PortfolioSubscriptionAction,
  beforeNavigate?: () => void,
): boolean => {
  if (errorCode(error) !== 'PORTFOLIO_CERTIFICATE_SUBSCRIPTION_REQUIRED')
    return false;
  const hasSubscription = errorPayload(error).has_subscription === true;
  const message = hasSubscription
    ? subscriptionMessages.portfolioUpgrade
    : subscriptionMessages.portfolioSubscribe;
  Alert.alert(message.title, message.body, [
    {text: 'إغلاق', style: 'cancel'},
    ...(onSubscriptions
      ? [
          {
            text: message.action,
            onPress: () => {
              beforeNavigate?.();
              onSubscriptions(hasSubscription);
            },
          },
        ]
      : []),
  ]);
  return true;
};
