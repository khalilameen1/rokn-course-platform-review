import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const playwright = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const {chromium} = playwright.default || playwright;
const backendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const blade = await readFile(resolve(
    backendRoot,
    'resources/views/admin/course-sections/partials/bunny-direct-upload.blade.php',
), 'utf8');
const uploadScript = blade
    .replace(/^<script>\s*/, '')
    .replace(/\s*<\/script>\s*$/, '')
    .replace('@json((string) auth()->id())', JSON.stringify('admin-1'))
    .replace("@json($errors->has('bunny_video_claim_terminal'))", 'false');

const fixture = `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"></head><body>
<form id="sectionForm" data-course-id="3" data-section-id="" data-bunny-upload-init="/init" data-bunny-upload-renew="/renew">
 <input name="_token" value="csrf"><input name="authoring_version" value="1">
 <input id="section_type" value="lesson"><input id="title_ar" value="المقطع الثاني">
 <input id="bunny_video_claim" name="bunny_video_claim">
 <input id="bunny_video" type="file" data-video-required="true" required>
 <button id="save" type="submit">حفظ</button>
</form>
<div id="bunny_upload_progress" class="is-hidden"><div class="progress-bar" aria-valuenow="0"></div></div>
<div id="bunny_upload_status"></div>
<button id="bunny_upload_cancel" type="button">إيقاف</button>
<button id="bunny_upload_retry" type="button" class="is-hidden">إعادة المحاولة</button>
<script>
window.__initCalls = [];
window.__renewCalls = 0;
window.__submittedClaim = null;
window.RoknAdminRequest = {
 request: async (url, options) => {
  if (url === '/init') {
   const body = JSON.parse(options.body);
   window.__initCalls.push(body);
   return {data: {
    upload_endpoint: 'https://video.bunnycdn.com/tusupload',
    claim: 'same-signed-claim',
    claim_expires_at: '2099-01-01T00:00:00Z',
    authorization_expires_in_seconds: 300,
    authorization_expires_at: '2099-01-01T00:00:00Z',
    headers: {AuthorizationSignature: 'signature', AuthorizationExpire: '4102444800', VideoId: 'video-1', LibraryId: 'library-1'},
   }};
  }
  if (url === '/renew') {
   window.__renewCalls += 1;
   return {data: {
    claim: 'same-signed-claim',
    claim_expires_at: '2099-01-01T00:00:00Z',
    authorization_expires_in_seconds: 300,
    authorization_expires_at: '2099-01-01T00:00:00Z',
    headers: {AuthorizationSignature: 'signature', AuthorizationExpire: '4102444800', VideoId: 'video-1', LibraryId: 'library-1'},
   }};
  }
  throw new Error('Unexpected app request ' + url);
 },
 blockMutationsUntilReload: () => {},
};
document.getElementById('sectionForm').addEventListener('submit', event => {
 const claim = document.getElementById('bunny_video_claim').value;
 if (!claim) return;
 event.preventDefault();
 window.__submittedClaim = claim;
});
</script>
<script>${uploadScript}</script>
</body></html>`;

const server = createServer((request, response) => {
    if (request.url === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(fixture);
        return;
    }
    response.statusCode = 404;
    response.end('not found');
});

await new Promise(done => server.listen(0, '127.0.0.1', done));
let browser;

const corsHeaders = {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Headers': '*',
    'Access-Control-Allow-Methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD',
    'Access-Control-Expose-Headers': 'Location,Upload-Offset,Tus-Resumable',
    'Tus-Resumable': '1.0.0',
};

const openScenario = async mode => {
    const context = await browser.newContext();
    const page = await context.newPage();
    const calls = {post: 0, head: 0, patch: 0, metadata: []};
    let releasePendingHead;
    await page.route('https://**/*', async route => {
        const method = route.request().method();
        if (method === 'OPTIONS') {
            await route.fulfill({status: 204, headers: corsHeaders});
            return;
        }
        if (method === 'POST') {
            calls.post += 1;
            calls.metadata.push(route.request().headers()['upload-metadata'] || '');
            if (mode === 'transport-retry' && calls.post === 1) {
                await route.abort('connectionfailed');
                return;
            }
            await route.fulfill({
                status: 201,
                headers: {...corsHeaders, Location: 'https://edge.bunnycdn.com/uploads/video-1'},
            });
            return;
        }
        if (method === 'HEAD') {
            calls.head += 1;
            if (mode === 'cancel-head' && calls.head === 1) {
                await new Promise(resolve => { releasePendingHead = resolve; });
                await route.fulfill({status: 200, headers: {...corsHeaders, 'Upload-Offset': '0'}}).catch(() => {});
                return;
            }
            if (['transport-retry', 'cancel-backoff'].includes(mode) && calls.head === 1) {
                await route.abort('connectionfailed');
                return;
            }
            await route.fulfill({status: 200, headers: {...corsHeaders, 'Upload-Offset': '0'}});
            return;
        }
        if (method === 'PATCH') {
            calls.patch += 1;
            if (mode === 'cancel-patch-backoff' && calls.patch === 1) {
                await route.fulfill({status: 503, headers: corsHeaders});
                return;
            }
            await route.fulfill({status: 204, headers: {...corsHeaders, 'Upload-Offset': '4'}});
            return;
        }
        await route.abort();
    });
    await page.goto(`http://127.0.0.1:${server.address().port}`);
    await page.locator('#bunny_video').setInputFiles({
        name: mode === 'transport-retry' ? 'retry.mp4' : 'cancel.mp4',
        mimeType: 'video/mp4',
        buffer: Buffer.from([1, 2, 3, 4]),
    });
    return {
        context,
        page,
        calls,
        releasePendingHead: () => releasePendingHead?.(),
    };
};

try {
    browser = await chromium.launch({channel: 'chrome', headless: true});

    const retry = await openScenario('transport-retry');
    await retry.page.locator('#save').click();
    await retry.page.waitForFunction(() => window.__submittedClaim === 'same-signed-claim');
    assert.equal(retry.calls.post, 2);
    assert.equal(retry.calls.head, 2);
    assert.equal(retry.calls.patch, 1);
    assert.ok(retry.calls.metadata.every(value => value.includes(`title ${Buffer.from('المقطع الثاني').toString('base64')}`)));
    assert.equal(await retry.page.evaluate(() => window.__initCalls.length), 1, 'transport retries must not allocate another video');
    assert.equal((await retry.page.locator('#bunny_upload_status').textContent()).includes('Failed to fetch'), false);
    await retry.context.close();

    const cancel = await openScenario('cancel-backoff');
    await cancel.page.locator('#save').click();
    await cancel.page.waitForFunction(() => document.getElementById('bunny_upload_status').textContent.includes('نحاول استئناف'));
    await cancel.page.locator('#bunny_upload_cancel').click();
    await cancel.page.waitForTimeout(900);
    assert.equal(cancel.calls.head, 1, 'cancel must stop a pending recovery delay');
    assert.equal(await cancel.page.evaluate(() => window.__submittedClaim), null, 'cancelled upload must not submit the section later');
    assert.match(await cancel.page.locator('#bunny_upload_status').textContent(), /تم إيقاف الرفع/);

    await cancel.page.locator('#bunny_upload_retry').click();
    await cancel.page.waitForFunction(() => window.__submittedClaim === 'same-signed-claim');
    assert.equal(cancel.calls.post, 1);
    assert.equal(cancel.calls.head, 2);
    assert.equal(cancel.calls.patch, 1);
    assert.equal(await cancel.page.evaluate(() => window.__initCalls.length), 1, 'manual resume must keep the original claim and idempotency key');
    await cancel.context.close();

    const pending = await openScenario('cancel-head');
    await pending.page.locator('#save').click();
    const headDeadline = Date.now() + 3000;
    while (pending.calls.head < 1 && Date.now() < headDeadline) await new Promise(done => setTimeout(done, 10));
    assert.equal(pending.calls.head, 1, 'the resumable HEAD request must start');
    await pending.page.locator('#bunny_upload_cancel').click();
    pending.releasePendingHead();
    await pending.page.waitForTimeout(100);
    assert.equal(pending.calls.patch, 0, 'cancelled HEAD must not continue into video bytes');
    assert.equal(await pending.page.evaluate(() => window.__submittedClaim), null, 'cancelled HEAD must not submit the section later');
    await pending.page.locator('#bunny_upload_retry').click();
    await pending.page.waitForFunction(() => window.__submittedClaim === 'same-signed-claim');
    assert.equal(pending.calls.post, 1);
    assert.equal(pending.calls.head, 2);
    assert.equal(pending.calls.patch, 1);
    assert.equal(await pending.page.evaluate(() => window.__initCalls.length), 1);
    await pending.context.close();

    const chunkBackoff = await openScenario('cancel-patch-backoff');
    await chunkBackoff.page.locator('#save').click();
    const patchDeadline = Date.now() + 3000;
    while (chunkBackoff.calls.patch < 1 && Date.now() < patchDeadline) await new Promise(done => setTimeout(done, 10));
    assert.equal(chunkBackoff.calls.patch, 1, 'the first video chunk must reach Bunny');
    await chunkBackoff.page.locator('#bunny_upload_cancel').click();
    await chunkBackoff.page.waitForTimeout(1100);
    assert.equal(chunkBackoff.calls.head, 1, 'cancel must stop the PATCH recovery delay before another HEAD');
    assert.equal(chunkBackoff.calls.patch, 1, 'cancelled PATCH recovery must not send another chunk');
    assert.equal(await chunkBackoff.page.evaluate(() => window.__submittedClaim), null);
    await chunkBackoff.page.locator('#bunny_upload_retry').click();
    await chunkBackoff.page.waitForFunction(() => window.__submittedClaim === 'same-signed-claim');
    assert.equal(chunkBackoff.calls.post, 1);
    assert.equal(chunkBackoff.calls.head, 2);
    assert.equal(chunkBackoff.calls.patch, 2);
    await chunkBackoff.context.close();

    console.log('PASS Bunny transport recovery, claim reuse, and cancellation during HEAD and retry backoff');
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
