# Apple StoreKit 2 purchase verification routing

## Normal failure being removed

Live configuration probe 272 on Laravel Cloud (2026-10-07) proved that the
configured key produced an authenticated Sandbox response, HTTP 404 / error
4040010 for a deliberately nonexistent transaction, while the production
endpoint returned HTTP 401. This was not a purchase and did not change balances.
The old gateway started every verification against production and reached
Sandbox only after 4040010. A first-release TestFlight/App Review purchase could
therefore never reach its correct endpoint.

## Proven references and actual reuse

- Apple signed transaction verification contract and open implementation:
  https://github.com/apple/app-store-server-library-node/blob/main/jws_verification.ts
  `verifyAndDecodeTransaction` verifies a device or server JWS, bundle and
  environment. This is an architectural reference, not copied TypeScript code
  and not a claim that the PHP verifier is Apple's official SDK.
- RevenueCat's shipped StoreKit 2 transition replaces app receipts with signed
  transactions:
  https://www.revenuecat.com/blog/engineering/revenuecat-sdk-5-0-the-storekit-2-update
  Actual adoption, not GitHub stars:
  https://www.revenuecat.com/customers/vsco
- Actual native dependency remains the installed `expo-iap` / OpenIAP bridge.
  Its `PurchaseCommon.purchaseToken` contract explicitly supplies iOS JWS.
  Rokn already sends it unchanged in `nativeStoreReceipt.ts`.
- Actual cryptographic dependency reuse remains the installed Firebase PHP-JWT
  ES256 implementation and OpenSSL certificate verification. No new dependency,
  provider, billing service, copied competitor source or license change.

## One verification owner

The gateway verifies the device transaction signature and matches its bundle,
product, transaction, Rokn account binding, consumable type, quantity and
environment before choosing a fixed Apple endpoint. Only Production and Sandbox
are allowed. An unsigned client environment cannot select the endpoint.

It then fetches the latest server transaction and repeats the same checks on
that independently Apple-signed snapshot. Credit remains downstream of successful
online verification; the device JWS alone never grants coins. HTTP authorization,
rate-limit and availability failures remain retryable instead of claiming the
learner did not pay. There is no environment fallback chain.

The mobile request/response contract, acknowledgement-after-credit behavior,
course checkout binding and dashboard financial reporting are unchanged.
The verified environment still separates Sandbox from real revenue. Google Play
verification and Android 60 compatibility are untouched. No released iOS client
uses a legacy StoreKit 1 receipt contract in this project.

## Verification status

`AppleStorePurchaseRoutingTest` defines signed Sandbox/Production routing,
tampered/foreign evidence rejection, updated server revocations, missing signed
server evidence, connection/auth/rate-limit retry behavior and no cross-environment
lookup. Its disposable EC certificate fixture never uses the operator's key.
Execution is deliberately deferred to the user's final test/build gate. No test,
native purchase, payment finalization, deployment or release success is claimed
for this local change until that gate is actually run.

Independent static review on 2026-10-07 accepted this environment-routing scope
without an actionable P1/P2 finding. It checked the installed expo-iap 5.4.1
contract, appAccountToken binding, finalization-after-credit and the backend's
Sandbox exclusion from dashboard revenue. That is source review only: the
disposable two-certificate fixture is not proof of Apple's real chain, a native
purchase, or end-to-end payment readiness.
