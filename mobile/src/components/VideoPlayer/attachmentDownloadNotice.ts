import {Platform} from 'react-native';
import {useSyncExternalStore} from 'react';
import type {CourseAttachment} from './types';

type Notice = {
  id: number;
  title: string;
  size?: string;
  onCancel: () => void;
  cancelled: boolean;
  attachmentIdentity?: string;
  isCurrent: () => boolean;
  transferPending: boolean;
};
type Cycle = {
  id: number;
  notices: Map<number, Notice>;
  visible: boolean;
  requested: boolean;
  shown: boolean;
  closeRequested: boolean;
  dismissed: boolean;
  receipt: Promise<void>;
  resolve: () => void;
};
type Snapshot = {id: number; visible: boolean; notices: Notice[]} | null;
export type AttachmentDownloadNotice = {
  transferFinished: () => void;
  dismiss: () => Promise<void>;
  release: () => void;
};

let nextId = 0;
let cycle: Cycle | null = null;
let snapshot: Snapshot = null;
const listeners = new Set<() => void>();
const emit = () => {
  snapshot = cycle
    ? {
        id: cycle.id,
        visible: cycle.visible,
        notices: [...cycle.notices.values()],
      }
    : null;
  listeners.forEach(listener => listener());
};
const clearIfReleased = (owner: Cycle) => {
  if (cycle === owner && owner.dismissed && owner.notices.size === 0)
    cycle = null;
};
const dismiss = (owner: Cycle): Promise<void> => {
  owner.closeRequested = true;
  // A committed visible prop is not yet a native presentation. Keep that
  // request alive until onShow; otherwise Fabric can coalesce it away and
  // never emit the onDismiss receipt that the save handoff is awaiting.
  owner.visible = owner.requested && !owner.shown && !owner.dismissed;
  if (!owner.requested) {
    owner.dismissed = true;
    owner.resolve();
  }
  clearIfReleased(owner);
  emit();
  return owner.receipt;
};
const cancelNotice = (owner: Cycle, notice: Notice) => {
  if (!notice.cancelled && owner.notices.has(notice.id)) {
    notice.cancelled = true;
    try {
      notice.onCancel();
    } catch {
      /* Cancellation must still close its notice. */
    }
  }
};

/** One progress cycle stays hidden through its transfers and native save handoffs. */
export const beginAttachmentDownloadNotice = (
  title: string,
  size: string | undefined,
  onCancel: () => void,
  attachment?: {identity: string; isCurrent: () => boolean},
): AttachmentDownloadNotice => {
  if (Platform.OS !== 'ios')
    return {
      transferFinished: () => {},
      dismiss: async () => {},
      release: () => {},
    };
  if (!cycle) {
    let resolve!: () => void;
    const receipt = new Promise<void>(accept => {
      resolve = accept;
    });
    cycle = {
      id: ++nextId,
      notices: new Map(),
      visible: true,
      requested: false,
      shown: false,
      closeRequested: false,
      dismissed: false,
      receipt,
      resolve,
    };
  }
  const owner = cycle;
  const notice: Notice = {
    id: ++nextId,
    title,
    size,
    onCancel,
    cancelled: false,
    attachmentIdentity: attachment?.identity,
    isCurrent: attachment?.isCurrent || (() => true),
    transferPending: true,
  };
  owner.notices.set(notice.id, notice);
  emit();
  let released = false;
  return {
    transferFinished: () => {
      if (released || !notice.transferPending) return;
      notice.transferPending = false;
      emit();
    },
    dismiss: () => (released ? Promise.resolve() : dismiss(owner)),
    release: () => {
      if (released) return;
      released = true;
      owner.notices.delete(notice.id);
      if (owner.notices.size === 0) void dismiss(owner);
      else emit();
    },
  };
};

export const cancelAttachmentDownloadNotices = () => {
  const owner = cycle;
  if (!owner) return;
  [...owner.notices.values()].forEach(notice => cancelNotice(owner, notice));
  void dismiss(owner);
};

/** Host-only lifecycle; cycle IDs reject late events from an earlier modal. */
export const attachmentDownloadNoticeHost = {
  subscribe: (listener: () => void) => {
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  },
  getSnapshot: () => snapshot,
  requested: (id: number) => {
    if (cycle?.id === id && cycle.visible) cycle.requested = true;
  },
  shown: (id: number) => {
    if (cycle?.id !== id || cycle.dismissed) return;
    cycle.shown = true;
    if (cycle.closeRequested) {
      cycle.visible = false;
      emit();
    }
  },
  dismissed: (id: number) => {
    if (cycle?.id !== id) return;
    const owner = cycle;
    owner.visible = false;
    owner.dismissed = true;
    owner.resolve();
    clearIfReleased(owner);
    emit();
  },
  hide: (id: number) => {
    if (cycle?.id === id) void dismiss(cycle);
  },
  cancel: (id: number, noticeId: number) => {
    if (cycle?.id !== id) return;
    const owner = cycle;
    const notice = owner.notices.get(noticeId);
    if (!notice?.transferPending || !notice.isCurrent()) return;
    cancelNotice(owner, notice);
    void dismiss(owner);
  },
  retire: () => {
    // A removed root host cannot present a save. Cancel actions before settling.
    const owner = cycle;
    cancelAttachmentDownloadNotices();
    if (owner) attachmentDownloadNoticeHost.dismissed(owner.id);
  },
};

type AttachmentIdentity = Pick<
  CourseAttachment,
  'id' | 'courseId' | 'downloadVersion'
>;
export const attachmentDownloadIdentity = (attachment: AttachmentIdentity) =>
  [
    attachment.courseId || 'course',
    attachment.id,
    attachment.downloadVersion || 'current',
  ].join('|');

/** Inline controls reuse the notice's original owner, even after its modal hides. */
export const useAttachmentDownloadCancellation = () => {
  const state = useSyncExternalStore(
    attachmentDownloadNoticeHost.subscribe,
    attachmentDownloadNoticeHost.getSnapshot,
    attachmentDownloadNoticeHost.getSnapshot,
  );
  return (attachment: AttachmentIdentity | null): (() => void) | undefined => {
    if (!attachment || !state) return undefined;
    const identity = attachmentDownloadIdentity(attachment);
    const notice = state.notices.find(
      item =>
        item.attachmentIdentity === identity &&
        item.transferPending &&
        !item.cancelled &&
        item.isCurrent(),
    );
    return notice
      ? () => attachmentDownloadNoticeHost.cancel(state.id, notice.id)
      : undefined;
  };
};
