# Platform association at deployment

Reuse: the existing Laravel HTTP transport, bounded same-host redirect checks,
and Rokn DAL statement validator. No new library or competitor source is used.
This is the association protocol shipped in Android, not a new UX reference:
https://developer.android.com/training/app-links/configure-assetlinks

Deployment must validate the publicly served Android statement against the
configured package, exact signing fingerprints and `handle_all_urls` relation.
An informational Rokn release hash includes Apple IDs too. Comparing that hash
to a still-live old deployment blocked enabling Apple even though its Android
statement was identical. The preflight now checks the actual Android contract;
the hash remains informational and is not trusted instead of the JSON statement.

The configured HTTPS origin and existing same-host redirect enforcement remain
unchanged; this unit does not change redirect scheme/port validation. A matching
metadata hash cannot authorize another package, signing key, namespace or
permission. No preflight switch is disabled and no signing trust is broadened.

Regression fixtures reproduce the old live header before adding Apple's ID and
reject incorrect statement fields even when the metadata hash matches. Runtime
deployment and native links still require separate evidence from those fixtures.
