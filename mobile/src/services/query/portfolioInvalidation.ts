import {queryClient} from './queryClient';
import {accountQueryKeys} from './queryKeys';

/**
 * A portfolio write the server has confirmed outside the gallery's own
 * mutation hooks (today: a portfolio media delivery from the durable outbox)
 * changes the owner's project list. Mark it stale (ARCHITECTURE.md M2,
 * "Successful deliveries invalidate the related queries"): a mounted gallery
 * reads again at once, an unmounted one on its next mount.
 */
export const invalidatePortfolio = (scope: string) => {
  void queryClient
    .invalidateQueries({queryKey: accountQueryKeys(scope).portfolio()})
    .catch(() => undefined);
};
