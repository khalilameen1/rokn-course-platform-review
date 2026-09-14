# Course checkout commercial policy

## Offer and receipt versions

- Stable API codes remain `basic`, `guided`, `mentor`; the new default names are Basic, Plus, Pro.
- New Basic definitions and explicit course-editor saves produce watch-only offers: `projects_enabled=false`, no chat, project reports, follow-up or certificate.
- The schema migration does **not** rewrite existing live Basic offers. Publish the new offer through normal staged course authoring/revision controls.
- Purchased order/enrollment snapshots are immutable. Version 6 explicitly includes `projects_enabled`. Versions 1–5 preserve their historical project entitlement, even after the live offer changes.
- `CourseAccessPlanService::projectsEnabledForEnrollment()` owns legacy fallback and fails closed for malformed plan-backed receipts.
- Deploy the version-6 MySQL CHECK migration before enabling new writers. It retains all historical schema branches. Do not restore an older CHECK after version-6 orders exist.

## Promotions

`settings.max_course_promotion_percent` is the combined percentage ceiling for earned coins and coupons. Its initial value is 20. The older `max_reward_contribution_per_course` remains an independently stored historical **coin amount**, not a percentage.

Purchased coins are not promotional discounts. The checkout policy applies the combined allowance cumulatively across upgrades. The admin editor version includes the percentage so stale forms cannot overwrite a newer policy silently.

## Pricing controls

The suggested Basic/Plus/Pro price positioning is 0.60 / 1.00 / 1.50 relative to Plus. These are suggestions, not automatic changes to existing live prices.

Each plan's nullable `delivery_cost_usd` records the allocated per-enrollment cost of content production, delivery, support and mandatory project checks. It excludes the provider budgets already held by the server. NULL is uncosted; it is never interpreted as zero. Only an administrator can submit this field through the course editor.

`ROKN_NET_USD_PER_PAID_COIN` must be the conservatively verified net retained value after payment fees, indirect taxes and FX. It is **not** the retail face value, and no fee/tax deduction is applied again in the floor calculation. Use the least funded enabled channel/package exposure when adopting one global value. This planning model does not replace settled-lot accounting.

The model computes

```
full cost = delivery allocation + active provider budgets × safety multiplier
required purchased coins = ceil(full cost / (net value × (1 − target margin)))
required list price = ceil(required purchased coins / (1 − promotion ceiling))
```

The default contribution target is 40 percent before income tax. Both the offer price and its purchased-coin floor must pass. Costed offers are checked on course saves, publication, global provider-capacity changes and promotion-policy changes.

Set `ROKN_ENFORCE_COMMERCIAL_FLOOR=true` for commercial activation after finance inputs are complete. New purchases/upgrades must call `CourseAccessPlanService::assertPurchasableEconomics()`. In strict mode existing uncosted offers are blocked with a generic public availability message; provider cost data is not returned to the learner. This check never changes an old purchase receipt.

An explicit internal-test configuration can leave strict mode off. The dashboard and publication warnings then label uncosted offers unverified. Successful internal purchase testing is not evidence that the test prices are profitable commercial prices.
