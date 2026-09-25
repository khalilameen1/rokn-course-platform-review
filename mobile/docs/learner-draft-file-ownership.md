# Learner draft attachment ownership

`learnerDraftFiles` owns attachment copying, size checks, account serialization,
provisional batch protection and orphan eviction. It is the existing public entry
point; callers do not assemble filesystem and registry operations themselves.
Drafts/outboxes own their content and durable storage writes. The native registry
and durable draft records together determine which copied files are still used.

`learnerDraftFiles/paths` owns managed path/account classification and extension
selection. `learnerDraftFiles/referenceStore` owns registry decoding, staged
replacement/backup recovery and reading references from durable drafts. It never
copies or evicts attachment bytes. Registry mutations run under the public file
owner's account queue, not a second independent lock. The common keyed queue
primitive keeps all file operations for one account ordered while different
accounts remain independent. Moving a registry operation outside that owner
would break the copy/retain/cleanup ordering even if the JSON write itself works.

`learnerDraftStorage` declares the persisted namespaces and account positions of
the records that can own these files. Each producer imports its namespace from
this declaration. Cleanup reads only those records for the exact account, not
every account-scoped AsyncStorage key. Payment recovery IDs, preferences and
support tracking receipts are not draft JSON and must not break file selection.
Project/course IDs that resemble an account ID do not change ownership.

When adding a persistent attachment owner, declare its namespace/account position
and use that namespace in its producer. Add a cleanup behavior test with its
actual persisted shape. Do not work around a missing declaration by ignoring
parse errors for active drafts: unreadable active owners must still stop eviction.
Quarantined `:corrupt` records are not active drafts; selectable support conflicts
are active and their embedded JSON must still be inspected for references.

No stored key or format is migrated by this declaration. Existing namespace
strings, retention limits, provisional grace, file-copy verification and atomic
registry replacement remain unchanged. A genuine storage/read failure does not
mean files are abandoned. Cleanup cannot evict a durable or provisional owner to
make room for a new pick.

`learnerDraftReferenceCleanup.test.ts` reproduces the native-purchase-binding
conflict and covers every declared draft family, corrupt/incomplete active reads,
unrelated account data, registry recovery and failed deletion accounting.
`learnerDraftStorage.test.ts` checks account positions and namespace boundaries.
`learnerDraftBatchOwnership.test.ts` covers multi-select budget protection. Other
draft, upload and chat suites exercise producers through their public APIs.
`learnerDraftRegistryCommit.test.ts` exercises temporary-write and rename failures,
rollback/backup recovery, concurrent owners and independent account commits
through the public file owner rather than bypassing its queue.

These are local mocked-filesystem tests, not device picker or OS cache-purge tests.
