# Course upgrade offers — local source, 4 October 2026

## Reference and reuse boundary

[RevenueCat's Photoroom case study](https://www.revenuecat.com/customers/photoroom)
documents actual use of a single authoritative subscription/purchase source.
This unit follows that ownership principle. It does not import RevenueCat's SDK,
claim access to Photoroom's private code, replace Kashier, or copy a different
provider's protocol. No matching public implementation of Rokn's immutable
course-price difference, earned/purchased coin split and Kashier settlement was
found. The product-specific integration remains Rokn code.

Actual reuse: existing immutable enrollment receipts, WalletService paid
contribution and debit rules, Laravel collections/query builder and the AI
request-admission arithmetic already used by the controller/jobs. Extracting
that arithmetic into AiRequestTokenEstimate preserves the actual output caps,
UTF-8 byte estimate and separate attachment estimate. No dependency is added.

## One decision owner

CoursePlanUpgradeEligibilityService owns higher-tier selection, feature intent,
remaining quota reads and the exact paid-price difference. Quote, checkout and
legacy settlement adapters use it. Unrequested offers skip economically
unfundable plans rather than failing on the first ranked catalogue entry.
Explicit targets retain their validation errors. Rewards never fund upgrades.
Reads do not authorize payments, renew quotas or create AI reservations.

Chat capacity includes the actual mandatory course brief and response contract,
plus a short nonempty question, using the same estimate as the request owner.
Follow-up capacity includes the compulsory prompt floor, request/token/cost
usage and used + max(reserved, SENT) + QUEUED commitments. The queue admission
uses the same commitment helper, not an independent count formula.

These reads establish minimum usable capacity, not a guarantee that any long
question, project context, conversation history or attachment will fit. Actual
requests still reserve their complete input under the existing enrollment lock.
No request bypass, artificial allowance reset or future-capacity promise exists.

## Mobile and dashboard

New feature-specific reads require upgrade_available and available_plan_codes.
The mobile upgrade sheet consumes those codes without a local tier/capability
decision. Missing/contradictory contracts fail visibly. Legacy no-feature API
reads and the old highest-tier already_upgraded field remain compatible.

The chat hook owns only block/offer presentation. All purchase/recovery stays
in CourseSubscriptionSheet. A backend captured chat_entitlement_revision retires
an exhausted local block after a real purchased-rights change; editing the
catalogue does not reset it. Late account/course/receipt flights cannot apply.

The dashboard fresh-student preview shows prospective higher chat tiers using
the same feature/minimum-capacity predicate. It is expressly not a simulation
of a particular student's captured paid floor or consumed quota. Actual offers
are bound to that student's receipt and current published pricing.

## Review and deferred acceptance

Independent source review accepted ownership, captured-receipt invalidation,
generic economic selection, compulsory chat input and pending follow-up counts.
It did not run tests, compile PHP/native code, certify provider behavior or
accept the whole project. This local change is not a deployment or new binary.

### Final gate — consent fixture's quote Promise, 5 October 2026

The consent suite's empty quote mock returned `undefined`, unlike the real
asynchronous API, and crashed when disabling chat entitlement activated the
existing GET-only offer reader. The fixture now rejects its Promise as an
offline discovery read. The real hook must report `error`; the test also checks
the exact course and `requiredFeature: chat` request while retaining its
no-question/no-upload and retired-consent assertions. No application behavior,
server policy or dashboard control changed. Independent review accepted this
limited correction. Seventeen of eighteen cases pass; a separate unary-file
cleanup assertion remains open.
Evidence: `mobile/.cache/final-gate-20261005/mobile-chat-consent-quote-recheck.log`.

The separate cleanup-call assertion was subsequently corrected against the
unary file-owner contract without changing upgrade or attachment behavior.
The full consent suite then passed all 18 cases in
`mobile/.cache/final-gate-20261005/mobile-chat-consent-final.log`. Independent
review accepted that exact cleanup-batch assertion; details and native limits
are recorded in `mobile/docs/learner-draft-file-ownership.md`. This supersedes
the 17-of-18 intermediate result, not the outstanding full release gate.
This is not native chat, provider settlement or whole-project acceptance.

### Final gate — keyboard-view offer fixture, 5 October 2026

The keyboard suite replaces `useCourseChat` with a presentation fixture. That
fixture omitted `upgradeStatus`; the real gate correctly withheld its upgrade
CTA rather than treating missing discovery as a sale. The fixture now declares
the typed status and retry callback. The original explicit-tap/reopen case
models idle/loading as no CTA, an available result as a CTA but no automatic
checkout, and the actual tap as the chat-specific embedded subscription sheet.
Closing/reopening returns to discovery/explanation, not the old checkout.

The whole suite passed 18 tests in
`mobile/.cache/final-gate-20261005/mobile-chat-keyboard-offer-final.log`, after
17 passed/1 failed in `mobile-chat-keyboard-offer-reproduction.log`. No product,
GET, payment, backend or dashboard code changed. Independent read-only review
accepted this presentation-fixture correction. Native keyboard/payment behavior
and the actual GET reader are not proved by its mocked controller.

Authored, not executed: actionable generic offers, output-only token rejection,
Guided exhaustion to Mentor, highest-tier unavailable, exact difference with no
reward spend, catalogue vs purchased revision, pending follow-up double-count
avoidance and token/cost denial, API availability validation, account/receipt
late responses and rendered gate loading/unavailable/retry. Existing purchase,
settlement, project-reply and legacy suites remain final-gate requirements.

At the final goal gate execute those suites, dependency/type/release checks and
the signed-device journeys against the matching backend/dashboard. Verify v60
compatibility, concurrent quota consumption during checkout and a real purchased
entitlement refresh before claiming runtime closure. Tests/build/deployment are
deliberately deferred, as requested. Other inventory units remain open.

## Exhausted project discussion — 5 October 2026 local source

The visible reference is [Duolingo's shipped Energy recovery](https://blog.duolingo.com/duolingo-energy/).
Its [Android listing](https://play.google.com/store/apps/details?id=com.duolingo)
reports 500M+ downloads. We reuse the observable principle that a usage limit
has an explicit next action rather than a dead-end sentence. We do not copy
private code, artwork, ads, gem refills, daily replenishment or unlimited claims.
No public implementation matching Rokn's per-course paid-difference contract
was identified. Actual code reuse is the existing React offer lifecycle,
CourseSubscriptionSheet, canonical budget and upgrade eligibility services.
The [Q2 2025 shareholder letter, page 5](https://investors.duolingo.com/static-files/0b55110c-2eb9-466d-8549-5459e0851290)
reports an actual iOS Energy rollout improving active use, learning time and
subscriber conversion. This supports real deployment of the mechanism, not
an install-count claim about this individual feature or a guarantee for Rokn.

- `useCourseUpgradeOffer` is extracted from course chat and also serves project
  discussion. It performs GET-only feature-specific discovery. An account/course/
  thread/receipt owner and the current open foreground visit retire old quotes.
  Missing or contradictory availability is a read error, never an assumed sale.
- The report remains first. The exhausted gate mounts only after the learner
  opens optional discussion, with a ready included full transcript and no pending
  send, full-thread hydration, draft-restore error or read failure. A valid higher tier gets an explicit
  upgrade CTA. Highest/unavailable gets no purchase CTA. Continue remains separate.
- Additive `reply_limit_reached` retains old `can_reply` entitlement semantics
  for v60. The server uses the same compulsory prompt floor and request/token/cost
  usage helper as offers. It is not a guarantee that an arbitrary large draft
  fits. The existing worker still reserves full context and attachment input.
  No quota reset, new allowance, provider request or monetary mutation is done
  by this read. Report failure and revoked rights are not quota exhaustion.
- Message-count exhaustion racing a send returns a stable 422 code. Mobile
  refreshes the existing GET owner without treating rejection as acceptance or
  consuming its durable draft. Generic 4xx failures do not offer an upgrade.
- Completed upgrade explicitly refreshes the full thread even when both plans
  remain enhanced. Return from a closed/background visit also refreshes capacity;
  the course summary intentionally omits quota. No parallel polling owner exists.
  A cached exhausted verdict cannot start offer discovery during this read;
  the controller passes its actual hydration state to the rendered panel.
- Dashboard pricing/feature preview and checkout retain the shared eligibility
  service. Exact paid difference and no reward spend are unchanged. No dashboard
  schema or new payment configuration is required for this presentation defect.

Authored, not executed: ready exhausted opt-in, highest unavailable, token/cost
verdict with messages remaining, pending/failed/summary/revoked/read/draft-error
non-upsell, quote failure retry, close/reopen and account replacement, same-level
receipt refresh, foreground return, stale project/read isolation, additive mapping,
server consumed/reserved capacity and coded rejection with no queued job. Existing
course chat, subscription, worker reservation and device journeys stay in the
final combined gate. Independent source review caught a cached-return gap:
an offer could begin before the full-thread refresh. The existing GET owner now
derives hydration synchronously from its access mismatch, and passes it through
the controller to the panel, protecting the first return render as well as the
in-flight period. The reviewer reread that binding and accepted this unit's
source with no remaining everyday blocker found. An additional actual-hook-to-
panel deferred return case is authored to pin that first-commit ordering. This
is not test/native/provider acceptance; no tests, builds, pushes, deployment or
native validation has run for this unit.

## Final gate — assistant read guard contract, 5 October 2026

The course journey contract freshly passed 16 cases and failed one old source
literal: `if (!remoteEnabled) return;`. The existing canonical-history owner
now guards the read with a compound condition. The corrected source assertion
requires all four actual conditions: active visit, assistant entitlement,
completed hydration and the same hydrated conversation. No guard, API, paid
turn, composer, entitlement or production behavior was changed.

The complete journey contract and actual-hook history-visit suites pass 34/34.
The latter verifies zero remote GET without entitlement, one GET after access
becomes available without resetting the draft, hidden/foreground visits, obsolete
responses and preserving live turn ownership without duplicate question POST.
Its account/native persistence and HTTP boundaries remain controlled doubles.
Independent read-only review accepted the unchanged product binding and coverage,
without rerunning tests. Evidence in `mobile/.cache/final-gate-20261005/`:
`mobile-course-journey-contract-reproduction.log` and
`mobile-course-journey-history-final.log`. This does not prove native chat,
backend/provider behavior, response quality or full release readiness.

## Final gate — stop ownership source contract, 5 October 2026

The async isolation suite retained an obsolete `stopConversationGeneration`
literal. The actual stop owner already captures its generation in `stopFlight`.
The assertion now requires the exact mounted/flight/generation/conversation
predicate and the account-boundary assertion plus ownership check after DELETE.
No production guard, endpoint, entitlement, dashboard or presentation changed.

The complete isolation and actual-hook cancellation suites pass 26/26 in
`mobile/.cache/final-gate-20261005/mobile-learning-stop-ownership-final.log`.
The latter covers preparation retirement, dispatched receipts across closing,
account/epoch replacement, old finally/send results and read-only reconciliation
of an unconfirmed cancellation. Network/account/native boundaries remain
controlled test doubles. Independent read-only review accepted this test-only
alignment, not native behavior, backend/provider integration or release readiness.
The prior reproduction is retained in
`mobile-learning-async-contract-reproduction.log`. Reference/adoption and reuse
boundaries above remain unchanged; this correction imports no reference code.

## Final gate — explicit chat callback dependencies, 5 October 2026

Full release lint reported five callbacks referencing returned conversation/turn
objects while listing only their member dependencies. The facade now destructures
those existing refs/functions and uses the same concrete values in callback
bodies and dependencies. It does not depend on freshly allocated owner objects,
create another consent owner or change the captured visit/account/generation
gates. No endpoint, quota, paid turn, attachment owner, dashboard or UX changed.

Scoped lint reports zero errors/warnings. The full consent/picker/history/stop
actual-hook suites pass 83/83 in
`mobile/.cache/final-gate-20261005/mobile-chat-callback-bindings-final.log`.
Independent read-only review accepted source equivalence and ownership. The
earlier full mobile gate passed 354 suites/3222 cases, but preceded this source
correction and is not proof of the current entire tree. A later combined gate
remains required. These controlled-boundary tests do not prove native/provider
behavior or release readiness. No new reference implementation was imported.
