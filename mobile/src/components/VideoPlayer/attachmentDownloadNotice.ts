import {Platform} from 'react-native';

type Notice = {
  id: number;
  title: string;
  size?: string;
  onCancel: () => void;
  cancelled: boolean;
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
): AttachmentDownloadNotice => {
  if (Platform.OS !== 'ios')
    return {dismiss: async () => {}, release: () => {}};
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
  };
  owner.notices.set(notice.id, notice);
  emit();
  let released = false;
  return {
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
    if (notice) cancelNotice(owner, notice);
    void dismiss(owner);
  },
  retire: () => {
    // A removed root host cannot present a save. Cancel actions before settling.
    const owner = cycle;
    cancelAttachmentDownloadNotices();
    if (owner) attachmentDownloadNoticeHost.dismissed(owner.id);
  },
};
