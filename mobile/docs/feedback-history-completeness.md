# Support history completeness and recovery

This local change prevents a failed support-case read from silently removing a known request or being reported as a complete refresh. Available requests stay usable, missing updates are explicitly signalled, and only a complete read replaces the list. Independent source review accepted this unit; runtime verification remains deferred.

## Proven reference and actual reuse

Rocket.Chat keeps the visible room list in its [observed subscriptions](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/views/RoomsListView/hooks/useSubscriptions.ts), separately from [refresh requests](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/views/RoomsListView/hooks/useRefresh.ts). Its [room synchronization](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/sagas/rooms.js) updates known records and distinguishes synchronization failure from success, while [getRooms](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/lib/methods/getRooms.ts) waits for its required reads. All four files were read before implementation. [The company reports more than 12 million platform users](https://www.rocket.chat/company/about-us), not that many mobile downloads.

This is architectural adaptation, not copied source or a claim that their protocol handles Rokn's guest receipts. No ready compatible receipt adapter was found; that gap was disclosed. Actual reuse is the existing Axios transport, account boundaries, receipt storage, case parser, Promise settlement, map-based history ordering and FeedbackConversation. No database, package or alternate transport was added.

## Defect and coherent handling

`loadProductFeedbackCases` previously returned a plain successful array whenever at least one case loaded, even when another remembered case failed. The actual history controller replaced its entire list with that array, so a network failure could make a request disappear without any error. This affected guest receipts and remembered guest cases supplementing a valid account index.

The service now distinguishes a complete array from `ProductFeedbackHistoryIncompleteError`, which carries only parsed, authorized usable results and the underlying failure. The controller merges a nonempty incomplete result by public ID without deleting previous entries, keeps the selection and reply draft, and shows `تعذّر تحديث بعض الطلبات` through the existing accessible error area. The existing Refresh button retries the same read. Successful updates remain ordered and are not duplicated.

If every receipt fails, the controller retains its previous history and shows the ordinary refresh error; an initial failure is not presented as no previous requests. A genuinely empty receipt history still succeeds without unnecessary network requests. A later complete read replaces the list, so legitimately absent entries can disappear only when absence is established by that complete result.

The account index remains stricter: an invalid response, failed pagination or index request throws its original error even if local receipt fallback succeeded. Local receipts are not a replacement for an authoritative account history. Existing account assertions and route/read-generation ownership prevent late partial results from merging into a newer owner. Endpoint, guest header, payload, backend authorization and dashboard contracts are unchanged; this local completeness defect needs no schema or dashboard rewrite.

## Source acceptance and final verification

The independent reviewer read current service, merge/error types, controller, references and the authored suite. It answered **هل وصلت الوحدة إلى مستوى المرجع ضمن مسؤوليتها؟ نعم** with limited source acceptance and no remaining daily-flow blocker found. It ran no tests and did not edit files. This is neither native runtime acceptance nor whole-project completion.

`feedbackHistoryCompleteness.test.tsx` is authored, **not run**, against the actual services, parser, receipt storage, controller and FeedbackConversation. It covers first partial load with usable rows and an accessible error, selected failed request and reply preservation, successful row updates and ordering, retry, deletion only after a complete read, all-failed and genuinely empty histories, valid account index plus guest-read failure, original account-index error, malformed individual receipt response, and late route/account results. Existing pagination and malformed-index suites remain required.

The final combined gate must run these suites and related history selection, draft, delivery and attachment checks, typecheck/lint, then device weak-network refresh and recovery with server/admin inspection. Tests, build, commit, push and deployment remain deferred. No live reviewer or release change is claimed.

## Final gate — accessible refresh binding, 5 October 2026

The complete suite reproduced 10 passes and two failures before refresh: the
test's `findAllByType(Pressable)` did not find the current renderer's memoized
button, leaving an undefined node. Its refresh helper now selects the actual
button using `accessibilityRole: button` and `accessibilityLabel: تحديث الحالات`,
then invokes that view's original `onRefresh` handler. No controller, storage,
service/parser, UI, endpoint or dashboard behavior changed for this correction.

All 12 cases now pass in
`mobile/.cache/final-gate-20261005/mobile-feedback-history-refresh-final.log`;
the baseline is `mobile-feedback-history-refresh-reproduction.log` in the same
directory. Independent read-only review accepted this selector correction after
reading the binding and result. Partial merge, complete-read-only deletion,
selected reply preservation, explicit retry, malformed reads and retired
account/route assertions remain unchanged. The reviewer did not run tests.

This is controlled HTTP/storage/view evidence, not real network/device/admin
acceptance or proof of the complete release gate. Build and upload remain open.
