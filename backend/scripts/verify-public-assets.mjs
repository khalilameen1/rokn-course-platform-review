import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import {
    deadPaths,
    deadReferenceTokens,
    dynamicExternalExceptions,
    externalStaticAssets,
    forbiddenAssetHashes,
    forbiddenAssetName,
    thirdPartyFamilies,
} from './frontend-asset-policy.mjs';
import { hashPublicAsset } from './public-asset-hash.mjs';

export const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const inventoryPath = resolve(repositoryRoot, 'resources/legal/frontend/public-asset-inventory.json');

function toRepositoryPath(path) {
    return relative(repositoryRoot, path).split(sep).join('/');
}

function walk(path) {
    if (!existsSync(path)) return [];
    return readdirSync(path, { withFileTypes: true }).flatMap(entry => {
        const child = resolve(path, entry.name);
        return entry.isDirectory() ? walk(child) : [child];
    });
}

export function sha256(path) {
    return hashPublicAsset(path);
}

export function validateFileInventory(actualEntries, inventoryEntries) {
    const errors = [];
    const actual = new Map(actualEntries.map(entry => [entry.path, entry]));
    const declared = new Map(inventoryEntries.map(entry => [entry.path, entry]));

    for (const path of actual.keys()) {
        if (!declared.has(path)) errors.push(`Unclassified public asset: ${path}`);
    }
    for (const path of declared.keys()) {
        if (!actual.has(path)) errors.push(`Inventory asset is missing: ${path}`);
    }
    for (const [path, entry] of actual) {
        const expected = declared.get(path);
        if (!expected) continue;
        if (entry.sha256 !== expected.sha256) errors.push(`Public asset hash changed without review: ${path}`);
        if (forbiddenAssetName.test(path)) errors.push(`Forbidden or unproven asset name: ${path}`);
        if (forbiddenAssetHashes.has(entry.sha256)) errors.push(`Restricted or unproven font bytes returned as: ${path}`);
    }
    return errors;
}

export function validateExternalTag(tag, path = '<memory>', allowedStaticAssets = externalStaticAssets) {
    const url = tag.match(/\b(?:src|href)=["'](https:[^"']+)["']/i)?.[1];
    if (!url) return [];
    if (dynamicExternalExceptions.some(exception => url.startsWith(exception.prefix))) return [];
    const allowed = allowedStaticAssets.find(asset => asset.url === url);
    if (!allowed) return [`Unpinned or unclassified static external asset in ${path}: ${url}`];
    const integrity = tag.match(/\bintegrity=["']([^"']+)["']/i)?.[1];
    if (integrity !== allowed.integrity) return [`Missing or incorrect SRI for ${url} in ${path}`];
    if (!/\bcrossorigin=["']anonymous["']/i.test(tag)) return [`Missing crossorigin=anonymous for ${url} in ${path}`];
    return [];
}

function verifySufeeSource(errors) {
    const publicSource = resolve(repositoryRoot, 'public/legal/source/sufee-1.0.0-rokn');
    const sourceRoot = resolve(repositoryRoot, 'resources/vendor/sufee');
    const manifestPath = resolve(publicSource, 'SOURCE_MANIFEST.json');
    if (!existsSync(manifestPath)) {
        errors.push('Sufee public corresponding-source manifest is missing.');
        return;
    }
    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
    const compiled = resolve(repositoryRoot, manifest.compiledArtifact ?? '');
    if (!existsSync(compiled) || manifest.compiledSha256 !== sha256(compiled)) {
        errors.push('Sufee compiled CSS is not bound to its public corresponding-source manifest.');
    }
    for (const [file, expectedHash] of Object.entries(manifest.correspondingSource ?? {})) {
        const preferredSource = resolve(sourceRoot, file);
        const deliveredSource = resolve(publicSource, file);
        if (!existsSync(preferredSource) || !existsSync(deliveredSource)) {
            errors.push(`Sufee corresponding source is incomplete: ${file}`);
            continue;
        }
        if (sha256(preferredSource) !== expectedHash || sha256(deliveredSource) !== expectedHash) {
            errors.push(`Sufee corresponding source hash mismatch: ${file}`);
        }
    }
}

function verifyExternalAssets(errors) {
    const bladeFiles = walk(resolve(repositoryRoot, 'resources/views')).filter(path => path.endsWith('.blade.php'));
    const seen = new Set();
    for (const file of bladeFiles) {
        const content = readFileSync(file, 'utf8');
        const tags = content.match(/<(?:script|link)\b[^>]*\b(?:src|href)=["']https:[^>]+>/gi) ?? [];
        for (const tag of tags) {
            const url = tag.match(/\b(?:src|href)=["'](https:[^"']+)["']/i)?.[1];
            if (url) seen.add(url);
            errors.push(...validateExternalTag(tag, toRepositoryPath(file)));
        }
    }
    for (const asset of externalStaticAssets) {
        if (!seen.has(asset.url)) errors.push(`Declared static external asset is not used: ${asset.url}`);
    }
}

function verifyDeadFamilies(errors) {
    for (const path of deadPaths) {
        const absolute = resolve(repositoryRoot, path);
        if (existsSync(absolute) && (statSync(absolute).isFile() || walk(absolute).length > 0)) {
            errors.push(`Dead public/view family returned: ${path}`);
        }
    }
    const roots = ['app', 'routes', 'resources/views', 'resources/js', 'resources/sass', 'config', 'database'];
    const candidates = roots.flatMap(root => walk(resolve(repositoryRoot, root))).filter(path => /\.(?:php|blade\.php|js|scss|css|html)$/i.test(path));
    for (const file of candidates) {
        const content = readFileSync(file, 'utf8');
        for (const token of deadReferenceTokens) {
            if (content.includes(token)) errors.push(`Dead asset reference ${token} remains in ${toRepositoryPath(file)}`);
        }
    }
}

function verifyCssAssetReferences(errors) {
    const cssFiles = walk(resolve(repositoryRoot, 'public')).filter(path => path.endsWith('.css'));
    for (const file of cssFiles) {
        const content = readFileSync(file, 'utf8');
        for (const match of content.matchAll(/url\(([^)]+)\)/gi)) {
            const url = match[1].trim().replace(/^['"]|['"]$/g, '');
            if (!url || /^(?:data:|https?:|#)/i.test(url)) continue;
            const pathOnly = url.split(/[?#]/, 1)[0];
            const target = pathOnly.startsWith('/')
                ? resolve(repositoryRoot, 'public', pathOnly.slice(1))
                : resolve(dirname(file), pathOnly);
            if (!existsSync(target)) {
                errors.push(`Missing local CSS dependency ${url} referenced by ${toRepositoryPath(file)}`);
            }
        }
    }
}

export function validateRepository() {
    const errors = [];
    if (!existsSync(inventoryPath)) return [`Missing fail-closed inventory: ${toRepositoryPath(inventoryPath)}`];
    const inventory = JSON.parse(readFileSync(inventoryPath, 'utf8'));
    const publicFiles = walk(resolve(repositoryRoot, 'public')).map(path => ({ path: toRepositoryPath(path), sha256: sha256(path) }));
    errors.push(...validateFileInventory(publicFiles, inventory.assets ?? []));

    const families = new Map(thirdPartyFamilies.map(family => [family.id, family]));
    for (const entry of inventory.assets ?? []) {
        if (!['first_party', 'generated', 'deployment', 'third_party'].includes(entry.classification)) {
            errors.push(`Invalid classification for ${entry.path}`);
        }
        if (entry.classification === 'third_party') {
            const family = families.get(entry.family);
            if (!family || !family.artifacts.includes(entry.path)) errors.push(`Invalid third-party family mapping for ${entry.path}`);
        }
    }
    for (const family of thirdPartyFamilies) {
        if (!family.version || !family.source || !family.license || family.legal.length === 0) {
            errors.push(`Incomplete legal metadata for family ${family.id}`);
        }
        for (const artifact of family.artifacts) {
            const entry = (inventory.assets ?? []).find(item => item.path === artifact);
            if (!entry || entry.family !== family.id) errors.push(`Third-party artifact is not classified: ${artifact}`);
        }
        for (const [, legalPath] of family.legal) {
            if (!existsSync(resolve(repositoryRoot, legalPath))) errors.push(`Legal source is missing: ${legalPath}`);
        }
    }

    verifySufeeSource(errors);
    verifyExternalAssets(errors);
    verifyDeadFamilies(errors);
    verifyCssAssetReferences(errors);
    return [...new Set(errors)];
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    const errors = validateRepository();
    if (errors.length) {
        console.error(errors.map(error => `- ${error}`).join('\n'));
        process.exit(1);
    }
    console.log('Public asset inventory, license families, restricted-font denylist, CDN SRI, dead references, and Sufee source delivery are valid.');
}
