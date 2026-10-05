import {useCallback, useEffect, useRef, useState} from 'react';

import {removeLearnerDraftFile} from '../../services/learnerDraftFiles';
import type {FeedbackAttachment} from '../../services/productFeedback';
import {pickFeedbackScreenshot} from './pickFeedbackScreenshot';

type Options = {
  ownerKey: string;
  canPrepare: () => boolean;
  onPrepared: (attachment: FeedbackAttachment) => void;
};

/** A prepared attachment belongs to one draft, never to the next screen/account. */
export const useFeedbackScreenshotPreparation = (options: Options) => {
  const latest = useRef(options);
  latest.current = options;
  const mounted = useRef(true);
  const flight = useRef<symbol | null>(null);
  const [preparation, setPreparation] = useState<{
    ownerKey: string;
    token: symbol;
  } | null>(null);

  const invalidate = useCallback(() => {
    flight.current = null;
    setPreparation(null);
  }, []);

  useEffect(() => {
    invalidate();
  }, [invalidate, options.ownerKey]);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      flight.current = null;
    };
  }, []);

  const ownerKey = options.ownerKey;
  const choose = async () => {
    if (
      !mounted.current ||
      latest.current.ownerKey !== ownerKey ||
      flight.current ||
      !latest.current.canPrepare()
    )
      return;

    const token = Symbol('feedback-screenshot');
    flight.current = token;
    setPreparation({ownerKey, token});
    let selected: FeedbackAttachment | undefined;
    let adopted = false;
    const isCurrent = () =>
      mounted.current &&
      latest.current.ownerKey === ownerKey &&
      flight.current === token;
    try {
      selected = await pickFeedbackScreenshot(isCurrent);
      if (!selected) return;
      if (isCurrent() && latest.current.canPrepare()) {
        // Commit the draft snapshot synchronously before unlocking send.
        latest.current.onPrepared(selected);
        adopted = true;
      }
    } finally {
      if (selected && !adopted)
        await removeLearnerDraftFile(selected).catch(() => undefined);
      // An old picker must not unlock another draft's preparation.
      if (flight.current === token) {
        flight.current = null;
        if (mounted.current) setPreparation(null);
      }
    }
  };

  return {
    choose,
    invalidate,
    isPreparing: () => flight.current !== null,
    preparing: preparation?.ownerKey === ownerKey,
  };
};
