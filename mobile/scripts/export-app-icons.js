'use strict';

// Native identity export, not artwork generation. Keep the existing launcher
// mark byte-for-byte; Sharp (also used by Expo's image tooling) only resizes and
// composites that mark onto Rokn's existing primary colour. No new dependency.
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const sharp = require('sharp');

const root = path.resolve(__dirname, '..');
const repository = path.dirname(root);
const original = 'android/app/src/main/res/mipmap-xxxhdpi/ic_launcher_foreground.png';
const originalSha256 = '122925077327dfa6b5678b2d008339ca457e0c6cf2f6a2d8711664df2c3ee3b3';
const foreground = 'src/assets/images/brand/rokn-app-icon-foreground.png';
const hash = buffer => crypto.createHash('sha256').update(buffer).digest('hex');

async function main() {
  if (sharp.versions.sharp !== '0.35.4') {
    throw new Error('Use the reviewed Sharp 0.35.4 export runtime.');
  }
  const source = await fs.readFile(path.join(root, original));
  if (hash(source) !== originalSha256) {
    throw new Error('The original Rokn launcher mark changed; review it before export.');
  }
  const tokens = await fs.readFile(path.join(root, 'src/constants/brandTokens.ts'), 'utf8');
  const background = tokens.match(/\bprimary:\s*'(#[0-9A-Fa-f]{6})'/)?.[1];
  if (!background) throw new Error('Rokn primary colour is missing.');
  const app = JSON.parse(await fs.readFile(path.join(root, 'app.json'), 'utf8')).expo;
  if (app.android.adaptiveIcon.backgroundColor !== background ||
      app.android.adaptiveIcon.foregroundImage !== `./${foreground}` ||
      app.android.adaptiveIcon.monochromeImage !== `./${foreground}`) {
    throw new Error('Expo launcher configuration does not match the native identity.');
  }
  const nativeColours = await fs.readFile(path.join(root, 'android/app/src/main/res/values/colors.xml'), 'utf8');
  if (!nativeColours.includes(`<color name="launcher_background">${background}</color>`)) {
    throw new Error('Native Android launcher background differs from Rokn primary.');
  }
  const outputs = [];
  async function save(file, data, size, opaque = true) {
    const info = await sharp(data).metadata();
    if (info.width !== size || info.height !== size || (opaque && info.hasAlpha)) {
      throw new Error(`Invalid icon dimensions or alpha: ${file}`);
    }
    const target = path.resolve(repository, file);
    if (!target.startsWith(repository + path.sep)) throw new Error('Icon destination escaped the repository.');
    await fs.writeFile(target, data);
    outputs.push({ file, width: size, height: size, alpha: Boolean(info.hasAlpha), bytes: data.length, sha256: hash(data) });
  }
  const sourceInfo = await sharp(source).metadata();
  if (sourceInfo.width !== 432 || sourceInfo.height !== 432 || !sourceInfo.hasAlpha) {
    throw new Error('Original launcher mark must remain a transparent 432px PNG.');
  }
  await save(`mobile/${foreground}`, source, 432, false);
  const master = await sharp(source).resize(1024, 1024, { fit: 'contain', kernel: 'lanczos3' })
    .flatten({ background }).removeAlpha().png({ compressionLevel: 9 }).toBuffer();
  await save(`mobile/${app.icon.replace(/^\.\//, '')}`, master, 1024);
  const solid = await sharp({ create: { width: 1024, height: 1024, channels: 3, background } })
    .png({ compressionLevel: 9 }).toBuffer();
  await save('mobile/src/assets/images/brand/rokn-app-icon-background.png', solid, 1024);
  const play = await sharp(master).resize(512, 512, { kernel: 'lanczos3' })
    .removeAlpha().png({ compressionLevel: 9 }).toBuffer();
  if (play.length > 1024 * 1024) throw new Error('Google Play icon exceeds 1 MiB.');
  await save('mobile/store/assets/play-icon-512.png', play, 512);

  const catalogDirectory = 'mobile/ios/Rokn/Images.xcassets/AppIcon.appiconset';
  const catalog = JSON.parse(await fs.readFile(path.join(repository, catalogDirectory, 'Contents.json'), 'utf8'));
  const sizes = new Map();
  for (const entry of catalog.images) {
    const dimensions = entry.size.split('x').map(Number);
    const scale = Number(entry.scale.replace(/x$/, ''));
    const size = dimensions[0] * scale;
    if (dimensions.length !== 2 || dimensions[0] !== dimensions[1] || !Number.isInteger(size) ||
        size < 1 || !entry.filename || path.basename(entry.filename) !== entry.filename) {
      throw new Error('Invalid iOS icon catalog entry.');
    }
    if (sizes.has(entry.filename) && sizes.get(entry.filename) !== size) {
      throw new Error('iOS icon filename has inconsistent dimensions.');
    }
    sizes.set(entry.filename, size);
  }
  for (const [filename, size] of sizes) {
    const data = size === 1024 ? master : await sharp(master).resize(size, size, { kernel: 'lanczos3' })
      .removeAlpha().png({ compressionLevel: 9 }).toBuffer();
    await save(`${catalogDirectory}/${filename}`, data, size);
  }
  if (hash(await fs.readFile(path.join(root, original))) !== originalSha256) {
    throw new Error('Original launcher mark was changed during export.');
  }
  const manifest = {
    method: 'existing-native-launcher-mark-export-no-ai-redrawing',
    source: { file: `mobile/${original}`, sha256: originalSha256 },
    background,
    sharp: sharp.versions.sharp,
    outputs,
  };
  await fs.writeFile(path.join(root, 'store/assets/app-icon-manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);
  process.stdout.write(`${JSON.stringify(manifest, null, 2)}\n`);
}

main().catch(error => { process.stderr.write(`${error.message}\n`); process.exitCode = 1; });
