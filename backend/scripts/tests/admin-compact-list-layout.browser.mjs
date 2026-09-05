import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, extname, resolve, sep} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const publicRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../public');
const projectRoot = resolve(publicRoot, '..');
const couponSource = await readFile(resolve(projectRoot, 'resources/views/admin/coupons/index.blade.php'), 'utf8');
const categorySource = await readFile(resolve(projectRoot, 'resources/views/admin/categories/index.blade.php'), 'utf8');

assert.doesNotMatch(couponSource, /col-xs-/);
assert.doesNotMatch(categorySource, /col-xs-/);
assert.doesNotMatch(categorySource, /images\/cars\/car-1\.png/);

const fixture = `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/admin/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/admin/assets/css/custom-global.css">
</head><body>
<main class="fixture-content admin-page">
  <section class="card"><div class="card-body">
    <div id="coupon-row" class="row connection-block align-items-start py-3 border-bottom">
      <div class="col-12 col-md-2 mb-2 mb-md-0 text-right"><strong>خصم الجهة التعليمية</strong></div>
      <div class="col-12 col-sm-6 col-md-2 mb-2 mb-md-0 text-right"><span id="coupon-code" class="d-block admin-value--ltr">ROKN-EDUCATION-LONG-CODE-2026</span></div>
      <div class="col-12 col-md-4 mb-2 mb-md-0 text-right"><span class="d-block">20٪ خصم</span><span class="d-block">120 استخدام</span><span class="d-block">أساسيات التصميم البصري للمبتدئين</span><span class="d-block">120 / 500</span><span class="d-block">2026-12-31</span></div>
      <div class="col-12 col-sm-6 col-md-1 mb-2 mb-md-0 text-right"><span>مفعل</span></div>
      <div id="coupon-actions" class="col-12 col-md-3 admin-actions justify-content-md-end"><a class="btn btn-sm btn-primary">تعديل</a><button class="btn btn-sm btn-danger">حذف</button></div>
    </div>
    <div id="category-row" class="row connection-block align-items-center py-3 border-bottom">
      <div class="col-12 col-sm-7 col-md-9 d-flex align-items-center text-right"><span class="admin-page__icon ml-2"><i class="fa fa-folder-open-o"></i></span><strong>التصميم والفنون البصرية</strong></div>
      <div id="category-actions" class="col-12 col-sm-5 col-md-3 admin-actions justify-content-sm-end mt-2 mt-sm-0"><a class="btn btn-sm btn-primary">تعديل</a><button class="btn btn-sm btn-danger">حذف</button></div>
    </div>
  </div></section>
</main></body></html>`;

const server = createServer(async (request, response) => {
    if (request.url === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        return response.end(fixture);
    }
    const path = resolve(publicRoot, '.' + request.url.split('?')[0]);
    if (!path.startsWith(publicRoot + sep)) { response.writeHead(403); return response.end(); }
    try {
        response.setHeader('Content-Type', extname(path) === '.css' ? 'text/css' : 'application/octet-stream');
        response.end(await readFile(path));
    } catch { response.writeHead(404); response.end(); }
});

await new Promise(ready => server.listen(0, '127.0.0.1', ready));
let browser;
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const page = await browser.newPage();
    for (const [viewport, contentWidth] of [[360, 280], [768, 688], [1024, 704], [1440, 1120]]) {
        await page.setViewportSize({width: viewport, height: 900});
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        await page.locator('.fixture-content').evaluate((element, width) => {
            element.style.width = `${width}px`;
            element.style.marginInline = 'auto';
        }, contentWidth);

        for (const selector of ['#coupon-row', '#category-row']) {
            const row = await page.locator(selector).boundingBox();
            const content = await page.locator('.fixture-content').boundingBox();
            assert.ok(row.x >= content.x - 1 && row.x + row.width <= content.x + content.width + 1, `${selector} escapes ${contentWidth}px content at ${viewport}px`);
        }
        for (const selector of ['#coupon-actions', '#category-actions']) {
            const actionBox = await page.locator(selector).boundingBox();
            const buttons = await page.locator(`${selector} .btn`).evaluateAll(elements => elements.map(element => element.getBoundingClientRect().toJSON()));
            assert.ok(buttons.every(button => button.x >= actionBox.x - 1 && button.x + button.width <= actionBox.x + actionBox.width + 1), `${selector} clips an action at ${viewport}px`);
        }
        const codeFits = await page.locator('#coupon-code').evaluate(element => element.scrollWidth <= element.clientWidth + 1);
        assert.ok(codeFits, `Coupon code overflows at ${viewport}px`);
    }
    console.log('PASS compact coupon/category lists at 280px content and dashboard breakpoints');
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
