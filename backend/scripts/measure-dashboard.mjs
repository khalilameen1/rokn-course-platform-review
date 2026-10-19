// Google Lighthouse's maintained Node API, with the same authenticated Chrome
// session as Playwright. No browser credentials or production records are used.
import { spawn, execFileSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import lighthouse from 'lighthouse';
import desktopConfig from 'lighthouse/core/config/desktop-config.js';
import { launch } from 'chrome-launcher';
import { chromium } from '@playwright/test';
import { phpArguments, signIn } from '../tests/Browser/support/authentication.mjs';

const origin = 'http://127.0.0.1:8793';
const output = '.lighthouseci';
execFileSync('php', [...phpArguments(), 'scripts/dashboard-browser.php', '--prepare'], { stdio: 'inherit' });
const server = spawn('php', [...phpArguments(), '-S', '127.0.0.1:8793', '-t', 'public', 'scripts/dashboard-browser.php'], { stdio: ['ignore', 'ignore', 'pipe'] });
let serverLog = '';
server.stderr.on('data', value => { serverLog = (serverLog + value.toString()).slice(-12000); });
let chrome;
let browser;
try {
    const deadline = Date.now() + 60000;
    for (;;) {
        if (server.exitCode !== null) throw new Error(`Local fixture server failed: ${serverLog}`);
        try {
            const ready = await fetch(`${origin}/login`, { signal: AbortSignal.timeout(5000) });
            if (ready.ok) break;
        } catch { /* Only the private fixture is allowed to start here. */ }
        if (Date.now() > deadline) throw new Error(`Fixture server did not start: ${serverLog}`);
        await new Promise(resolve => setTimeout(resolve, 250));
    }
    chrome = await launch({
        chromePath: process.env.CI ? chromium.executablePath() : undefined,
        chromeFlags: ['--headless=new', '--disable-dev-shm-usage', ...(process.env.CI ? ['--no-sandbox'] : [])],
    });
    browser = await chromium.connectOverCDP(`http://127.0.0.1:${chrome.port}`);
    const context = browser.contexts()[0];
    const page = await context.newPage();
    await signIn(page, origin);
    await page.close();
    await mkdir(output, { recursive: true });
    const results = [];
    for (const mode of ['mobile', 'desktop']) {
        for (const path of ['/operations', '/operations/students', '/operations/orders', '/operations/paths/create']) {
            console.log(`Measuring authenticated ${mode}: ${path}`);
            const url = `${origin}${path}`;
            const result = await lighthouse(url, {
                port: chrome.port, logLevel: 'error', disableStorageReset: true,
                onlyCategories: ['performance', 'accessibility', 'best-practices'], output: ['html', 'json'],
            }, mode === 'desktop' ? desktopConfig : undefined);
            if (!result || result.lhr.runtimeError) throw new Error(`Lighthouse failed: ${JSON.stringify(result?.lhr.runtimeError)}`);
            if (new URL(result.lhr.finalDisplayedUrl).pathname !== path) throw new Error(`Measurement lost dashboard access: ${result.lhr.finalDisplayedUrl}`);
            const stem = `${mode}-${path.replaceAll('/', '-').replace(/^-/, '')}`;
            await writeFile(`${output}/${stem}.html`, result.report[0]);
            await writeFile(`${output}/${stem}.json`, result.report[1]);
            const row = {
                mode, path,
                scores: Object.fromEntries(Object.entries(result.lhr.categories).map(([key, value]) => [key, value.score])),
                metrics: Object.fromEntries(['first-contentful-paint', 'largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift']
                    .map(key => [key, result.lhr.audits[key]?.numericValue])),
                warnings: result.lhr.runWarnings,
            };
            results.push(row);
            console.log(JSON.stringify(row));
        }
    }
    await writeFile(`${output}/summary.json`, JSON.stringify({
        scope: 'Disposable local fixture, not production load or production latency',
        measuredAt: new Date().toISOString(), lighthouseVersion: '13.5.0', results,
    }, null, 2));
} finally {
    if (browser) await browser.close();
    if (chrome) await chrome.kill();
    server.kill();
}
