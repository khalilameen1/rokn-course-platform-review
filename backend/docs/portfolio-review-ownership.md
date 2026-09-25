# Portfolio review ownership

## Read boundary

PortfolioReviewReadService owns the selected-content fingerprint and effective approval evaluation.
Its snapshot and status methods do not write review state. publicUrlFor reloads the current owner
before evaluating approval and constructing a URL, so a stale User object cannot return an old slug.
User.profile_deeplink and certificate issuance consume this reader, not the moderation writer.

The fingerprint format and ordering are unchanged. A refactor must not invalidate existing approvals
or the revision/hash embedded in public media links.

## Write boundary

PortfolioModerationService owns explicit invalidation, reconciliation and review decisions.
reconcile evaluates current content and persists pending state when an approved revision has drifted.
Its compare-and-set revision guard prevents an older reader from revoking a newer review decision.
A failed guard conservatively denies the old read; it does not undo the newer decision.

reconcileOwnerState is the profile endpoint's explicit write-capable entry point.
PublicPortfolioService deliberately reconciles public reads and admin previews: out-of-band edits
must both stop public access and return the owner to the persisted pending review queue.
These endpoints are not write-free. Admin previews take their snapshot after reconciliation.

Model observers continue invalidating material changes through the same moderation owner.
Private draft edits and no-op saves preserve approval. Decisions still lock the owner, compare the
submitted revision and hash, validate media readiness, and consume the revision.

## Verification

PortfolioReviewReadOwnershipTest checks read-only database queries, no writer resolution by read
consumers, unchanged approval fingerprints, current links from stale models, deleted owners,
single revocation, stale-reader/newer-decision interleaving, and public drift reconciliation.

PortfolioPrepublicationReviewTest retains real public/media/admin/profile endpoint coverage,
including persisted requeueing, suspension, private drafts, stale reviews and media replacement.
Certificate and portfolio endpoint suites cover the unchanged external contracts.
