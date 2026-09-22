'use strict';

// Size/format export only; the generated artwork is preserved as its own source.
const path = require('node:path');
const sharp = require('sharp');

async function main() {
  const output = path.join(__dirname, 'play-feature-generated-v2-1024x500.png');
  await sharp(path.join(__dirname, 'play-feature-generated-v2-source.png'))
    .resize(1024, 500, { fit: 'contain', background: '#070A10' })
    .flatten({ background: '#070A10' })
    .removeAlpha()
    .png({ compressionLevel: 9 })
    .toFile(output);
  const info = await sharp(output).metadata();
  if (info.width !== 1024 || info.height !== 500 || info.hasAlpha) {
    throw new Error('Invalid Google Play feature graphic export');
  }
  process.stdout.write(JSON.stringify({ output, width: info.width, height: info.height, alpha: info.hasAlpha }) + '\n');
}

main().catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
