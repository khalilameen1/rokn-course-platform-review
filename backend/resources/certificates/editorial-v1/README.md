# Editorial certificate artwork v1

Frozen production assets shared by `editorial_v1` and `editorial_v2`.
Approved from certificate design iteration v8 on 15 September 2026.

- Canvas: 2800 × 1900 pixels, paper `#fcfcfa`, ink `#101c2d`
- No certificate heading, decorative seal, duplicated signer name or English signature
- Wordmark: existing Rokn mobile logo, tinted by the renderer without changing its alpha
- Signature: approved Arabic CEO signature asset, retaining its transparent padding
- Fonts: the app's Cairo Regular, Medium and SemiBold fonts under the included OFL
- Issued text, project evidence, course revision and QR destination are snapshots
- Existing unversioned credentials retain the legacy renderer and original assets

Authoring preview and issuance both use the native PHP renderer. PDF delivery wraps
the exact issued PNG instead of re-typesetting a second certificate. The offline QA
script renders synthetic examples only; its QR targets use `preview.invalid`.

Do not alter these assets or geometry after release. Create a new explicit design
version for future changes so missing-artifact recovery remains deterministic.

## Local refinement 19 September 2026

New issuance and authoring previews select `editorial_v2`. The wordmark is closer
to the statement, course titles use 48 instead of 42 logical pixels, the divider
uses a clearer two-pixel stroke, and the certificate identifier uses 13 instead
of 10 logical pixels. Text, signature, QR placement and footer alignment stay
unchanged. The renderer preserves v1 geometry for existing issued snapshots.

The approved blue refinement uses Rokn `#2c69db` on the wordmark and course title
only. `CertificateCornerArtwork` draws the quiet top-right and bottom-left
contours from code; generated sample text/signatures/QR pixels are never reused.
This finalizes the still-local v2 design, not a replacement for an issued v1.

Only local code and sample previews were changed. No server deployment, database
migration, store upload or issued-credential update was performed.
