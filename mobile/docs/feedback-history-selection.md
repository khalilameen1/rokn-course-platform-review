# Support history refresh and request selection

This local change separates refreshing support history from opening a request. Refreshing must keep the learner in the selected conversation or the request list. Source review accepted this unit; tests, device checks and release remain deferred to the final combined gate.

## Established reference

Rocket.Chat separates [RoomsListView item navigation](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/views/RoomsListView/index.tsx) from its [refresh hook](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/views/RoomsListView/hooks/useRefresh.ts). Explicit room opening is handled by [goRoom](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/lib/methods/helpers/goRoom.ts), not replayed by a list refresh. All three source files were read before implementation. [Rocket.Chat reports over 12 million platform users](https://www.rocket.chat/company/about-us); this is platform adoption, not a mobile installation count.

Rokn adapts this separation rather than copying their source, importing their data layer or claiming a ready support-case adapter exists. Actual reuse is the existing React Navigation route, React state, account boundary, feedback read/parser, and guest receipt storage. The approved screen and wire contract stay unchanged.

## Defect and implementation

Previously, `reloadCases` chose `preferredCaseId || requestedCaseId` after every successful read. The actual Feedback refresh action supplies an empty preferred ID. A learner entering request A, returning to the list or selecting B, then pressing Refresh was therefore sent back to A. The refresh also made a late read decide selection instead of preserving the learner's newer intent.

`useFeedbackCases` now accepts the route target once when route/account inputs change. Plain refresh replaces history but never selects a request. The explicit Open follow-up action selects its target before loading; failed loading leaves that intent pending, so an ordinary retry can recover it. Choosing another request or the list replaces that pending intent. There is no fallback that replays the original route after success.

A render-bound route/account owner and existing read generation guard launching, completion, error and busy state. A retained callback from a former route cannot retarget the current screen. A route change while boundary capture is pending prevents the obsolete GET from being launched. A response or failure already in flight cannot replace the current route's history or finish its newer loading state.

## Contract and verification

The index and case endpoints, pagination parser and guest `X-Support-Access` header are unchanged. The actual Feedback screen still refreshes without an explicit selection and opens a received follow-up with its explicit ID and receipt. Existing backend case authorization and the admin support timeline remain authoritative; this navigation defect requires no schema or dashboard rewrite. Other history synchronization and whole-project release requirements are not declared complete by this unit.

The independent reviewer inspected the three references, controller and authored suite, and answered **هل وصلت الوحدة إلى مستوى المرجع ضمن مسؤوليتها؟ نعم** with limited source acceptance and no daily-flow blocker found. It did not run tests or change files.

`feedbackHistorySelection.test.tsx` is authored, **not run**, against the actual hook, feedback services and FeedbackConversation. It covers the actual Back to list and Refresh controls, choosing B and keeping its reply on refresh, initial failure/retry, manual failure/retry in list or B, guest receipt follow-up and credentials, new/cleared route, late former-route success/failure and busy state, retained callbacks, and boundary capture during initial/manual reads. Native/storage/HTTP boundaries are mocked; this is not physical-device evidence.

The final combined gate must run this and existing pagination, delivery, draft and attachment suites, typecheck/lint, then device route entry, list/B refresh, failure/retry and follow-up recovery with backend/admin inspection. Build, commit, push and deployment remain deferred. The active project goal is unchanged.

## Final gate — actual Back, Refresh and case-row selectors

The complete suite reproduced nine passes and three failures: its memoized
`Pressable` type selectors returned no node before Back/Refresh could run. The
fixture now finds Refresh by its real button role and label, Back by its button
role and existing visible text, and case B by its exact button/case-status
label. The real `FeedbackConversation` handlers, history controller, receipts,
parser, draft state and all original assertions remain unchanged.

All 12 cases passed in
`mobile/.cache/final-gate-20261005/mobile-feedback-history-selection-final.log`;
the baseline is `mobile-feedback-history-selection-reproduction.log` in the
same directory. Independent read-only review accepted this limited selector
correction after reading actual controls and the result. It confirmed Back
does not replay A, B/reply survives refresh, a failed target remains recoverable,
and obsolete route callbacks/reads do not retarget the current screen. It ran
no tests. Native, real network/server/admin and full release acceptance remain
open; no build, push or deployment was performed.
