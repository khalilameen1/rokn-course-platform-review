import {AppState, type NativeEventSubscription} from 'react-native';
import Share from 'react-native-share';

type SaveReceipt = Awaited<ReturnType<typeof Share.open>>;
let presentationTail: Promise<void> = Promise.resolve();
const waitingSaves = new Set<() => void>();

/** Retire waits, not an already displayed native picker and its receipt. */
export const cancelPendingAttachmentSaves = () => {
  waitingSaves.forEach(cancel => cancel());
};

export const saveAttachmentToFiles = (
  target: string,
  title: string,
  isCurrent: () => boolean,
  signal: AbortSignal,
): Promise<SaveReceipt | null> => {
  // RNShare's Files picker owns one native delegate receipt slot. Transfers
  // stay parallel; only a foreground save presentation takes this slot.
  const presentation = presentationTail.then(
    () =>
      new Promise<SaveReceipt | null>((resolve, reject) => {
        let finished = false;
        let subscription: NativeEventSubscription | undefined;
        const release = () => {
          subscription?.remove();
          signal.removeEventListener('abort', cancel);
          waitingSaves.delete(cancel);
        };
        const cancel = () => {
          if (finished) return;
          finished = true;
          release();
          resolve(null);
        };
        const present = () => {
          if (finished) return;
          try {
            if (signal.aborted || !isCurrent()) {
              cancel();
              return;
            }
            if (AppState.currentState !== 'active') return;
            finished = true;
            release();
            resolve(
              Share.open({
                url: `file://${target}`,
                saveToFiles: true,
                failOnCancel: false,
                title,
              }),
            );
          } catch (error) {
            finished = true;
            release();
            reject(error);
          }
        };
        try {
          waitingSaves.add(cancel);
          signal.addEventListener('abort', cancel);
          subscription = AppState.addEventListener('change', present);
          present();
        } catch (error) {
          finished = true;
          release();
          reject(error);
        }
      }),
  );
  presentationTail = presentation.then(
    () => undefined,
    () => undefined,
  );
  return presentation;
};
