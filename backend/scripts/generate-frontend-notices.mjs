import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
import { externalStaticAssets, thirdPartyFamilies } from './frontend-asset-policy.mjs';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outputPath = resolve(repositoryRoot, 'public/THIRD_PARTY_NOTICES.frontend.md');
const expectedBundlePackages = ['bootstrap', 'jquery', 'popper.js'];

function packageNameFromInput(input) {
    return input.replace(/\\/g, '/').match(/(?:^|\/)node_modules\/((?:@[^/]+\/)?[^/]+)/)?.[1] ?? null;
}

async function verifyBundledPackageInventory() {
    const result = await build({
        entryPoints: [resolve(repositoryRoot, 'resources/js/app.js')],
        bundle: true,
        platform: 'browser',
        target: 'es2018',
        write: false,
        metafile: true,
        logLevel: 'silent',
    });
    const detected = [...new Set(Object.keys(result.metafile.inputs).map(packageNameFromInput).filter(Boolean))].sort();
    if (JSON.stringify(detected) !== JSON.stringify([...expectedBundlePackages].sort())) {
        throw new Error(`Frontend legal inventory does not match bundled packages: ${detected.join(', ')}.`);
    }
}

function legalText(path) {
    const absolute = resolve(repositoryRoot, path);
    if (!existsSync(absolute)) throw new Error(`Legal text source is missing: ${path}`);
    const source = readFileSync(absolute, 'utf8');
    if (path.endsWith('node_modules/popper.js/dist/umd/popper.js')) {
        const notice = source.match(/\/\*\*![\s\S]*?\*\//)?.[0];
        if (!notice || !/Permission is hereby granted/.test(notice)) throw new Error('Popper.js complete bundled license notice is missing.');
        return notice.trim();
    }
    const text = source.trim();
    if (text.length < 100) throw new Error(`Legal text source is incomplete: ${path}`);
    return text;
}

function renderLegalBlocks(legal) {
    return legal.map(([label, path]) => [
        `### ${label}`,
        '',
        `Exact legal text source: \`${path}\``,
        '',
        '~~~text',
        legalText(path),
        '~~~',
    ].join('\n')).join('\n\n');
}

function renderLocalFamily(family) {
    return [
        `## ${family.name} ${family.version}`,
        '',
        `License: ${family.license}`,
        `Pinned source: ${family.source}`,
        `Distributed artifacts: ${[...family.artifacts, ...(family.inlineArtifacts ?? [])].join(', ')}`,
        `Modifications/provenance: ${family.modifications}`,
        family.id === 'sufee' ? 'Public corresponding source: `/legal/source/sufee-1.0.0-rokn/README.txt` (the manifest binds it to the served CSS SHA-256).' : '',
        '',
        renderLegalBlocks(family.legal),
    ].filter((line, index, values) => line !== '' || values[index - 1] !== '').join('\n');
}

function renderExternalAsset(asset) {
    return [
        `## ${asset.name} ${asset.version} (runtime CDN)`,
        '',
        `License: ${asset.license}`,
        `Exact URL: ${asset.url}`,
        `Subresource integrity: ${asset.integrity}`,
        '',
        renderLegalBlocks(asset.legal),
    ].join('\n');
}

function renderNotice() {
    return [
        '# Frontend Third-Party Notices',
        '',
        'This generated notice covers the compiled application bundle, the active legacy/admin public tree, and exact-version runtime CDN assets. It does not assume that a bundle notice covers unrelated legacy files.',
        'Every retained public file is separately classified and hash-pinned by `resources/legal/frontend/public-asset-inventory.json`.',
        'Run `npm run notices:frontend` after changing frontend dependencies or legal metadata.',
        '',
        ...thirdPartyFamilies.map(renderLocalFamily),
        '',
        ...externalStaticAssets.map(renderExternalAsset),
        '',
    ].join('\n');
}

await verifyBundledPackageInventory();
const expected = renderNotice();
if (process.argv.includes('--check')) {
    if (!existsSync(outputPath) || readFileSync(outputPath, 'utf8') !== expected) {
        console.error('Frontend third-party notices are missing or do not match the active public inventory.');
        process.exit(1);
    }
    console.log('Frontend notices cover the bundle, active legacy assets, public GPL source, and runtime CDNs.');
} else {
    writeFileSync(outputPath, expected, 'utf8');
    console.log('Generated public/THIRD_PARTY_NOTICES.frontend.md.');
}
