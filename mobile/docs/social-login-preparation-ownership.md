# Social login preparation ownership

## Final gate — eagerly observed test rejection, 5 October 2026

Three auth preparation fixtures assigned a rejects matcher and awaited its
promise only after releasing controlled preparation. Jest's valid-expect lint
did not recognize that deferred await. Each now immediately invokes an async
observer that awaits the identical matcher, then awaits that observer at the
same post-release point. No provider/nonce/journal/intent code or assertion changed.
The observer is attached before retirement, not after the rejection or before
unlocking preparation. The same correction covers one certificate fixture.

Scoped lint is zero errors/warnings. The complete four suites pass 36/36 in
`mobile/.cache/final-gate-20261005/mobile-async-assertion-observers-final.log`.
Independent read-only review accepted eager attachment, preserved expectations
and absence of deadlock. This is test-source acceptance, not live OAuth/Apple,
certificate/provider integration or release readiness.

This local change prevents a provider window from opening after the learner leaves Login while preparation is pending. Presentation ownership ends on blur, removal or unmount. Once the native provider window starts, credential completion remains independent of that presentation and the exact durable session still reaches Redux. Independent source review accepted this unit; runtime verification remains deferred to the combined final gate.

## Reference and actual reuse

Rocket.Chat cancels superseded login work when its workspace owner changes in [the login saga](https://raw.githubusercontent.com/RocketChat/Rocket.Chat.ReactNative/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/sagas/login.js). [Its regression case](https://raw.githubusercontent.com/RocketChat/Rocket.Chat.ReactNative/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/sagas/__tests__/login.switchCancel.test.ts) holds preparation pending, changes workspace, then verifies that the retired work does not persist the former owner's credentials. This is an architectural reference for ownership, not a matching Rokn navigation adapter. [Rocket.Chat reports more than 12 million platform users](https://www.rocket.chat/company/about-us); that is platform adoption evidence, not an exact mobile audience or a runtime benchmark.

No matching implementation for Rokn's PKCE journal plus guest route handoff was found and no Rocket.Chat source was copied. The integration reuses the installed Expo browser and Apple authentication libraries, React Navigation listeners, secure-session mutation owner and the existing keyed async queue. No dependency or second authentication system was introduced.

## Ownership contract

- The Login owner distinguishes preparation, an opened provider, failure, recoverable browser waiting and a committed session. Preflight and optional guest preparation recheck ownership before passing to the provider service. Terms and privacy navigation retire preparation on blur even when Login remains mounted. Failed optional preparation also retires on departure after its physical flight has finished. A retired physical flight cannot release another attempt.
- The facade checks ownership after discovery and installation identity. Browser and Apple adapters check it again after their native journal is saved, immediately before calling the provider UI. Retired preparation deletes only its expected encrypted attempt. Different presentation owners cannot join the same physical provider flight merely because they chose the same provider.
- These checks do not cancel an already opened provider, the one-time exchange, the secure credential commit or global Redux adoption. The existing exact committed-token check still prevents an old completion from adopting a replaced session.
- Optional route writes carry a live persistence predicate and an exact receipt. Creation time and write generation are captured before waiting for scopes, so an older delayed request cannot overwrite a newer destination. The physical write and retirement cleanup share one queue slot. Claim snapshots wait for preceding cleanup, then validate outside that slot; acknowledgement and invalid-envelope removal compare the exact receipt in the same queue without waiting for themselves. An old cancellation does not globally clear a newer journey. Scope discovery stays outside the mutation queue.
- Guest preparation remains optional and bounded to 600 ms in Login. Its staging is invoked only while the journey remains eligible; public guest migration retains its existing separate owner. Accepted provider completion may finish optional route persistence after Login leaves. `LOGIN_RESUMING` retains its handoff on blur, but explicit Back without a committed session retires both that route intent and the expected encrypted attempt, whether optional storage has already completed or is still pending.

## Backend and dashboard compatibility

Only local lifecycle ownership changes. `SocialOAuthController` still accepts S256 challenge and verifier, binds callbacks to an attempt and returns the existing completion response. Native Apple continues sending its identity token, authorization code and nonce to `SignController`. Provider discovery and dashboard-controlled recommendation and welcome offer fields remain unchanged. Backend and dashboard edits are not needed for this client-side preparation defect; no ledger or entitlement operation was changed.

## Deferred verification

### Attached dashboard-owned recommendation badge — 6 October 2026

The user relaxed the requested ReelShort-specific reference to any successful
ordinary badge and identified the actual defect: the label floated away from
the control. The small filled label adapts Udemy's displayed `Bestseller` badge,
not its private source or authentication flow. The inspected [course page](https://www.udemy.com/course/the-complete-web-development-bootcamp/)
showed the badge and 1,592,757 enrolled students; [Udemy's Android listing](https://play.google.com/store/apps/details?id=com.udemy.android)
showed 10M+ downloads. These establish adoption of the reference and label,
not measured conversion improvement or native acceptance of Rokn.

The existing RN View/Text implementation is reused. The label stays in normal
flow, touches the control's upper edge, uses Rokn primary/white and logical start
in RTL, and can grow vertically for authored text or large fonts rather than
being absolutely overlaid on the button. One common wrapper now covers Google,
Facebook, TikTok and the original native Apple button. Backend/provider
availability, dashboard preference selection, authored text, authentication
callbacks and actual reward amounts stay with their existing owners. A generic
preference badge no longer disappears when the optional reward offer is zero;
explicit `{coins}` copy still requires an available indivisible offer.

After disclosure that no measured share was established, the owner explicitly
requested `اختيار 89% من المستخدمين` as marketing copy. That supplied string
and its English translation are now shared defaults in `social_auth` config;
dashboard-authored labels override them. This is owner-authored copy, not a
calculated usage metric or proof of conversion/share. No analytics records,
measurement evidence, new fields, ledger mutation or provider bonus were made.
Production text has not been changed in this local unit.

Multi-provider, attached-flow and zero-offer/template contracts are authored
but not run. Independent review accepted the source behavior only. The single
final native/functional gate still must accept the rendered result and button
callbacks. The new source is still local.

The independent reviewer accepted the source after claim snapshots waited for physical writer cleanup, failed optional journeys retired after their flight finished, and explicit Back consistently retired a resuming handoff whether its route was ready or delayed. This is acceptance of source coherence and authored coverage within the unit, not executed test or device evidence.

Authored but not executed: departure during secure preflight by blur, beforeRemove and unmount; opening terms then returning and starting again; a raw route write finishing after departure with a newer Wallet return preserved; a claim requested while a retired raw envelope awaits cleanup; leaving during final service preparation; an old provider cancellation retaining a newer receipt; committed-session adoption after the old Login unmounts. Late optional scope completion covers failure, recoverable browser waiting and committed login with both blur and beforeRemove. A durable resuming route also retires with its expected encrypted attempt on explicit Back. Existing facade tests add installation identity retirement and reject a different owner's duplicate. Browser tests hold the real PKCE journal before Android open. Apple tests hold availability, nonce and journal preparation before native open. The real secure commit delivery suite also adds unmount during credential persistence, with no Redux adoption before the durable write completes.

The combined gate must run these cases alongside existing social auth, dismissal, native fallback, nonce binding and interrupted journey suites, then exercise Android and iOS provider launch, cancellation, callback return, backgrounding, successful login and process restart on devices. Tests, lint, typecheck, native build, commit, push and deployment have not been run for this unit. Source review cannot establish native behavior, release readiness or completion of the full project goal.
