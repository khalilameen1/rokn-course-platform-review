import test from 'node:test';
import assert from 'node:assert/strict';
import { forbiddenAssetHashes } from '../frontend-asset-policy.mjs';
import { canonicalPublicAssetBytes } from '../public-asset-hash.mjs';
import { validateExternalTag, validateFileInventory, validateRepository } from '../verify-public-assets.mjs';

test('current repository satisfies the fail-closed public asset policy', () => {
    assert.deepEqual(validateRepository(), []);
});

test('mutable external package URLs are rejected', () => {
    const errors = validateExternalTag('<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>');
    assert.match(errors.join('\n'), /Unpinned or unclassified/);
});

test('static CDN assets without exact SRI are rejected', () => {
    const url = 'https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js';
    const integrity = 'sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ';
    const allowed = [{ url, integrity }];
    assert.match(validateExternalTag(`<script src="${url}"></script>`, '<test>', allowed).join('\n'), /SRI/);
    assert.match(validateExternalTag(`<script src="${url}" integrity="wrong"></script>`, '<test>', allowed).join('\n'), /SRI/);
    assert.match(validateExternalTag(`<script src="${url}" integrity="${integrity}"></script>`, '<test>', allowed).join('\n'), /crossorigin/);
    assert.deepEqual(validateExternalTag(`<script src="${url}" integrity="${integrity}" crossorigin="anonymous"></script>`, '<test>', allowed), []);
    assert.match(validateExternalTag(`<script src="${url}" integrity="${integrity}" crossorigin="anonymous"></script>`).join('\n'), /Unpinned or unclassified/);
});

test('unclassified public additions are rejected', () => {
    const errors = validateFileInventory([{ path: 'public/rogue.js', sha256: 'abc' }], []);
    assert.match(errors.join('\n'), /Unclassified/);
});

test('restricted font bytes are rejected even when renamed', () => {
    const restricted = [...forbiddenAssetHashes][0];
    const entry = { path: 'public/assets/renamed.bin', sha256: restricted };
    const errors = validateFileInventory([entry], [{ ...entry, classification: 'first_party' }]);
    assert.match(errors.join('\n'), /Restricted or unproven font bytes/);
});

test('text asset fingerprints are stable across operating-system line endings', () => {
    const lf = canonicalPublicAssetBytes('public/app.css', Buffer.from('a\nb\n'));
    const crlf = canonicalPublicAssetBytes('public/app.css', Buffer.from('a\r\nb\r\n'));

    assert.deepEqual(crlf, lf);
});
