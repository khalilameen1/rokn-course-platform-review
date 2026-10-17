/**
 * Bridges the AI consent service to the app's own consent sheet. The service
 * stays UI-free; a host mounted once at the app root renders the request.
 */
export type AiConsentPromptRequest = Readonly<{
  id: number;
  decide: (accepted: boolean) => void;
}>;

let nextId = 0;
let hosts = 0;
let current: AiConsentPromptRequest | null = null;
const listeners = new Set<() => void>();
const emit = () => listeners.forEach(listener => listener());

export const aiConsentPromptHost = {
  subscribe: (listener: () => void) => {
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  },
  getSnapshot: () => current,
  /** Returns the unregister function; prompts need at least one host. */
  register: () => {
    hosts += 1;
    let registered = true;
    return () => {
      if (!registered) return;
      registered = false;
      hosts -= 1;
      // Without a host nobody can answer; an unanswered prompt is "not now".
      if (!hosts) current?.decide(false);
    };
  },
};

/** Resolves true only on an explicit "accept"; any dismissal resolves false. */
export const presentAiConsentPrompt = (): Promise<boolean> =>
  new Promise(resolve => {
    if (!hosts) {
      resolve(false);
      return;
    }
    current?.decide(false);
    const id = (nextId += 1);
    const request: AiConsentPromptRequest = {
      id,
      decide: accepted => {
        if (current?.id !== id) return;
        current = null;
        emit();
        resolve(accepted);
      },
    };
    current = request;
    emit();
  });
