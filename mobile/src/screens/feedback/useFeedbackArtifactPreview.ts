import {useCallback, useEffect, useMemo, useRef, useState} from 'react';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {
  loadProductFeedbackCase,
  type ProductFeedbackArtifact,
  type ProductFeedbackCase,
} from '../../services/productFeedback';

type ViewerOwner = {identityKey: string; caseId: string; available: boolean};
type Preview = {
  owner: ViewerOwner;
  token: symbol;
  artifact: ProductFeedbackArtifact;
  imageKey: number;
  refreshing: boolean;
  failed: boolean;
  controller?: AbortController;
};

const findArtifact = (supportCase: ProductFeedbackCase, id: string) =>
  [
    ...supportCase.attachments,
    ...supportCase.messages.flatMap(message => message.attachments),
  ].find(artifact => artifact.id === id);

const usableLink = (artifact: ProductFeedbackArtifact) =>
  Date.parse(artifact.expiresAt) > Date.now() + 30_000;

/**
 * Like Rocket.Chat's attachment screen, the viewer owns its loading lifecycle,
 * not the conversation history. Signed-link renewal is Rokn-specific binding.
 */
export const useFeedbackArtifactPreview = (
  identityKey: string,
  selectedCase: ProductFeedbackCase | undefined,
  focused: boolean,
) => {
  const active = useAppForegroundState();
  const caseId = selectedCase?.publicId || '';
  const owner = useMemo<ViewerOwner>(
    () => ({identityKey, caseId, available: focused && active && !!caseId}),
    [identityKey, caseId, focused, active],
  );
  const ownerRef = useRef(owner);
  const caseRef = useRef(selectedCase);
  ownerRef.current = owner;
  caseRef.current = selectedCase;
  const mountedRef = useRef(true);
  const sessionRef = useRef<Preview | undefined>(undefined);
  const imageSequenceRef = useRef(0);
  const [preview, setPreview] = useState<Preview>();

  const owns = useCallback(
    (candidate: Preview) =>
      mountedRef.current &&
      candidate.owner.available &&
      ownerRef.current === candidate.owner &&
      sessionRef.current?.token === candidate.token,
    [],
  );
  const dismiss = useCallback((publish = true) => {
    sessionRef.current?.controller?.abort();
    sessionRef.current = undefined;
    if (publish && mountedRef.current) setPreview(undefined);
  }, []);
  const publish = (next: Preview) => {
    sessionRef.current = next;
    setPreview(next);
  };

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      dismiss(false);
    };
  }, [dismiss]);
  useEffect(() => {
    dismiss();
    return () => dismiss(false);
  }, [owner, dismiss]);

  const displayed = preview && owns(preview) ? preview : undefined;

  const openArtifact = async (
    artifact: ProductFeedbackArtifact,
    forceRefresh = false,
  ) => {
    // A retained callback from another case/account cannot borrow the new owner.
    const supportCase = caseRef.current;
    if (
      !mountedRef.current ||
      ownerRef.current !== owner ||
      !owner.available ||
      !supportCase ||
      supportCase.publicId !== owner.caseId
    )
      return;
    const current = sessionRef.current;
    if (
      current &&
      owns(current) &&
      current.artifact.id === artifact.id &&
      current.refreshing
    )
      return;
    if (
      forceRefresh &&
      (!displayed || !owns(displayed) || displayed.artifact.id !== artifact.id)
    )
      return;
    const canonical = findArtifact(supportCase, artifact.id);
    if (!canonical) return;
    // Ignore a caller's URL; only the current case's authorized descriptor opens.
    const needsRefresh = forceRefresh || !usableLink(canonical);
    dismiss(false);
    const next: Preview = {
      owner,
      token: Symbol('support-attachment-viewer'),
      artifact: canonical,
      imageKey: ++imageSequenceRef.current,
      refreshing: needsRefresh,
      failed: false,
      controller: needsRefresh ? new AbortController() : undefined,
    };
    publish(next);
    if (!needsRefresh) return;
    try {
      const boundary = await captureAccountSessionBoundary();
      if (!owns(next) || next.controller?.signal.aborted) return;
      assertAccountSessionBoundary(boundary);
      const refreshed = await loadProductFeedbackCase(
        owner.caseId,
        supportCase.accessToken,
        boundary,
        next.controller?.signal,
      );
      assertAccountSessionBoundary(boundary);
      if (!owns(next) || next.controller?.signal.aborted) return;
      if (refreshed.publicId !== owner.caseId)
        throw new Error('SUPPORT_ATTACHMENT_CASE_CHANGED');
      const renewed = findArtifact(refreshed, canonical.id);
      if (!renewed || !usableLink(renewed))
        throw new Error('SUPPORT_ATTACHMENT_UNAVAILABLE');
      // Never replace history with a renewal read: a reply may have landed since
      // that GET began. Only this viewer consumes the refreshed descriptor.
      publish({
        ...next,
        artifact: renewed,
        imageKey: ++imageSequenceRef.current,
        refreshing: false,
        controller: undefined,
      });
    } catch (error: unknown) {
      if (!owns(next) || next.controller?.signal.aborted) return;
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        dismiss();
        return;
      }
      publish({
        ...next,
        refreshing: false,
        failed: true,
        controller: undefined,
      });
    }
  };

  return {
    openArtifact,
    closeArtifact: () => {
      if (displayed && owns(displayed)) dismiss();
    },
    markArtifactLoadFailed: (artifactId: string) => {
      const current = sessionRef.current;
      // Native Image can deliver an old onError after renewal or reopening the
      // same attachment. ID alone does not identify a native loading attempt.
      if (
        displayed &&
        current &&
        owns(displayed) &&
        current.imageKey === displayed.imageKey &&
        current.artifact.id === artifactId &&
        !current.refreshing
      )
        publish({...current, failed: true});
    },
    previewArtifact: displayed?.artifact,
    previewLoadFailed: displayed?.failed || false,
    previewBusy: displayed?.refreshing || false,
    previewRequestKey: displayed?.imageKey,
  };
};
