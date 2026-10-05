# Wallet task presentation ownership

Task commands keep their account ownership independently of the Wallet visit that initiated them. A late task response must not open an external app, show the coin guide or present an error over a different page. A confirmed reward must still invalidate the account's wallet snapshots.

This unit is local. Tests and native verification are deferred to the final combined gate. No build, commit, push or deployment was performed for it.

## Reference and actual reuse

The reference is Rocket.Chat React Native at revision `f505f82d7bd152c5325b70b42c3c68053a7fa9fd`. Its [useJumpToMessage](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/views/RoomView/hooks/useJumpToMessage.ts) checks the generation after asynchronous work and suppresses obsolete error presentation. Its [useRoomAudioLifecycle](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/views/RoomView/hooks/useRoomAudioLifecycle.ts) binds room audio to navigation blur and scoped cleanup. These are separate examples of intent invalidation and screen lifecycle ownership, not a ready-made wallet task adapter.

[Rocket.Chat's company page](https://www.rocket.chat/company/about-us) reports more than 12 million platform users. This establishes real platform adoption, not the mobile install count or a measured performance result for these hooks.

No accessible adapter matching Rokn's reward tasks, WhatsApp verification and financial settlement was found. This is an architectural adaptation, not copied Rocket.Chat code. Actual reuse is the installed React Navigation [useIsFocused](https://reactnavigation.org/docs/use-is-focused/), Rokn's existing foreground store, guarded task API commands, URL policy, OS opener and settlement publisher. No dependency or copied-source license was added.

## Ownership rules

- Each task press captures the current account identity, screen focus and application foreground visit before capturing the secure session. If that capture finishes after the visit is abandoned, no task command starts.
- A focus, foreground or account transition retires the initiating presentation. Leaving and returning does not authorize the old response to open a destination or show a message.
- A valid start response still updates the server-owned task state while Wallet remains mounted for that account. If its external destination was not opened because the visit ended, the local retry marker makes the next explicit action open it using a fresh server authorization. The app does not claim coins automatically or assert that the learner completed an external action.
- Successful OS hand-off can itself background Rokn. Its success clears the retry marker under account ownership, without requiring the old foreground visit to remain active. A late failure retains retry state but shows an error only in the initiating visit.
- Confirmed claims retain the real API settlement publication even if Wallet unmounts. A still-mounted account updates its task and reloads the authoritative wallet snapshot while offscreen. There is no local reward delta, reversal, cancellation of an already dispatched financial request, or second claim for reconciliation.
- Failed claims reconcile the account snapshot without presenting an abandoned error. Account replacement or unmount prevents updates to the old Wallet. Busy operation keys include the secure-session epoch. Each task's spinner records its operation key: an obsolete request releases its own spinner even if its secure session is no longer valid, but cannot release a newer request's spinner.

## Backend and dashboard compatibility

`coin-earning-methods/{id}/start` and `claim-coins` are unchanged. The backend still owns attempts, verification delay, WhatsApp proof, immutable claim receipts and ledger locking. Claim API settlement publication remains independent of screen mounting. The dashboard still owns task availability, destinations, reward amount and campaign limits. No presentation flag is sent to the server, and no financial formula or dashboard control is duplicated in the app.

The approved rewards layout and copy are unchanged. This does not add wallet package buying or a new reward popup.

## Deferred verification

`walletTaskPresentationOwnership.test.tsx` is authored but not run. It uses the real task API command implementation and settlement publisher with controlled HTTP, OS-opening and presentation boundaries. Cases cover current double taps, leave and return, background and return, suppressed-opening retry, successful OS hand-off backgrounding, late OS failure, guide response, retired start and resume reconciliation, confirmed claim during blur/background/unmount, failed claim reconciliation, replacement accounts, replacement secure sessions and delayed account capture.

The same-account session replacement cases distinguish two outcomes: the old completion cannot release a newer busy flight, and an old completion without a replacement flight enables the real `RewardsTaskList` button for a new explicit press. Only decorative leaves are mocked for the button case.

Existing task ownership, ready-to-claim and wallet settlement suites retain their behavior with visible-visit mocks. The architecture assertion now records epoch-scoped busy ownership. These source and authored-test changes are not runtime proof.

The final gate must execute the task suites together, then verify navigation, background return, external opening and real wallet refresh on Android and iOS. Native timing and actual task/payment integration remain unverified until that gate.

## Independent review

Independent source review accepted the unit after correcting busy cleanup when a secure session changes without changing account identity. The follow-up review confirmed that the task's operation key releases only its own spinner and that the authored real-button case covers re-enabling and a new press. No remaining everyday blocker was identified in this unit by that source review.

This acceptance covers source coherence only. It does not establish executed test results, native behavior, deployment or completion of the overall project goal.

## Final gate — accessible task button fixture, 5 October 2026

The whole task-presentation suite reproduced 17 passing cases and one failure
before the real-button scenario could press its control. The installed React
Native `Pressable` export is memo-wrapped; TestRenderer does not find that
export identity as the rendered component type. The fixture now selects the
actual control by its button role and the shipped label `متابعة تابع ركن`.
No row, task label, command, busy state or financial behavior changed.

The first selector attempt mistakenly expected the generic `ابدأ` label; the
existing social-task action source returns `متابعة`. That intermediate failure
is retained in `mobile/.cache/final-gate-20261005/mobile-wallet-task-button-final.log`.
After correcting the expected social label, all 18 cases passed in
`mobile-wallet-task-button-final-accepted.log`; the original import-identity
baseline is `mobile-wallet-task-button-reproduction.log` in the same directory.

Independent read-only review accepted the accessible selector and confirmed
that the real handler, disabled/busy assertions, obsolete-response silence and
fresh explicit tap are still exercised. This is mocked HTTP/OS and rendered
component evidence, not a native spinner, financial settlement or full wallet
acceptance. The complete release gate remains open; no build or deployment
was performed for this correction.
