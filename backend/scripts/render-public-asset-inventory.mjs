import { readdirSync, writeFileSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { thirdPartyFamilies } from './frontend-asset-policy.mjs';
import { hashPublicAsset } from './public-asset-hash.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const inventoryPath = resolve(root, 'resources/legal/frontend/public-asset-inventory.json');
const thirdParty = new Map(thirdPartyFamilies.flatMap(family => family.artifacts.map(path => [path, family.id])));
const generated = new Set(['public/THIRD_PARTY_NOTICES.frontend.md', 'public/mix-manifest.json']);
const deployment = new Set([
    'public/.htaccess', 'public/index.php', 'public/public/.gitignore',
    'public/robots.txt', 'public/web.config',
]);

function walk(path) {
    return readdirSync(path, { withFileTypes: true }).flatMap(entry => {
        const child = resolve(path, entry.name);
        return entry.isDirectory() ? walk(child) : [child];
    });
}

function repoPath(path) {
    return relative(root, path).split(sep).join('/');
}

function classification(path) {
    if (thirdParty.has(path)) return { classification: 'third_party', family: thirdParty.get(path) };
    if (generated.has(path)) return { classification: 'generated' };
    if (deployment.has(path)) return { classification: 'deployment' };
    if (/^public\/(?:admin\/assets\/css\/|admin\/assets\/js\/(?:main|admin-identity-theme|request|reward-rule-form|users-index|course-studio(?:-[a-z-]+)?)\.js$|assets\/img\/badges\/|css\/landing\.css$|favicon\.ico$|images\/)/.test(path)) {
        return { classification: 'first_party' };
    }
    throw new Error(`No explicit initialization rule for public asset: ${path}`);
}

const assets = walk(resolve(root, 'public')).map(path => {
    const relativePath = repoPath(path);
    return {
        path: relativePath,
        sha256: hashPublicAsset(path),
        ...classification(relativePath),
    };
}).sort((a, b) => a.path.localeCompare(b.path));

const inventory = `${JSON.stringify({ schemaVersion: 1, policy: 'Every distributed public file is explicitly classified and SHA-256 pinned; additions fail CI until reviewed.', assets }, null, 2)}\n`;

if (process.argv.includes('--write')) {
    writeFileSync(inventoryPath, inventory, 'utf8');
    console.log(`Updated ${relative(root, inventoryPath).split(sep).join('/')}.`);
} else {
    process.stdout.write(inventory);
}
