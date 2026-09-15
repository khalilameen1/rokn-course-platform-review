# Editorial certificate artwork v1

Frozen production assets for `CertificateArtworkRenderer::VERSION` (`editorial_v1`).
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
