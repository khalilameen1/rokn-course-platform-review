# Additional four findings — 24 October 2026

Source: the user's second Gemini review, `العيوب الأربعة الإضافية المثبتة - مراجعة منصة ركن.docx`.
Reviewed against `89190bb0`, not an older production deployment. Deployment was paused before any promotion to main or store upload.

## 1. Completion claim prematurely closes playback — confirmed

The backend closed a session on `complete`/`is_completed`, before verified learning eligibility was computed. The mobile outbox also rejected all subsequent samples in that same session. Fixing only one side would not repair the journey.

Both sides now distinguish a completion claim from a terminal `stop`/terminal error. Rewinding retains the latest position, not the completed playhead. Coalescing a pending completion into a real stop retains its completion claim without dropping the stop. The existing durable outbox, account isolation and monotonically ordered session sequence remain the only delivery engine. The hook avoids streaming completion requests for every frame after confirmation.

Behavior tests exercise seek-to-95%, refusal, rewind, verified rewatch and successful completion in the same session; mobile tests cover online/offline rewind, in-flight completion/stop ordering and repeated checkpoint callbacks.

Reference: [Mux view definition](https://www.mux.com/docs/guides/understand-metric-definitions) and [playback events](https://www.mux.com/docs/guides/mux-data-playback-events) explicitly allow looping/seeking within one view and separate `ended` from `viewend`. This is lifecycle architecture guidance, not copied Mux source or a newly installed analytics SDK. Reused code: Rokn's existing typed durable outbox and Laravel transaction/service owners.

Adoption evidence, beyond documentation/stars: [Mux's published customer cases](https://www.mux.com/customers) include Patreon, Shopify and Skool; its [scale statement](https://www.mux.com/video-api) reports billions of streams across thousands of customers. This supports choosing its lifecycle model, not a claim that this patch integrates Mux or replicates its entire infrastructure.

## 2. Thirty content-second cap conflicts with faster playback — confirmed

The client sends on a 30 wall-clock-second cadence, while the old cap allowed only 30 content seconds. Normal 1.25x/1.5x/2x watches could fail the 80% requirement. The report's claim of impossibility in every circumstance was too broad: rewatching/additional flushes can change the result, but the normal single-watch defect is real.

The default content-second cap is now derived from accepted heartbeat gap × supported playback rate (45 × 2 = 90). The calculation still takes the minimum of actual position advance, elapsed server time × maximum rate, and the configured cap. The aggregate clock still prevents overlapping devices from double-crediting. The gap and seek defenses are not removed. No extra heartbeat traffic was added.

HTTP regressions cover normal 1x/1.25x/1.5x/2x cadence and the same-session premature seek test preserves the eligibility refusal. Deployment must check for an explicit legacy `LEARNING_MAXIMUM_CREDIT_PER_HEARTBEAT` override; defaults cannot override an intentional environment setting.

## 3. Free course-code upgrade blocked by paid floor — confirmed

The grant correctly captured Basic capabilities, but upgrade pricing incorrectly deducted its catalogue price as though the learner had purchased Basic. A zero-cost grant now contributes zero upgrade price credit. The existing current plan order, not just the original learning order, determines this: grant → Plus costs the Plus price; paid Plus → Pro remains the price difference. Reward coins are never spent on upgrades. Existing paid/coupon purchase difference rules and paid-floor checks remain unchanged.

One shared `CoursePlanUpgradeEligibilityService` still supplies offer visibility, quotes and settlement. No parallel mobile/dashboard formula or migration of immutable receipts was introduced. The test covers offer visibility, 80% paid floors, both upgrades, repeated authorization, unchanged rewards and preserved original grant lineage.

This is a Rokn-specific entitlement/pricing correction using existing Eloquent order receipts and aggregate owners, not a third-party pricing engine. No external library was found that could own this custom grant policy without duplicating the existing ledger.

## 4. Timely store purchase rejected on late receipt delivery — confirmed

Google/Apple verification already credits the purchased coins, so the old behavior did not erase the payment. It nevertheless failed to fulfill the authorized course when receipt delivery arrived after the quote's 15 minutes.

Binding now uses verified provider purchase time inside the authorized quote window, not delivery time. Expiry observed by a resume before the receipt arrives can recover. Explicit cancellation, later authorization for the same course, changed course terms, old/missing timestamps, wrong ownership/product/profile, and purchases actually made after the deadline never silently spend the credit. Receipt replay and course debit remain idempotent through the existing owners.

Reference: [Google Play processing/recovery](https://developer.android.com/google/play/billing/integrate) requires processing purchased transactions recovered after the app resumes. The existing store verification implementation, native bridge and wallet/order services are reused; no provider protocol was copied from a different payment gateway.

Fourteen provider/state regressions cover Google and Apple with delayed delivery, expiry already observed, cancellation (including cancellation after observed expiry), replacement consent, changed terms and genuinely late purchase. Each case repeats binding and checks both enrollment count and exact remaining paid balance.

## Verification at handoff

- Focused backend run: 106 cases passed; six initial Apple test-fixture errors were caused by trying to edit an immutable Google order into an Apple order. The fixture was corrected to create the actual provider order initially; all 12 delayed-receipt cases then passed (50 assertions). No production immutability guard was weakened.
- Mobile playback/outbox focused tests: 34 passed; completion/navigation tests: 5 passed. Full mobile release suite: **379 suites, 3487 tests passed, exit 0**; TypeScript and changed-file ESLint passed.
- Full backend CI: pending final revision. Do not treat this document as release approval until final results are recorded.
- No production deployment, main promotion or Play upload performed as part of this review so far.
