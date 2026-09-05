import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, extname, resolve, sep} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const publicRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../public');
const styles = [
    'custom-global.css',
    'course-workspace.css',
    'course-studio.css',
    'course-editor.css',
    'course-student-preview.css',
];
const fixture = `<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
${styles.map(file => `<link rel="stylesheet" href="/admin/assets/css/${file}">`).join('')}
<style>html,body{margin:0}body{padding:12px;background:var(--rokn-admin-canvas)}</style>
<body class="admin-shell">
<header class="course-workspace"><div class="course-workspace__context"><div><span>مساحة الكورس</span><h1>أساسيات الرسم</h1></div><b class="course-workspace__state">مسودة</b></div></header>
<main class="course-studio">
 <div class="course-studio__topbar"><div class="course-studio__heading"><h1>محتوى الكورس</h1></div><div class="course-studio__top-actions"><button class="course-studio__save-action">حفظ</button></div></div>
 <div class="course-studio__layout"><section class="course-studio__canvas">
  <article class="student-course-card"><div class="student-course-card__cover"><span class="studio-edit-chip">تعديل الغلاف</span></div><div class="student-course-card__content"><div class="student-course-card__badges"><span>تصميم</span></div><h2>أساسيات الرسم</h2><p>وصف واضح للكورس</p></div></article>
  <div class="studio-inline-editor"><header class="studio-inline-editor__header"><h3>إضافة مقطع</h3></header><div class="studio-inline-editor__body"><label class="studio-inline-field">العنوان<input type="text" value="المقطع الأول"></label><p class="studio-inline-feedback">تم الحفظ</p><p class="studio-inline-feedback is-error">تعذر الحفظ</p></div></div>
 </section><aside class="studio-rail-card"><h2>جاهزية النشر</h2><ul><li>أكمل الغلاف</li></ul></aside></div>
</main>
<section class="course-editor"><div class="form-container"><div class="form-section"><h2 class="section-title">بيانات الكورس</h2><label class="form-label-modern">العنوان</label><input class="form-control-modern"><p class="course-editor__plan-note">راجع سعر الفئة</p></div></div></section>
<section class="learner-preview"><div class="learner-preview__notice is-draft">المعاينة لمسودة الكورس</div><div class="learner-preview__shell"><article class="learner-preview__phone"><div class="learner-preview__course"><h2>الكورس كما يراه الطالب</h2><p>محتوى المعاينة</p></div></article></div></section>
</body></html>`;

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

const channel = value => {
    value /= 255;
    return value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4;
};
const luminance = color => {
    const [r, g, b] = color.match(/[\d.]+/g).slice(0, 3).map(Number);
    return .2126 * channel(r) + .7152 * channel(g) + .0722 * channel(b);
};
const contrast = (foreground, background) => {
    const values = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
    return (values[0] + .05) / (values[1] + .05);
};

await new Promise(done => server.listen(0, '127.0.0.1', done));
let browser;
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const page = await browser.newPage();
    await page.goto(`http://127.0.0.1:${server.address().port}`);
    for (const dark of [false, true]) {
        await page.locator('body').evaluate((body, enabled) => body.classList.toggle('dark-mode', enabled), dark);
        const expected = dark ? {
            text: 'rgb(247, 249, 252)', surface: 'rgb(17, 22, 32)', border: 'rgb(37, 44, 56)',
        } : {
            text: 'rgb(17, 22, 32)', surface: 'rgb(255, 255, 255)', border: 'rgb(229, 231, 235)',
        };
        for (const selector of ['.student-course-card', '.studio-inline-editor', '.form-container', '.learner-preview__phone']) {
            const colors = await page.locator(selector).evaluate(element => {
                const style = getComputedStyle(element);
                return {color: style.color, background: style.backgroundColor, border: style.borderTopColor};
            });
            assert.equal(colors.color, expected.text, `${selector} text in ${dark ? 'dark' : 'light'} mode`);
            assert.equal(colors.background, expected.surface, `${selector} surface in ${dark ? 'dark' : 'light'} mode`);
            assert.equal(colors.border, expected.border, `${selector} border in ${dark ? 'dark' : 'light'} mode`);
        }
        for (const selector of ['.studio-edit-chip', '.studio-inline-feedback:not(.is-error)', '.studio-inline-feedback.is-error', '.course-editor__plan-note', '.learner-preview__notice']) {
            const colors = await page.locator(selector).evaluate(element => {
                const style = getComputedStyle(element);
                return {color: style.color, background: style.backgroundColor};
            });
            assert.ok(contrast(colors.color, colors.background) >= 4.5, `${selector} contrast in ${dark ? 'dark' : 'light'} mode`);
        }
        for (const width of [320, 375, 768, 1280]) {
            await page.setViewportSize({width, height: 900});
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `overflow at ${width}px in ${dark ? 'dark' : 'light'} mode`);
        }
    }
    console.log('PASS course authoring semantic theme, light/dark contrast, four viewport widths');
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
