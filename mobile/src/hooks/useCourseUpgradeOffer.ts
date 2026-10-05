import {useCallback, useEffect, useRef, useState} from 'react';
import {getFullTrackUpgradeQuote} from '../services/roknApi';
import type {CourseCheckoutFeature} from '../services/api/courseCheckout';

export type CourseUpgradeOfferStatus =
  | 'idle'
  | 'loading'
  | 'available'
  | 'unavailable'
  | 'error';

/** GET-only discovery. The existing subscription sheet owns purchase/recovery. */
export const useCourseUpgradeOffer = ({
  ownerKey,
  courseId,
  requiredFeature,
  active,
}: {
  ownerKey: string;
  courseId: string;
  requiredFeature: CourseCheckoutFeature;
  active: boolean;
}) => {
  const scope = JSON.stringify([ownerKey, courseId, requiredFeature]);
  const ownerRef = useRef({scope, active});
  if (ownerRef.current.scope !== scope || ownerRef.current.active !== active) {
    // Retire a closed or replaced visit at render, before effect cleanup.
    ownerRef.current = {scope, active};
  }
  const owner = ownerRef.current;
  const [retryRevision, setRetryRevision] = useState(0);
  const [decision, setDecision] = useState<{
    owner: typeof owner;
    status: CourseUpgradeOfferStatus;
  }>({owner, status: 'idle'});

  useEffect(() => {
    if (!owner.active || !courseId) return;
    let cancelled = false;
    const ownsOffer = () => !cancelled && ownerRef.current === owner;
    setDecision({owner, status: 'loading'});
    void getFullTrackUpgradeQuote(courseId, {requiredFeature})
      .then(quote => {
        if (!ownsOffer()) return;
        if (
          typeof quote.upgradeAvailable !== 'boolean' ||
          !Array.isArray(quote.availablePlanCodes) ||
          quote.upgradeAvailable !== (quote.availablePlanCodes.length > 0)
        ) {
          throw new Error('COURSE_UPGRADE_AVAILABILITY_MISSING');
        }
        setDecision({
          owner,
          status:
            !quote.alreadyUpgraded && quote.upgradeAvailable
              ? 'available'
              : 'unavailable',
        });
      })
      .catch(() => {
        if (ownsOffer()) setDecision({owner, status: 'error'});
      });
    return () => {
      cancelled = true;
    };
  }, [courseId, owner, requiredFeature, retryRevision]);

  const retry = useCallback(() => {
    if (ownerRef.current === owner && owner.active) {
      setRetryRevision(value => value + 1);
    }
  }, [owner]);
  const status: CourseUpgradeOfferStatus =
    !active || !courseId
      ? 'idle'
      : decision.owner === owner
      ? decision.status
      : 'loading';

  return {status, retry};
};
