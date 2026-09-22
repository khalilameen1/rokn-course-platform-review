# ROKN store review access — verified Android reviewer account

Updated 2026-09-14. The dedicated replacement reviewer account is registered as
ROKN user **14**, enrolled in the shared internal/license test, and verified on
Android release **58 (1.0.57)**. Google Play Billing resolved the live package,
completed a no-charge licensed test purchase for 900 coins, and the app then used
those coins through its normal checkout to enroll the reviewer in the Blender
course's mentor plan. Lesson access, mentor chat, enhanced project feedback and
certificate eligibility were visible; the post-enrollment balance is 35 coins.
The reusable Google login and English walkthrough were saved in Play Console's
private App access declaration with full paid/premium-content access selected.
Credentials remain out of this repository. Target audience, Data safety and the
store listing are complete. Play is set to 18+ only with Google-known minors
blocked. Four authentic release-58 screenshots and the generated feature graphic
are in the listing; the latter is disclosed as AI-generated. Production release
58 is staged for Egypt. Google's automated pre-submission checks completed
without a reported problem; only the final review request remains.
See [STORE_RELEASE_STATUS.md](STORE_RELEASE_STATUS.md) for artifact and backend pins.

A first dedicated Google review account was created on 2026-09-13 and registered
as ROKN user 13. It was replaced on September 14 by the final reviewer identity,
ROKN user 14, because the first account's password was not reusable. The final
account completed the normal app Google flow on emulator-5554, accepted the
internal-test invitation, resolved Play Billing and received the verified mentor
enrollment described above. The old user 13 remains historical and unenrolled.
See [CONSOLE_SETUP.md](../store/CONSOLE_SETUP.md) for console access, signing/OAuth
identities, app links and public policy-page evidence; those prerequisites are
separate from the reviewer account and product configuration below.

## Required before entering review instructions

### Owner phone-test access — updated September 14

After the owner's purchase on release 58, read-only command 188 at 00:18:53
Cairo on September 14 confirmed user 6's Google purchase 1 / order 17 for
`rokn.coins.900`: test environment, credited, approved/settled and finalized,
with zero cash revenue and no reversal or finalization retry. Course order 18
created active enrollment 8 in course 10, mentor plan 27 with chat enabled,
50 messages and certificate eligibility. This is owner acceptance evidence;
the separate final reviewer verification is recorded below. Refund/recovery
testing remains incomplete.

Earlier, read-only production command 184 confirmed that the owner's existing Rokn
account (user 6) already has paid mentor access to published Blender course 3:
learning, chat with a 50-message limit, enhanced project feedback and certificate
availability. No grant, balance adjustment or plan modification was needed.
The personal Khalil account (user 5) has no enrollment in that course. The final
reviewer account now has its own normally purchased mentor entitlement. The
shared Play tester list contains four approved identities and is selected for
both internal testing and license testing.

The internal-test link is
`https://play.google.com/apps/internaltest/4700165170808444276`.
Sign in to Play with an included identity and join the test before installing.
For a purchase test use Google's explicitly labeled test payment instrument,
not an ordinary saved card. Four release 58 candidate screenshots were captured
from the verified emulator session and uploaded. No old-build screenshot was
submitted as a candidate image.

- **Reviewer Google account: VERIFIED AND SAVED.** The final account is ROKN user
  14. It is a member of the four-account `Rokn internal QA` list used for both
  internal and license testing, accepted the internal-test invitation, signed in
  through the normal Google OAuth flow on emulator-5554 and required no OTP or
  operator intervention during verification. Its identifier, password and English
  instructions are stored only in Play Console's private App access declaration.
  Full paid/premium-content access is explicitly declared there; no reviewer
  password or authentication bypass was added to the app or repository.
- **Review content: PROVISIONED AND VERIFIED THROUGH THE NORMAL FLOW.** On release
  58, the account resolved `rokn.coins.900` at the localized EGP 11.11 price. The
  Google sheet explicitly identified the transaction as a test order with no
  charge and used the always-approves test instrument. The 900 coins were credited,
  then spent through the app's normal course checkout on published course 3,
  **أساسيات الرسم والتحريك في Blender**, mentor plan 12. The balance changed from
  935 to 35 coins. The first lesson loaded, the mentor Ask panel opened, and My
  Corner displayed the enrolled course with Continue. The plan includes learning,
  50 mentor messages, enhanced project feedback and certificate eligibility. No
  fake receipt, complimentary grant, balance edit or authentication bypass was used.
- **Deletion account: MISSING.** Use a separate disposable social account with no
  real purchases or valuable work; it must support fresh sign-in with the same
  provider. Never delete the reusable review account during the walkthrough.
- **Owner Google test purchase: VERIFIED; recovery/refund testing incomplete.** Laravel Cloud deployment 204
  runs `855f7293fe3e96682a87f2b79d69ba4238c98f87`, with backend code unchanged
  from deployment 202 / `3c72607`, including verified-test fulfillment/recovery.
  That implementation credits verified store tests with zero cash revenue and
  finalizes Google purchases after credit commits. Product `rokn.coins.900` is
  active and bound to package 4, and the Google credential is deployed. The
  service account is Active with the approved app-scoped permissions. OAuth and
  product reads succeeded. The earlier purchase-read denial in command 171 was
  superseded by command 172 at 12:23:52 UTC, which returned both OAuth and
  purchase-read success without a credential or permission change. Command 188
  subsequently verified the owner purchase and finalization recorded above.
  Authenticated Play test event
  `21807048248284341` reached production on September 13 at 13:57:27 UTC with
  no error, verified by command 173. Command 174 re-read the active product and
  matching Egypt price; command 175 enabled package 4's Google channel without
  changing its price or any user's balance. Cloud trial billing is active.
  Apple products are incomplete. See
  STORE_RELEASE_STATUS.md for the current console and configuration evidence.
  Deployment alone does not verify configuration or an end-to-end store purchase.
- **Public support contact: SAVED; private review contact not verified.**
  `support@rokn.app` and `https://rokn.app` were saved with the Console's Publish
  action. IARC uses that support email, but this does not establish reusable
  sign-in or the private reviewer-contact fields. Use the owner's supplied phone
  only in those private fields and verify the contact name against company records;
  do not copy the phone here. Keep someone available throughout review.
- **Audience eligibility: SAVED AS 18+ ONLY.** Google-known minors are blocked.
  This matches the live privacy policy and the current AI-provider eligibility
  boundary without inventing a parental-consent or minor-access flow. IARC's
  Generic 3+ content rating is separate from the target-audience declaration.
  See [console setup](../store/CONSOLE_SETUP.md).

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
   This path succeeded for both the owner and the final reviewer on release 58.
   The reviewer now has a pre-provisioned mentor entitlement and will not need to
   purchase access during review.
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
   and tells the owner; private work remains. The approved pre-publication review
   requires administrator approval of the current works/profile snapshot before
   that URL can display content. Edited works return to review. Verify the owner
   pending/rejected state and the corresponding dashboard decision after rollout.
   Use an actually approved harmless portfolio for the reporting walkthrough.
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
- [x] Owner Google licensed-test credit and server finalization verified by
  command 188 on release 58, followed by course 10 enrollment.
- [x] Final reviewer user 14 signed in on release 58, resolved the native Google
  package, completed a no-charge 900-coin licensed test purchase and bought course
  3's mentor plan through normal in-app checkout; lesson/chat access and 35-coin
  remaining balance were verified in the UI.
- [x] Play Console App access contains the final reusable Google credentials,
  English walkthrough and the full paid/premium-content-access declaration.
- [ ] Verify cancellation/pending handling, retry without duplicate coins,
  refund/recovery and repeat purchase. The successful owner purchase does not
  establish these cases or Apple's sandbox fulfillment.
- [ ] Verify the deployed consent/reporting/deletion endpoints through the real UI;
  new backend routes include `/api/v1/ai-consent` and `/api/v1/ai-content-reports`.
  Review `GET /api/health/launch-ready`, including its body on HTTP 503. Health and
  store notifications are intentionally outside `/api/v1/`.
- [ ] Record binary/version, device/OS, backend deployment, chosen course/project,
  account-access confirmation, and date/result for each walkthrough step in the
  private release record. Keep both stores' access instructions current and leave
  the reusable review account active. Unchecked items remain incomplete; Android
  reviewer purchase and mentor access are now verified, while the remaining
  consent/reporting/deletion and recovery cases are not.
