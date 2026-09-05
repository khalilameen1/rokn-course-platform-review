import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const publicRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../public');
const scripts = ['request.js', 'course-studio-core.js', 'course-studio-summary.js'];
const fragments = version => ['course', 'instructor', 'readiness']
    .map(region => `<article data-studio-summary="${region}">${region} ${version}</article>`).join('');
let version = 3;
let fail = false;
let delayed = false;
let releaseRead;
const fixture = `<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8">
<div id="courseStudio" data-course-id="3" data-summary-url="/summary" data-authoring-version="3">
 <div id="courseStudioSummaryStatus" hidden>المعاينة لم تتحدث <button data-studio-summary-retry>تحديث المعاينة</button></div>
 ${fragments(3)}<input id="unsaved" value="عمل لم يُحفظ"><button id="save">حفظ</button><div id="courseStudioToast"></div>
</div>${scripts.map(file => `<script src="/${file}"></script>`).join('')}
<script>
const core = window.RoknCourseStudio.start();
document.querySelector('#save').addEventListener('click', () => core.mutate(async () => {
 const result = await core.request('/save', {method: 'POST'});
 core.syncVersion(core.requireMutation(result, core.authoringVersion));
}));
</script></html>`;
const server = createServer(async (request, response) => {
    if (request.url === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        return response.end(fixture);
    }
    if (scripts.includes(request.url.slice(1))) {
        response.setHeader('Content-Type', 'text/javascript');
        return response.end(await readFile(resolve(publicRoot, 'admin/assets/js', request.url.slice(1))));
    }
    response.setHeader('Content-Type', 'application/json');
    if (request.url === '/save') return response.end(JSON.stringify({success: true, authoring_version: ++version}));
    if (request.url === '/summary') {
        const readVersion = version;
        if (delayed) {
            delayed = false;
            await new Promise(done => { releaseRead = done; });
        }
        response.statusCode = fail ? 503 : 200;
        return response.end(JSON.stringify(fail ? {message: 'unavailable'} : {
            course_id: 3, authoring_version: readVersion, html: fragments(readVersion),
        }));
    }
    response.statusCode = 404;
    response.end('{}');
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
let browser;
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const page = await browser.newPage();
    await page.goto(`http://127.0.0.1:${server.address().port}`);
    await page.locator('#unsaved').fill('مقطع آخر أكتبه الآن');
    await page.locator('#save').click();
    await page.waitForFunction(() => document.querySelector('[data-studio-summary="readiness"]').textContent === 'readiness 4');
    assert.equal(await page.locator('#unsaved').inputValue(), 'مقطع آخر أكتبه الآن');

    fail = true;
    await page.locator('#save').click();
    await page.locator('#courseStudioSummaryStatus').waitFor({state: 'visible'});
    assert.equal(await page.locator('#courseStudio').getAttribute('data-authoring-version'), '5');
    assert.equal(await page.locator('#unsaved').inputValue(), 'مقطع آخر أكتبه الآن');
    assert.equal(await page.locator('#save').isEnabled(), true);
    fail = false;
    await page.locator('[data-studio-summary-retry]').click();
    await page.waitForFunction(() => document.querySelector('[data-studio-summary="readiness"]').textContent === 'readiness 5');

    delayed = true;
    await page.locator('#save').click();
    await page.waitForFunction(() => document.querySelector('#courseStudio').dataset.authoringVersion === '6');
    const readDeadline = Date.now() + 3000;
    while (!releaseRead && Date.now() < readDeadline) await new Promise(done => setTimeout(done, 10));
    assert.ok(releaseRead, 'The saved event must request an updated summary');
    await page.locator('#save').click();
    await page.waitForFunction(() => document.querySelector('[data-studio-summary="readiness"]').textContent === 'readiness 7');
    releaseRead();
    assert.equal(await page.locator('[data-studio-summary="course"]').textContent(), 'course 7');
    assert.equal(await page.locator('#unsaved').inputValue(), 'مقطع آخر أكتبه الآن');
    console.log('PASS saved course/readiness refresh, failed read/retry, stale response, unsaved editor preservation');
} finally {
    releaseRead?.();
    await browser?.close();
    await new Promise(done => server.close(done));
}
