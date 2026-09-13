'use strict';

// Deterministic publication exports of the existing identity, not AI generation.
// Requires Node.js and Sharp. Does not edit the original icon or mobile config.
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const sharp = require('sharp');

const outputDir = __dirname;
const mobileRoot = path.resolve(outputDir, '../..');
const iconPath = path.join(mobileRoot, 'src/assets/images/brand/rokn-app-icon-1024.png');
const fontPath = path.join(mobileRoot, 'src/assets/fonts/Cairo/Cairo-Bold.ttf');
const sourceSha256 = '7cbbeb281e9c06ab3409e406513d380a7581e8fbfd7cc9e63d28d387f980f6ca';
const fontSha256 = 'a0e58d71b85b15902ea87914d8e31a6d22da48ac2db70213dcfd1a7dad3f198a';
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

async function main() {
  const source = await fs.readFile(iconPath);
  if (hash(source) !== sourceSha256) {
    throw new Error('The approved icon changed. Review the new source before exporting.');
  }
  if (hash(await fs.readFile(fontPath)) !== fontSha256) {
    throw new Error('The bundled Cairo font changed. Review it before exporting.');
  }
  const sourceInfo = await sharp(source).metadata();
  if (sourceInfo.width !== 1024 || sourceInfo.height !== 1024) {
    throw new Error('The approved icon must be 1024 by 1024 pixels.');
  }

  const icon = await sharp(source)
    .resize(512, 512, { kernel: 'lanczos3', fit: 'contain' })
    .flatten({ background: '#070A10' })
    .removeAlpha()
    .png({ compressionLevel: 9, palette: false })
    .toBuffer();

  const template = await fs.readFile(path.join(outputDir, 'feature-graphic.svg'), 'utf8');
  // Pango uses the bundled Cairo font for correct Arabic shaping on every host.
  // Insert the rendered text only in the in-memory export; the SVG stays editable.
  const textMatch = template.match(/<text id="tagline"[^>]*>([^<]+)<\/text>/);
  if (!textMatch || textMatch[1] !== 'سكرول واتعلم') {
    throw new Error('The feature graphic must retain the approved slogan verbatim.');
  }
  const slogan = await sharp({
    text: {
      text: `<span foreground="#F7F9FC">${textMatch[1]}</span>`,
      font: 'Cairo Bold 54',
      fontfile: fontPath,
      rgba: true,
      dpi: 72,
    },
  }).png().toBuffer({ resolveWithObject: true });
  if (slogan.info.width > 420 || slogan.info.height > 100) {
    throw new Error('The slogan does not fit its safe area. Inspect the font rendering.');
  }
  const textX = 267.5 - slogan.info.width / 2;
  const textY = 250 - slogan.info.height / 2;
  const renderSvg = template
    .replace('../../src/assets/images/brand/rokn-app-icon-1024.png', `data:image/png;base64,${source.toString('base64')}`)
    .replace(/<defs>[\s\S]*?<\/defs>/, '')
    .replace(textMatch[0], `<image x="${textX}" y="${textY}" width="${slogan.info.width}" height="${slogan.info.height}" href="data:image/png;base64,${slogan.data.toString('base64')}"/>`);
  const feature = await sharp(Buffer.from(renderSvg))
    .flatten({ background: '#070A10' })
    .removeAlpha()
    .png({ compressionLevel: 9, palette: false })
    .toBuffer();

  const outputs = [];
  for (const [name, data, width, height, limit] of [
    ['play-icon-512.png', icon, 512, 512, 1024 * 1024],
    ['play-feature-graphic-1024x500.png', feature, 1024, 500, 15 * 1024 * 1024],
  ]) {
    const info = await sharp(data).metadata();
    if (info.width !== width || info.height !== height || info.hasAlpha || data.length > limit) {
      throw new Error(`Invalid publication dimensions, alpha or size: ${name}`);
    }
    await fs.writeFile(path.join(outputDir, name), data);
    outputs.push({ file: name, width, height, bytes: data.length, alpha: false, sha256: hash(data) });
  }
  if (hash(await fs.readFile(iconPath)) !== sourceSha256) {
    throw new Error('The source changed during export. Do not upload these outputs.');
  }
  const manifest = {
    method: 'deterministic-sharp-svg-export-no-ai-generated-imagery',
    source: { file: 'mobile/src/assets/images/brand/rokn-app-icon-1024.png', sha256: sourceSha256 },
    font: { file: 'mobile/src/assets/fonts/Cairo/Cairo-Bold.ttf', sha256: fontSha256 },
    slogan: textMatch[1],
    sharp: sharp.versions.sharp,
    outputs,
  };
  await fs.writeFile(path.join(outputDir, 'asset-manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);
  process.stdout.write(`${JSON.stringify(manifest, null, 2)}\n`);
}

main().catch(error => {
  process.stderr.write(`${error.message}\n`);
  process.exitCode = 1;
});
