# Native Apple sign-in on managed hosting

This retains the existing `expo-apple-authentication` native flow and Firebase
PHP-JWT ES256 signing. No competitor source is claimed or copied. The secret-env
versus private-file transport follows the existing Rokn Apple purchase verifier;
the credentials remain separate. Apple's required credential lifecycle is the
reference: https://developer.apple.com/help/account/capabilities/create-a-sign-in-with-apple-private-key

Set these only in the server's private environment configuration:

- `APPLE_CLIENT_ID=com.rokn`
- `APPLE_TEAM_ID` and `APPLE_KEY_ID` from the Apple developer team's Sign in with
  Apple key associated with this primary App ID
- `APPLE_PRIVATE_KEY_BASE64` containing the base64-encoded downloaded P-256 key,
  **or** `APPLE_KEY_FILE` pointing to a readable private file supplied on every
  deployment
- Preserve existing `SOCIAL_AUTH_PROVIDERS` and append `apple` when enabling it

Base64 is encoding, not encryption. Never commit its value, print it in command
output, embed it in Cloud command history, or put it in the mobile binary. The
App Store purchase-verification key and general App Store Connect API credentials
are not authentication keys.

An explicitly supplied invalid base64 key fails closed instead of silently using
an older file. Readiness checks a parseable P-256 private key using OpenSSL, the
same primitive Firebase JWT uses for the signature. The previous capability
fixtures pointed at PHP source files rather than real signing material; they now
generate disposable test keys. Exchange retains the existing identity, nonce,
encrypted refresh-token and account-deletion revocation contracts.

The focused fixtures cover file-backed signing, cloud-secret signing and actual
verification of the client-secret signature, malformed and RSA secret rejection,
and provider readiness. These cases are added to the final gate; adding them is
not a claim that they have run or that native Apple sign-in has succeeded.
