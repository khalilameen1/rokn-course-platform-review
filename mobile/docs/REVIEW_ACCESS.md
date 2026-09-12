# ROKN store review access — operational draft

Prepared 2026-09-12 against source `902fe80`. This is **not a verified reviewer
account or a ready-to-submit access declaration**. See [STORE_RELEASE_STATUS.md](STORE_RELEASE_STATUS.md)
for the exact Android artifact and undeployed backend changes. No account,
entitlement, product, payment, or production setting was changed for this guide.
See [CONSOLE_SETUP.md](../store/CONSOLE_SETUP.md) for console access, signing/OAuth
identities, app links and public policy-page evidence; those prerequisites are
separate from the reviewer account and product configuration below.

## Required before entering review instructions

- **Reusable reviewer social account: MISSING.** Record the chosen provider,
  dedicated account identifier, working sign-in instructions, and access to all
  restricted features in the stores' private review fields, not this repository.
  The current app presents social sign-in; it has no reviewer password/backdoor.
  A provider challenge requiring an operator's phone or one-time intervention is
  not verified reusable access. Resolve that through an approved account/access
  arrangement; do not weaken production authentication or invent credentials.
- **Review content and access: NOT SELECTED/VERIFIED.** Record an actual published
  course title, guest sample, unit/project, and the review account's plan/access.
  Prepare separate documented access for the three plan behaviors below through
  the normal authorized workflow. Do not assume a new social account already has
  coins, an enrollment, AI quota, a completed project, or a certificate.
- **Deletion account: MISSING.** Use a separate disposable social account with no
  real purchases or valuable work; it must support fresh sign-in with the same
  provider. Never delete the reusable review account during the walkthrough.
- **Store payments and backend rollout: PENDING.** Last documented production
  inspection found no Google/Apple product IDs in package responses. Production
  still rejects verified test/sandbox receipts with `store_test_purchase_not_allowed`.
  Financial-policy approval, fulfillment/recovery work, configuration and an
  actual end-to-end store test remain prerequisites, not completed steps.
- **Review contact: NOT ENTERED/VERIFIED.** The owner has supplied
  `support@rokn.app` and a contact phone number in the conversation. Use the
  supplied details in the private console fields and confirm the contact name
  against the company record; do not copy the private phone into this repository.
  Keep someone available throughout review.

Google requires English instructions, reusable access from any location, and
complete details for third-party sign-in and paywalled content. Enter them under
Play Console's App content → App access; do not declare unrestricted access.
[Google sign-in requirements](https://support.google.com/googleplay/android-developer/answer/15748846?hl=en).
Apple requests valid demo access and any special instructions in App Review
Information, with backend services available during review.
[Apple review preparation](https://developer.apple.com/app-store/review/),
[App Review Guidelines, Before You Submit](https://developer.apple.com/app-store/review/guidelines/#before-you-submit).

## Walkthrough to verify, then give to reviewers

These are source-backed UI paths, not a claim they passed on the submitted binary.
Replace the unspecified course/account references with verified details before submission.

1. **Guest sample:** Open a course from the home catalog without signing in.
   On the selected course, tap **شاهد مجانًا** (Watch free), or open
   **محتوى الكورس** (Course contents) and a sample marked **شاهد** (Watch).
   Only an explicitly configured preview is guest-accessible. Record the actual
   sample title; a full paid lesson is not a guest sample.
2. **Sign in:** Tap **سجّل الدخول لفتح الكورس** (Sign in to unlock the course),
   or the profile sign-in action. Use the supplied provider's **المتابعة بحساب
   Google / TikTok / Apple / Facebook** button only if offered. Provider
   availability comes from `GET /api/v1/auth-methods`; Apple is iOS-only in the UI.
   A configured source button does not prove the live provider is operational.
3. **Coins, then course purchase:** Open **المحفظة** (Wallet) → **شحن الرصيد**
   (Top up) → **اختيار الباقة** (Choose package). The Play/App Store build should
   open its native store purchase sheet. The localized price comes from the store.
   After verified credit, return to the course → **اختر الفئة المناسبة لك**
   (Choose your plan) or **شراء الكورس** (Buy course) → **تأكيد الشراء** (Confirm).
   Buying coins alone does not enroll the account; the course confirmation spends
   the displayed usable balance. Never substitute an external checkout link.
   Do not describe this step as working until the payment blocker above is resolved.
4. **Projects by plan:** Start/resume the designated enrolled course, complete its
   required unit, and open its project from the course contents/transition. Use
   a harmless, non-personal sample file matching that project's instructions.
   Default plans are **التعلّم** (`basic`: pass-only project result, no course
   chat or generated project output), **التعلّم بإرشاد** (`guided`: course chat
   and project report, no report follow-up or generated output), and
   **التعلّم بمتابعة** (`mentor`: chat, enhanced report, follow-ups and output).
   Actual access-plan flags/quotas are authoritative. Certificates require their
   normal completion conditions; they are not issued merely by choosing a plan.
5. **AI consent, answer, report:** In an eligible lesson tap **اسأل** (Ask), or
   initiate project review. The first action displays **الاستفسارات ومراجعة
   المشاريع**, explaining transfer of text, attachments and context to OpenRouter
   and model providers. **ليس الآن** (Not now) declines; **سياسة الخصوصية** opens
   the policy without accepting; **أوافق وأتابع** explicitly accepts. Verify
   decline preserves the draft and does not submit new AI work. After accepting,
   send a harmless question, wait for the answer, then use **الإبلاغ عن الرد** →
   **إرسال البلاغ** once if testing reporting. Follow-up messages on a project
   report require the appropriate plan. Settings → **مشاركة البيانات للذكاء
   الاصطناعي** → **إيقاف المشاركة** withdraws consent for new requests; already
   started work may finish. Reports are followed through Settings → **تواصل معنا**.
6. **Portfolio report:** Profile → **أعمالي** (My work) → **مشاركة** (Share) opens
   the owner's existing unlisted portfolio URL. On that web page, use
   **الإبلاغ عن محتوى**, describe the issue, optionally provide a contact email,
   then **إرسال البلاغ**. Do not publish abusive material to demonstrate reporting
   or send repeated test reports. Sharing suspension hides the public page/media
   and tells the owner; private work remains. Pre-publication moderation and
   viewer blocking are still unresolved release scope, not existing features.
7. **Delete the disposable account last:** Profile → **الإعدادات** → **حذف الحساب**.
   Read the loss-of-data/balance warning, confirm, and authenticate again with the
   same social provider when requested. Verify sign-out and loss of the old
   account's access. **تم إغلاق الحساب** can mean media cleanup remains pending;
   do not promise instant erasure of every retained record. The fallback request
   page is `https://rokn.app/account-deletion`, not a substitute for testing the
   in-app flow. Apple authorization revocation also requires deployed credentials.

## Product/configuration handoff — operators only

- For each existing package, select the **real console product ID** and record
  its fixed coin quantity. Backend package fields are `name_ar`, `name_en`,
  `price`, positive integer `coins`, `is_active`, `sort_order`,
  `google_product_id` + `google_enabled`, and `apple_product_id` + `apple_enabled`.
  `direct_enabled` is separate. IDs are unique; once a store ID is assigned, its
  mapping and coin quantity are immutable. Create a new contract for a changed
  quantity rather than repurposing an issued product. No product IDs are assigned
  by this document. Configure consumable, one-time coin products, not subscriptions;
  multi-quantity is unsupported and must remain disabled.
- Google server configuration: `GOOGLE_PLAY_PACKAGE_NAME=com.rokn`,
  `GOOGLE_PLAY_SERVICE_ACCOUNT_FILE` **or** `GOOGLE_PLAY_SERVICE_ACCOUNT_BASE64`,
  `GOOGLE_PLAY_RTDN_AUDIENCE`, and `GOOGLE_PLAY_RTDN_SERVICE_ACCOUNT_EMAIL`.
  Verify Android Publisher access for this app and authenticated RTDN delivery to
  `POST /api/store-notifications/google`. Register the actual upload certificate
  and test-track access in Play Console; neither is established by a local signature check.
- Apple server configuration: `APPLE_STORE_BUNDLE_ID=com.rokn`,
  `APPLE_STORE_ISSUER_ID`, `APPLE_STORE_KEY_ID`, and
  `APPLE_STORE_PRIVATE_KEY_FILE` **or** `APPLE_STORE_PRIVATE_KEY_BASE64`.
  The code pins Apple Root CA G3; `APPLE_STORE_ROOT_CERTIFICATE_SHA256` is only
  for a documented additional trusted root. Configure notifications to
  `POST /api/store-notifications/apple`. Store API keys are distinct from
  Sign in with Apple lifecycle credentials. Never place private keys here.
- App login identity and store purchase-testing identity are different concerns.
  Provision internal license/sandbox testers through the respective console;
  an Apple Sandbox account does not supply a reusable ROKN social login.
  [Apple sandbox account setup](https://developer.apple.com/help/app-store-connect/test-in-app-purchases/create-a-sandbox-apple-account).

## Narrow pre-submission evidence checklist

- [ ] On the intended backend, `GET /api/v1/packages` has correct `id`, `coins`,
  `store_products.google`/`.apple` and boolean `channels.google`/`.apple` for each
  enabled product; the native store resolves matching IDs and localized prices.
- [ ] `GET /api/v1/product-features` permits `checkout`; its server default is
  `PRODUCT_CHECKOUT_ENABLED`, subject to configured overrides. Authenticated
  `GET /api/v1/store-billing/context` returns `google_obfuscated_account_id` and
  `apple_app_account_token`; preserve `STORE_BILLING_ACCOUNT_BINDING_KEY` across
  deployments. The app binds receipts and calls `POST /api/v1/store-purchases/verify`
  with `provider`, `product_id`, `purchase_token`, optional `transaction_id`.
  Do not hand-submit fabricated receipts or infer success from the native sheet alone.
- [ ] Record actual successful credit, cancellation/pending handling and retry
  without duplicate coins. Sandbox fulfillment and Google server finalization
  remain pending; do not mark this checkbox based on scaffolding or unit tests.
- [ ] Verify the deployed consent/reporting/deletion endpoints through the real UI;
  new backend routes include `/api/v1/ai-consent` and `/api/v1/ai-content-reports`.
  Review `GET /api/health/launch-ready`, including its body on HTTP 503. Health and
  store notifications are intentionally outside `/api/v1/`.
- [ ] Record binary/version, device/OS, backend deployment, chosen course/project,
  account-access confirmation, and date/result for each walkthrough step in the
  private release record. Keep both stores' access instructions current and leave
  the reusable review account active. All checkboxes above are currently unverified.
