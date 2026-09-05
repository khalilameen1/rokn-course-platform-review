import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const publicRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../public');
const scripts = [
    'request.js',
    'course-studio-core.js',
    'course-studio-outline.js',
    'course-studio-editor-coordinator.js',
    'course-studio-section-editor.js',
    'course-studio-module-editor.js',
];
let version = 1;

const fixture = `<!doctype html><html lang="ar" dir="rtl"><head>
<meta charset="utf-8"><meta name="csrf-token" content="browser-test-token">
</head><body>
<div id="courseStudio" data-course-id="3" data-can-author="1" data-authoring-version="1">
 <script type="application/json" id="courseAuthoringGraph">{"authoring_version":1,"modules":[],"module_reorder_url":"/modules/reorder","section_reorder_url":"/sections/reorder"}</script>
 <button id="addFirstModule" type="button" data-inline-module-open>إضافة أول وحدة</button>
 <div id="studioEmptyCourse">لا توجد وحدات</div>
 <div id="studioModulesList"></div>
 <div id="studioInlineAuthoring">
  <section id="studioInlineEditor" hidden>
   <form id="sectionForm" action="/sections" aria-busy="false">
    <input name="_token" value="browser-test-token"><input name="authoring_request_id">
    <input name="authoring_version" value="1"><input name="_method" value="POST" disabled>
    <input name="module_id"><input name="order"><input name="section_type" id="section_type" value="lesson">
    <input name="bunny_video_claim" id="bunny_video_claim"><input id="bunny_video" type="file" data-video-required="true">
    <span id="studioInlineEditorEyebrow"></span><h3 id="studioInlineEditorTitle"></h3>
    <div id="studioInlineFeedback" hidden></div>
    <div id="studioInlineLessonFields">
     <input name="title_ar" id="sectionTitle"><textarea name="lesson_description_ar"></textarea>
     <input name="lesson_duration_minutes" type="number"><input name="is_opened" type="checkbox">
    </div>
    <div id="studioInlineProjectFields" hidden>
     <textarea name="project_requirements_ar"></textarea>
     <input name="project_submission_types[]" value="text" type="checkbox">
     <input name="is_graduation_project" type="checkbox">
    </div>
    <button id="studioInlineDeleteSection" type="button" hidden>حذف</button>
    <button data-inline-editor-close type="button">إغلاق</button>
    <button id="studioInlineSaveSection" type="submit"><span>حفظ المقطع</span></button>
   </form>
  </section>
  <section id="studioInlineModuleEditor" hidden>
   <form id="studioModuleForm" action="/modules" aria-busy="false">
    <input name="_token" value="browser-test-token"><input name="authoring_request_id">
    <input name="authoring_version" value="1"><input name="_method" value="POST" disabled><input name="order">
    <div data-module-feedback hidden></div><div class="studio-inline-module__copy"><span></span></div>
    <input id="studioInlineModuleName" name="title_ar">
    <button id="studioInlineDeleteModule" type="button" hidden>حذف</button>
    <button data-inline-module-close type="button">إغلاق</button><button type="submit">حفظ الوحدة</button>
   </form>
  </section>
 </div>
 <div id="courseStudioToast"></div>
</div>
<script>window.RoknCourseVideoUpload={isBusy:()=>false,setSectionContext:()=>{document.getElementById('bunny_video').required=false;},resetAfterCommit:()=>{}};</script>
${scripts.map(file => `<script src="/${file}"></script>`).join('')}
<script>
window.RoknCourseStudio.start();
</script>
</body></html>`;

const json = (response, body) => {
    response.setHeader('Content-Type', 'application/json; charset=utf-8');
    response.end(JSON.stringify(body));
};
const server = createServer(async (request, response) => {
    const path = request.url.split('?')[0];
    if (path === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        return response.end(fixture);
    }
    if (scripts.includes(path.slice(1))) {
        response.setHeader('Content-Type', 'text/javascript; charset=utf-8');
        return response.end(await readFile(resolve(publicRoot, 'admin/assets/js', path.slice(1))));
    }
    if (request.method === 'POST' && path === '/modules') {
        return json(response, {
            success: true,
            authoring_version: ++version,
            module: {id: 7, title: 'الوحدة الأولى', title_ar: 'الوحدة الأولى', order: 1, update_url: '/modules/7', delete_url: '/modules/7', sections: []},
        });
    }
    if (request.method === 'POST' && path === '/sections') {
        return json(response, {
            success: true,
            authoring_version: ++version,
            section: {id: 34, module_id: 7, type: 'lesson', title: 'المقطع الأول', title_ar: 'المقطع الأول', order: 1, update_url: '/sections/34', delete_url: '/sections/34', has_video: true, is_opened: true, row_label: 'مقطع · مجاني'},
        });
    }
    if (request.method === 'POST' && path === '/sections/34') {
        return json(response, {
            success: true,
            authoring_version: ++version,
            section: {id: 34, module_id: 7, type: 'lesson', title: 'المقطع المعدّل', title_ar: 'المقطع المعدّل', order: 1, update_url: '/sections/34', delete_url: '/sections/34', has_video: true, is_opened: true, row_label: 'مقطع · مجاني'},
        });
    }
    response.statusCode = 404;
    json(response, {success: false, message: 'not found'});
});

await new Promise(done => server.listen(0, '127.0.0.1', done));
let browser;
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const page = await browser.newPage();
    await page.goto(`http://127.0.0.1:${server.address().port}`);

    await page.locator('#addFirstModule').click();
    await page.locator('#studioInlineModuleName').fill('الوحدة الأولى');
    await page.locator('#studioModuleForm button[type="submit"]').click();
    await page.locator('.outline-module[data-module-id="7"]').waitFor();
    await page.waitForFunction(() => document.getElementById('studioModuleForm').getAttribute('aria-busy') === 'false');
    const failures = [];
    const firstLessonOpened = await page.evaluate(() => {
        const editor = document.getElementById('studioInlineEditor');
        const form = document.getElementById('sectionForm');
        return !editor.hidden && form.elements.module_id.value === '7' && document.activeElement === form.elements.title_ar;
    });
    if (!firstLessonOpened) {
        failures.push('saving the first module did not open its first lesson editor');
    }

    if (await page.locator('#studioInlineEditor').isHidden()) {
        await page.locator('.outline-module[data-module-id="7"] .outline-item-insert').click();
    }
    await page.locator('#sectionTitle').fill('المقطع الأول');
    await page.locator('#bunny_video_claim').evaluate(input => { input.value = 'signed-video-claim'; });
    await page.locator('#studioInlineSaveSection').click();
    await page.locator('.outline-item[data-section-id="34"]').waitFor();
    assert.equal(
        await page.locator('.outline-item[data-section-id="34"] .outline-item__copy small').textContent(),
        'مقطع · مجاني',
        'the JS-created row must display the same canonical free-preview label as the initial Studio row'
    );
    await page.waitForFunction(() => document.getElementById('sectionForm').getAttribute('aria-busy') === 'false');
    await page.locator('[data-inline-section-edit="34"]').click();
    assert.equal(await page.locator('#sectionForm').getAttribute('data-section-id'), '34', 'saved lesson must remain editable without reload');
    assert.equal(await page.locator('#sectionForm [name="_method"]').inputValue(), 'PATCH');

    assert.deepEqual(failures, [], failures.join('\n'));
    console.log('PASS empty course -> module -> automatic lesson -> immediate edit without reload');
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
