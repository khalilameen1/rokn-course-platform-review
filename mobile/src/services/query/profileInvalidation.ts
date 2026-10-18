import {queryClient} from './queryClient';
import {accountQueryKeys} from './queryKeys';

/**
 * A saved account profile (name, avatar, headline) changes what the أنا tab
 * shows. The write path marks the read stale (ARCHITECTURE.md M1/M2); a
 * mounted profile reads again at once, an unmounted one on its next mount.
 */
export const invalidateAccountProfile = (scope: string) => {
  void queryClient
    .invalidateQueries({queryKey: accountQueryKeys(scope).accountProfile()})
    .catch(() => undefined);
};
