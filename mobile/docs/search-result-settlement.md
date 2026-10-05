# Search result settlement

Local search presentation now distinguishes a pending query, a failed query and a completed result on the first render after the input changes. An empty result and `search_zero_results` must belong to the successfully loaded current query, not to the preceding query's loading flags. This unit does not change the approved search layout, debounce duration, search endpoint or ranking.

## Reference and reuse

[Rocket.Chat directory search](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/views/DirectoryView/hooks/useDirectorySearch.ts) publishes the result total and announces it after a successful response. Its [directory screen](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/views/DirectoryView/index.tsx) separates search loading from pagination. Rocket.Chat's [company page](https://www.rocket.chat/company/about-us) reports more than 12 million platform users. That is adoption evidence, not a mobile installation count or a performance result for Rokn.

The reference supplies the architectural principle, not a matching Rokn adapter. No Rocket.Chat source code was copied and no new package was added. The implementation reuses Rokn's existing React state, query normalization, public catalogue transport, cancellation, pagination, search selector, feed components and analytics outbox. It adapts ownership of the visible state to Rokn's existing normalized query and accepted result.

## Presentation and event contract

- `usePublishedCourseCatalogue` derives pending presentation from the current normalized query and the accepted result owner. It does not wait for the 350 ms debounce effect to set loading before suppressing an empty state.
- Failures carry the query they belong to. Changing the query immediately suppresses the preceding query's failure. A failed current request is an error, not a successful empty response.
- `searchResultsReady` requires a nonempty current query, an accepted response for that query, no visible loading and no current failure. The public catalogue owner supplies this state through `useHomeCatalogue`; Home does not infer success from an empty array.
- `HomeCatalogueFeed` continues using the existing loading and error props. No replacement UI or parallel search flow was introduced. Pagination loading, pagination errors and the browse cache notice are not presented as another query's status.
- Home emits `search_zero_results` only when the current search is ready and its selected results are empty. The dependency uses normalized input, so an equivalent Arabic spelling does not by itself recount the same accepted empty result. The event still sends a bounded length, not the raw query.
- Existing request identity checks and aborts continue rejecting abandoned replies. Returning to already accepted results does not require another request solely to clear an obsolete loading flag.

This is not exactly-once counting across app sessions, a record of every successful HTTP request or a historical analytics repair. It does not change existing retry timing or claim that all catalogue cache and freshness concerns are solved.

## Backend and dashboard compatibility

The existing `search/courses` read remains the authority for published search results. Its response revision and pagination validation, Arabic normalization and public visibility contract are unchanged. Search responses do not use the browse cache as fabricated matches.

`search_zero_results` is already allowed by both mobile and backend product-event contracts. Existing event persistence and outbox delivery remain in place. The dashboard's general event quality totals include these records; there is no dedicated search-zero-results chart being changed in this unit. Correcting the producer avoids false new observations without deleting or recalculating previous events. No backend schema, server endpoint or dashboard UI change is required for this additive mobile presentation state.

## Verification status

The independent reviewer accepted the source within this unit's responsibility and found no remaining normal-flow blocker. That acceptance covers query ownership, transient status presentation and event production, not runtime performance or the full project. Tests have been authored but not run, and acceptance on a phone is not yet proven.

- `homeCatalogueQueryOwnership.test.tsx` records every hook render, including the first render before effects. It covers pending query ownership, failure followed by a new query, immediate restoration and the existing abandoned reply and pagination cases.
- `homeSearchResultSettlement.test.tsx` uses the real Home screen, public catalogue join, hook, selector and feed. Controlled transport and leaf mocks cover delayed nonempty and empty responses, failure, abandoned empty replies and equivalent Arabic spelling. It observes every rendered status title and the event calls, not just the final tree.

The final combined gate must execute these tests and the surrounding catalogue, search and analytics suites. It must also inspect the actual phone experience under delayed or failed responses and verify event delivery through the backend. No tests, lint, typecheck, build, commit, push or deployment have been performed for this unit.
