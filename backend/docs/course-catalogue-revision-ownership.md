# Catalogue cache generation ownership

`CourseCatalogueRevisionService` owns the revision key, atomic seeding/increment,
cache-failure fallback and transaction timing. Readers use `current()`. Writers
use `invalidateAfterCommit()` whether called inside or outside a transaction.
Callbacks registered inside nested transactions run only after the outer commit
and are discarded with the transaction that scheduled them on rollback.

The existing cache key and ten-year revision TTL are unchanged. Seeding uses the
existing time-based generation rather than restarting at 1. Cache availability
does not determine whether a persisted course edit, image, publication or account
deletion succeeded. The optional failure callback is solely for operational
reporting, including release-command warnings; reporting cannot fail the mutation.

Consumers are the catalogue query reader, `InvalidatesCourseCatalogue` model events,
catalogue-relevant photos, account deletion, staged publication and release search
backfills. Consumers decide *whether* their change affects the catalogue; they must
not implement their own generation key, seed or commit timing.

`CourseCatalogueRevisionOwnershipTest` adds stable-reader/immediate-writer, nested
commit, inner/outer rollback and cache/reporting failure coverage. The account
deletion and staged-publish integration tests continue to exercise real consumer
transactions. See the repository README for local verification evidence and limits.
