import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, extname, resolve, sep} from 'node:path';
import {fileURLToPath} from 'node:url';

// Run against a real browser, not a source-text assertion. An installed Playwright
// module can be supplied without changing the application's runtime dependencies.
const {chromium} = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const publicRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../public');
const fixture = `<!doctype html><html dir="rtl" lang="ar" class="admin-shell-root">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/admin/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/admin/assets/scss/style.css">
<link rel="stylesheet" href="/admin/assets/css/custom-global.css">
<link rel="stylesheet" href="/admin/assets/css/admin-shell.css">
<body class="admin-shell">
<aside id="left-panel" class="left-panel modern-sidebar">
 <nav class="sidebar-nav"><div class="modern-brand">
  <a class="brand-logo" href="#main-content"><img class="brand-wordmark" src="/images/rokn-wordmark.png" alt="Rokn"><img class="brand-symbol" src="/images/rokn-app-icon.png" alt=""></a>
  <button id="adminSidebarClose" class="admin-sidebar-close">إغلاق</button>
 </div><div class="main-menu"><ul class="modern-nav">
  <li class="nav-item active"><a class="nav-link" href="#main-content"><i class="menu-icon">R</i><span class="menu-text">الكورسات</span></a></li>
 </ul></div></nav>
</aside><button id="adminSidebarOverlay" class="admin-sidebar-overlay" tabindex="-1"></button>
<div id="right-panel" class="right-panel">
 <header id="header" class="modern-header"><div class="modern-header-content">
  <div class="header-right">
   <button id="menuToggle" class="modern-menu-toggle">القائمة</button>
   <div class="modern-notification"><button id="notificationToggle" class="notification-btn">N</button><div id="notificationMenu" class="notification-dropdown" aria-hidden="true"><a href="#main-content">إشعار جديد</a></div></div>
   <button id="darkModeToggle" class="dark-mode-toggle">الوضع</button>
  </div><div class="header-left"><div class="modern-user-menu">
   <button id="userMenuToggle" class="user-profile-btn">الحساب</button>
   <div id="userMenu" class="user-dropdown" aria-hidden="true"><a href="#main-content">بيانات الحساب</a></div>
  </div></div>
 </div></header>
 <main id="main-content" class="content"><h1 class="admin-page__title">أساسيات الرسم والتحريك</h1>
  <form id="ajaxForm" method="post"><button type="submit">حفظ الكورس</button><output id="saved">0</output></form>
  <form id="nativeForm" method="post" action="/submitted"><button type="submit">نشر الكورس</button></form>
  <div class="enhanced-alert enhanced-alert-error"><div class="alert-content">راجع البيانات</div><button data-close-alert>إغلاق الخطأ</button></div>
 </main>
</div>
<script>
document.getElementById('ajaxForm').addEventListener('submit', event => {
 event.preventDefault();
 if (event.target.dataset.roknSubmitting === 'true') return;
 document.getElementById('saved').value = String(Number(document.getElementById('saved').value) + 1);
});
</script><script src="/js/app.js"></script><script src="/admin/assets/js/main.js"></script>
</body></html>`;
let submissions = 0;
const server = createServer(async (request, response) => {
    if (request.url === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        return response.end(fixture);
    }
    if (request.url === '/submitted') {
        submissions += 1;
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        return response.end('<p>saved</p>');
    }
    const path = resolve(publicRoot, '.' + request.url.split('?')[0]);
    if (!path.startsWith(publicRoot + sep)) { response.writeHead(403); return response.end(); }
    try {
        const types = {'.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.ttf': 'font/ttf'};
        response.setHeader('Content-Type', types[extname(path)] || 'application/octet-stream');
        response.end(await readFile(path));
    } catch { response.writeHead(404); response.end(); }
});
await new Promise(ready => server.listen(0, '127.0.0.1', ready));
const origin = `http://127.0.0.1:${server.address().port}`;
let browser;
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    for (const storageDenied of [false, true]) {
        const context = await browser.newContext({viewport: {width: 1280, height: 900}});
        if (storageDenied) await context.addInitScript(() => {
            Object.defineProperty(window, 'localStorage', {get() { throw new DOMException('denied', 'SecurityError'); }});
        });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin);
        await page.waitForFunction(() => document.getElementById('menuToggle').hasAttribute('aria-expanded'));
        assert.equal(await page.locator('#left-panel').evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(7, 10, 16)', 'Legacy template CSS must not own the Rokn sidebar');
        await page.locator('#userMenuToggle').click();
        assert.equal(await page.locator('#userMenu').getAttribute('aria-hidden'), 'false');
        await page.locator('#notificationToggle').click();
        assert.equal(await page.locator('#userMenu').getAttribute('aria-hidden'), 'true');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#notificationMenu').getAttribute('aria-hidden'), 'true');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'notificationToggle');
        await page.locator('#darkModeToggle').click();
        assert.equal(await page.locator('#darkModeToggle').getAttribute('aria-pressed'), 'true');
        await page.locator('#ajaxForm button').click();
        assert.equal(await page.locator('#saved').textContent(), '1');
        assert.equal(await page.locator('#ajaxForm').getAttribute('aria-busy'), null);
        await page.locator('[data-close-alert]').click();
        assert.equal(await page.locator('.enhanced-alert').count(), 0);
        await page.locator('#menuToggle').click();
        assert.equal(Math.round((await page.locator('#left-panel').boundingBox()).width), 70);
        for (const width of [320, 375, 768, 1280]) {
            await page.setViewportSize({width, height: 900});
            await page.waitForTimeout(350);
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Overflow at ${width}px`);
            if (width <= 768) {
                await page.locator('#menuToggle').click();
                assert.equal(await page.locator('#left-panel').getAttribute('aria-modal'), 'true');
                assert.equal(await page.locator('#right-panel').getAttribute('inert'), '');
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('#left-panel').getAttribute('aria-hidden'), 'true');
                assert.equal(await page.evaluate(() => document.activeElement.id), 'menuToggle');
            }
        }
        await page.locator('#nativeForm button').click();
        await page.waitForURL('**/submitted');
        await page.goBack();
        await page.waitForFunction(() => !document.getElementById('nativeForm').hasAttribute('aria-busy'));
        assert.equal(await page.locator('#nativeForm button').getAttribute('aria-disabled'), null);
        assert.deepEqual(errors, []);
        await context.close();
        console.log(`PASS shell navigation, storage=${storageDenied ? 'denied' : 'available'}, AJAX submit, native submit/back, four viewport widths`);
    }
    assert.equal(submissions, 2);
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
