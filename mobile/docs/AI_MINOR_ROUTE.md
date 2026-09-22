# AI provider route for learners aged 12+

Verified: 14 September 2026. Operational handoff; not legal certification or store approval.

## State and decision

- Candidate: direct Anthropic commercial Messages API for chat and project feedback.
- Initial model candidate: `claude-sonnet-5`, already used for projects through OpenRouter.
- **NOT ENABLED:** no runtime endpoint/model change, key creation or live child-data request is evidenced by this document.
- The owner authorized replacement/setup. Direct Console onboarding is in progress separately; account readiness is not yet proven.
- Keep release access gated until account, child-consent, safety and deployment checks below pass.
- An adult provider account does not remove obligations toward downstream minor users.

## Verified provider position

- Anthropic's commercial terms allow customers to power products for their own end users; Claude.ai consumer terms are separate.
- Its Usage Policy expressly covers products serving minors and requires the additional minors guidance.
- It requires AI disclosure at least at the beginning of each chat session.
- Minors guidance calls for appropriate age checks, moderation/filtering, monitoring/reporting, safe-use guidance and child-privacy compliance.
- No blanket API minimum age of 13 or universal minors-ZDR prerequisite was found in those reviewed documents. This is not an exemption from applicable law.
- Egypt appears in the API supported-country list. Availability does not prove this organization's access, funding or rate limits.
- Commercial API content is excluded from model training under the commercial terms.
- Standard API retention is up to 30 days with feature, safety and legal exceptions; flagged inputs/outputs may remain up to two years and safety scores seven years.
- ZDR requires organization-specific enablement via Anthropic sales and has feature/model/safety/legal exceptions. Do not promise it before verification.
- The retention page's named Covered Models exclude Sonnet 5 at verification time; recheck before enabling or changing models.

## Required account and policy evidence

- [ ] Verify the correct Rokn commercial organization, authorized administrator, Egypt eligibility, billing and server-only API credential.
- [ ] Confirm the exact enabled model, usable rate limits and spend cap with a synthetic, non-personal smoke test.
- [ ] Describe to Anthropic: Egypt-operated educational app, ages 12-17, Arabic tutoring, image/PDF projects, territories and safeguards.
- [ ] Request written confirmation of any use-specific approval/child-safety prompt, applicable DPA, retention and optional ZDR requirements.
- [ ] Record the actual account/retention configuration; a support enquiry is not approval.
- [ ] Verify Egyptian child-data/cross-border requirements and each intended market's rules with qualified local guidance.
- [ ] Where COPPA applies, implement direct parent notice and verifiable parental consent before covered collection, not only before the AI call.
- [ ] Implement guardian review, withdrawal and deletion handling. A signed returned consent form is one FTC-listed method; a manual first workflow can be considered.

## Current code gaps

- `backend/app/Services/AiConsentService.php` checks active user, consent version and timestamp only; it does not establish age or guardian authority.
- `mobile/src/services/aiConsent.ts` offers learner self-consent and names OpenRouter/model providers. It is not verified parental consent.
- No age/guardian verification fields were found in the inspected application/config/migrations.
- `backend/app/Services/AiPromptPolicy.php` contains refusal instructions, permits general questions and discloses identity when asked. A prompt is not independent moderation.
- `backend/config/openrouter.php` defaults to Gemini chat, Claude projects, provider data collection allowed, ZDR off, web search on and a Cloudflare PDF parser.
- `backend/resources/lang/{ar,en}/privacy.php` and mobile privacy/consent copy describe OpenRouter and broad provider-dependent retention.
- `backend/app/Services/OpenRouterService.php` normalizes OpenRouter streaming, errors, token usage and reported cost.
- `backend/app/Services/OpenRouterCostReconciliationService.php` calls OpenRouter `/generation`; direct Anthropic cannot use that reconciliation path.

## Bounded implementation after evidence is confirmed

- [ ] Add the smallest native Messages adapter preserving existing durable paid-call orchestration and the current `chat()` result contract.
- [ ] Convert system/content blocks, images/PDFs, stream events, refusal/errors and token accounting to Anthropic's documented contract.
- [ ] Keep API credentials on the backend; preserve timeout, cancellation, uncertain-call quarantine and no-double-charge behavior.
- [ ] Make cost reconciliation provider-aware; never label token-price estimates as provider-confirmed charges.
- [ ] Disable Gemini/OpenRouter fallback on the new child-serving route; do not silently cross to another processor.
- [ ] Initially omit web search, external parsers, Files API and code execution unless their child-data and retention paths are approved/documented.
- [ ] Add age/guardian authorization at appropriate collection boundaries and recheck authorization before queued provider execution.
- [ ] Add evaluated Arabic/English input/output safeguards, reporting and human escalation; include an upload detection/response plan.
- [ ] If moderation runs after generation, do not expose unreviewed streaming output first.
- [ ] Display AI identity every chat session; update Arabic/English/mobile disclosures and bump the account-bound consent version.
- [ ] Verify revoked/missing consent, underage paths, unsafe text/uploads, provider failures, accounting and synthetic end-to-end success before release.

## Alternatives

- Direct OpenAI: under-13/below digital-consent-age personal data requires implemented API ZDR first; approval is required and `store:false` alone is insufficient.
- Azure OpenAI: Microsoft-hosted processing and strong controls are documented, but specific 12-year-old authorization was not established in this review; do not infer it from an adult subscription.

## Primary sources

- [Anthropic commercial terms](https://www.anthropic.com/legal/commercial-terms) and [Usage Policy](https://www.anthropic.com/legal/aup).
- [Minors guidance](https://support.claude.com/en/articles/9307344-responsible-use-of-anthropic-s-models-guidelines-for-organizations-serving-minors) and [developer child safety](https://support.claude.com/en/articles/15591275-child-safety-guidance-for-developers).
- [Supported countries](https://www.anthropic.com/supported-countries), [API overview](https://platform.claude.com/docs/en/api/overview) and [model IDs](https://platform.claude.com/docs/en/about-claude/models/model-ids-and-versions).
- [Commercial retention](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data), [ZDR eligibility](https://platform.claude.com/docs/en/manage-claude/api-and-data-retention) and [sales contact](https://claude.com/contact-sales).
- [FTC COPPA compliance plan, May 2026](https://www.ftc.gov/business-guidance/resources/childrens-online-privacy-protection-rule-six-step-compliance-plan-your-business).
- [OpenAI under-18 API guidance](https://developers.openai.com/api/docs/guides/safety-checks/under-18-api-guidance) and [API data controls](https://developers.openai.com/api/docs/guides/your-data).
- [Azure data handling](https://learn.microsoft.com/en-us/azure/foundry/responsible-ai/openai/data-privacy) and [Microsoft AI Code of Conduct](https://learn.microsoft.com/en-us/legal/ai-code-of-conduct).
