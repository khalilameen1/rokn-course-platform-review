# Website wallet checkout

`/recharge` is a mobile-first browser top-up page. `/wallet` remains the
installed-app link. No app payment button, store product or mobile price was
changed by this feature.

## One account and one financial path

- Sign in with Google, Facebook or TikTok using the exact identity already
  linked in the app. The page does not create another account or match by an
  unverified email. Apple browser sign-in is not available in the current
  provider registry; an Apple-only account cannot use this flow yet.
- The `student` cookie guard is separate from dashboard `web` sessions and
  native API tokens. Browser sign-in/out does not invoke device-login policy,
  revoke native tokens, change the locked phone or grant staff access.
- Available packages, coin quantities and direct prices come from the same
  catalogue and `PackageChannelPricingService` used by Kashier in the app.
  The admin setting **خصم الشحن عبر كاشير** controls the existing direct
  discount. This release does not change its stored value or infer store fees.
- Orders, price snapshots, pending-attempt recovery, gateway signature/API
  verification and wallet fulfillment are shared with the existing mobile
  checkout. Opening or returning from the gateway does not grant coins.
- The website supplies only a different fixed return route to the shared
  checkout service. It does not collect card data, invent payment methods or
  use browser-provided success/price/account values as financial evidence.

## Deployment

Until the branded domain is connected, leave `WEB_WALLET_URL` blank: the page
uses the deployment's `APP_URL` as its canonical origin. Once `rokn.app`
serves this same Laravel deployment with HTTPS, set `WEB_WALLET_URL` to
`https://rokn.app` and refresh the configuration cache. Also retain the host
in `APP_TRUSTED_HOSTS` if using a domain other than the existing Rokn domains.
Do not change `SOCIAL_AUTH_PUBLIC_API_URL` or the already registered provider
callback URLs just to enable the wallet page. OAuth still returns through
those API callbacks, then reaches the fixed website completion endpoint.

The canonical-origin redirect runs before saving the browser verifier to
avoid losing the session between www, apex and the Laravel origin. No DNS
change or replacement of the old rokn.app deployment is included here.

Keep `/recharge` out of mobile settings/legal links or in-app calls to use
an alternate checkout unless the relevant storefront rules/program permit
that placement. This is an independent website checkout, not a change to
store checkout routing.

## Verification

Focused cases are in `tests/Feature/WebWalletTest.php`. They cover identity
separation, canonical OAuth return, native-session preservation, account
deletion freshness, package eligibility/pricing, duplicate checkout, order
ownership, read/write rate-limit separation, receipt recovery and unchanged
mobile callback behavior. Existing Kashier payment/reconciliation and mobile
result-view suites remain the shared financial regression coverage.

Before live availability, verify a real browser login and one Kashier capture
on the chosen origin and confirm the resulting balance in the app. An
automated provider fixture is not evidence of a live provider transaction.
