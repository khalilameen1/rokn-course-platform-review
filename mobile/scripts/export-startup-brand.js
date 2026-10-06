'use strict';

// Mechanical native export of the approved composition, not new artwork.
// Reuses the reviewed Sharp/Pango renderer, original wordmark and shipped Cairo
// fonts. Android must fit its system mask; iOS retains the 205dp composition.
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const sharp = require('sharp');
const {getAndroidSplashConfig} = require('@expo/prebuild-config/build/plugins/unversioned/expo-splash-screen/getAndroidSplashConfig');
const {setSplashImageDrawablesForThemeAsync} = require('@expo/prebuild-config/build/plugins/unversioned/expo-splash-screen/withAndroidSplashImages');
const root = path.resolve(__dirname, '..');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const sources = [
  ['src/assets/images/logo.png', 'a257b8279434edef0813f5d6ed92f20698a5cc81bc03ba14855ea0603494ea8c'],
  ['src/assets/fonts/Cairo/Cairo-Medium.ttf', '64c7cab8e2cf003c3fc4edca1820e7e1b0ee667373cf8500060bca97262a2b65'],
  ['src/assets/fonts/Cairo/Cairo-ExtraBold.ttf', '67526b4f18bb9bb568e81135df9eec813a15cc1252ae784ea505236e75a5b56e'],
];
const scale = 4;
const logical = {width: 205, height: 118, wordmarkHeight: 68, gap: 23, lineHeight: 27};
const transparent = {r: 0, g: 0, b: 0, alpha: 0};
const canvas = (width, height) => sharp({create: {width, height, channels: 4, background: transparent}});

async function main() {
  if (sharp.versions.sharp !== '0.35.4') throw new Error('Use the reviewed Sharp 0.35.4 export runtime.');
  for (const [file, sha256] of sources) {
    if (hash(await fs.readFile(path.join(root, file))) !== sha256) {
      throw new Error(`Approved source changed; review it before export: ${file}`);
    }
  }
  // Register both actual static font faces in Pango before mixed-weight shaping.
  for (const [file, font, text] of [
    [sources[1][0], 'Cairo Medium 18', 'كورسات'],
    [sources[2][0], 'Cairo ExtraBold 18', 'هتكملها'],
  ]) {
    await sharp({text: {text, font, fontfile: path.join(root, file), dpi: 72 * scale, rgba: true}}).png().toBuffer();
  }
  const {data: slogan, info: sloganInfo} = await sharp({text: {
    text: '<span foreground="#B8C3D4">كورسات <span font="Cairo ExtraBold 18" foreground="#FFFFFF">هتكملها</span></span>',
    font: 'Cairo Medium 18', fontfile: path.join(root, sources[1][0]),
    dpi: 72 * scale, rgba: true,
  }}).png().toBuffer({resolveWithObject: true});
  if (sloganInfo.width > logical.width * scale || sloganInfo.height > logical.lineHeight * scale) {
    throw new Error('The approved slogan does not fit its original frame.');
  }
  const wordmark = await sharp(path.join(root, sources[0][0]))
    .resize(logical.width * scale, logical.wordmarkHeight * scale, {fit: 'contain', background: transparent})
    .png().toBuffer();
  const brand = await canvas(logical.width * scale, logical.height * scale).composite([
    {input: wordmark, left: 0, top: 0},
    {input: slogan, left: Math.round((logical.width * scale - sloganInfo.width) / 2),
      top: (logical.wordmarkHeight + logical.gap) * scale + Math.round((logical.lineHeight * scale - sloganInfo.height) / 2)},
  ]).png({compressionLevel: 9}).toBuffer();
  const androidMark = await sharp(brand).resize(160 * scale).png().toBuffer();
  const androidInfo = await sharp(androidMark).metadata();
  const android = await canvas(192 * scale, 192 * scale).composite([
    {input: androidMark, left: 16 * scale, top: Math.round((192 * scale - androidInfo.height) / 2)},
  ]).png({compressionLevel: 9}).toBuffer();
  const {data: pixels, info} = await sharp(android).raw().toBuffer({resolveWithObject: true});
  let maxRadius = 0;
  for (let y = 0; y < info.height; y++) {
    for (let x = 0; x < info.width; x++) {
      if (pixels[(y * info.width + x) * info.channels + 3] === 0) continue;
      maxRadius = Math.max(maxRadius, Math.hypot(x + 0.5 - info.width / 2, y + 0.5 - info.height / 2));
    }
  }
  if (maxRadius > 96 * scale) throw new Error('Android system mask would clip the approved brand.');
  const ios = await sharp(brand).resize(logical.width * 3, logical.height * 3).png({compressionLevel: 9}).toBuffer();
  const outputs = [];
  for (const [file, data] of [
    ['src/assets/images/brand/rokn-startup-brand.png', brand],
    ['src/assets/images/brand/rokn-startup-brand-android.png', android],
    ['ios/Rokn/Images.xcassets/RoknStartupBrand.imageset/rokn-startup-brand@3x.png', ios],
  ]) {
    const target = path.resolve(root, file);
    if (!target.startsWith(root + path.sep)) throw new Error('Startup destination escaped the mobile project.');
    await fs.mkdir(path.dirname(target), {recursive: true});
    await fs.writeFile(target, data);
    const {width, height} = await sharp(data).metadata();
    outputs.push({file, width, height, bytes: data.length, sha256: hash(data)});
  }
  // Actual reuse of Expo's installed image exporter, not a reimplementation of
  // Android icon bounds. It pads imageWidth inside the system's 288dp canvas
  // for every density. A fixed-size LayerDrawable child would bypass scaling.
  const config = JSON.parse(await fs.readFile(path.join(root, 'app.json'), 'utf8')).expo;
  const plugin = config.plugins.find(item => Array.isArray(item) && item[0] === 'expo-splash-screen')[1];
  const props = {...plugin, ...plugin.android};
  if (props.imageWidth !== 192 || props.image !== './src/assets/images/brand/rokn-startup-brand-android.png' || props.drawable) {
    throw new Error('Android splash configuration differs from the reviewed native composition.');
  }
  const nativeProps = {...props, image: path.resolve(root, props.image)};
  await setSplashImageDrawablesForThemeAsync(getAndroidSplashConfig(config, nativeProps), 'light', root, props.imageWidth);
  for (const [density, multiplier] of [['mdpi', 1], ['hdpi', 1.5], ['xhdpi', 2], ['xxhdpi', 3], ['xxxhdpi', 4]]) {
    const file = `android/app/src/main/res/drawable-${density}/splashscreen_logo.png`;
    const data = await fs.readFile(path.join(root, file));
    const {width, height} = await sharp(data).metadata();
    if (width !== 288 * multiplier || height !== width) throw new Error('Expo native canvas dimensions differ from its system contract.');
    outputs.push({file, width, height, bytes: data.length, sha256: hash(data)});
  }
  const manifest = {
    method: 'original-wordmark-real-Cairo-fonts-native-single-splash',
    sharp: sharp.versions.sharp,
    slogan: 'كورسات هتكملها', background: '#0B1628',
    logical, android: {
      frameDp: 192, compositionWidthDp: 160, maskRadiusDp: 96, maxAlphaRadiusDp: maxRadius / scale,
      nativeCanvasDp: 288, imageWidthDp: props.imageWidth,
      generator: '@expo/prebuild-config/setSplashImageDrawablesForThemeAsync',
      generatorVersion: require('@expo/prebuild-config/package.json').version,
    },
    sources: sources.map(([file, sha256]) => ({file, sha256})), outputs,
  };
  await fs.writeFile(path.join(root, 'store/assets/startup-brand-manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
  process.stdout.write(JSON.stringify(manifest, null, 2) + '\n');
}
main().catch(error => {process.stderr.write(error.message + '\n'); process.exitCode = 1;});
