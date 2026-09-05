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
let version = 14;
let moduleCreated = false;
let modulePostCount = 0;
const moduleIntentIds = [];
const moduleExpectedVersions = [];
const sectionReceipts = new Map();
const committedSections = [];
let sectionPostCount = 0;
let sectionReceiptGetCount = 0;

const modulePayload = () => ({
    id: 7,
    title: 'الوحدة الأولى',
    title_ar: 'الوحدة الأولى',
    order: 1,
    update_url: '/modules/7',
    delete_url: '/modules/7',
    sections: [],
});
const currentModulePayload = () => ({
    ...modulePayload(),
    title: 'الوحدة الأحدث',
    title_ar: 'الوحدة الأحدث',
    sections: committedSections.map(section => ({...section})),
});
const sectionMarkup = section => `<div class="outline-item" data-section-id="${section.id}" data-section-type="${section.type}"><span class="outline-item__copy"><strong>${section.title}</strong><small>${section.row_label}</small></span><button type="button" data-inline-section-edit="${section.id}">تعديل</button></div>`;
const moduleMarkup = () => moduleCreated ? `<article class="outline-module" data-module-id="7">
 <header class="outline-module__header">
  <button type="button" class="outline-module__drag"></button>
  <button type="button" class="outline-module__toggle" aria-expanded="true" aria-controls="module-7-content"><span class="outline-module__number">1</span><span class="outline-module__name"><small>الوحدة 1</small><strong>الوحدة الأحدث</strong></span><span class="outline-module__count">0 عناصر</span></button>
  <button type="button" data-inline-module-edit data-module-id="7" class="outline-module__edit">تعديل</button>
 </header>
 <div class="outline-module__content studio-sortable-sections" id="module-7-content" data-module-id="7">${committedSections.map(sectionMarkup).join('')}<div class="outline-item-actions" data-module-actions="7">${committedSections.some(section => section.type === 'project') ? '<span data-project-present>المشروع مضاف</span>' : '<button type="button" data-inline-editor-open="project" data-module-id="7">مشروع</button>'}</div></div>
</article>` : '';
const fixture = () => `<!doctype html><html lang="ar" dir="rtl"><head>
<meta charset="utf-8"><meta name="csrf-token" content="browser-test-token">
</head><body>
<div id="courseStudio" data-course-id="3" data-actor-id="42" data-can-author="1" data-authoring-version="${version}">
 <script type="application/json" id="courseAuthoringGraph">${JSON.stringify({authoring_version: version, modules: moduleCreated ? [currentModulePayload()] : [], module_reorder_url: '/modules/reorder', section_reorder_url: '/sections/reorder'})}</script>
 <button id="addFirstModule" type="button" data-inline-module-open>إضافة أول وحدة</button>
 ${moduleCreated ? '' : '<div id="studioEmptyCourse">لا توجد وحدات</div>'}
 <div id="studioModulesList">${moduleMarkup()}</div>
 <div id="studioInlineAuthoring">
  <section id="studioInlineEditor" hidden>
   <form id="sectionForm" action="/sections" data-create-receipt-url="/section-receipts/__INTENT__" aria-busy="false">
    <input name="_token" value="browser-test-token"><input name="authoring_request_id">
    <input name="authoring_version" value="1"><input name="_method" value="POST" disabled>
    <input name="module_id"><input name="order"><input name="section_type" id="section_type" value="lesson">
    <input name="bunny_video_claim" id="bunny_video_claim"><input id="bunny_video" type="file" data-video-required="true">
    <span id="studioInlineEditorEyebrow"></span><h3 id="studioInlineEditorTitle"></h3>
    <div id="studioInlineFeedback" hidden></div>
    <input name="title_ar" id="sectionTitle">
    <div id="studioInlineLessonFields">
     <textarea name="lesson_description_ar"></textarea>
     <input name="lesson_thumbnail" id="lessonThumbnail" type="file">
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
const studioFetch = window.fetch.bind(window);
window.fetch = async (url, options = {}) => {
 const response = await studioFetch(url, options);
 if (String(url).endsWith('/sections') && String(options.method || 'GET').toUpperCase() === 'POST') {
  if (options.body?.get?.('section_type') === 'lesson') {
   return new Response('{"success":', {status: 200, headers: {'Content-Type': 'application/json'}});
  }
  throw new TypeError('response lost after commit');
 }
 return response;
};
window.RoknCourseStudio.start();
</script>
</body></html>`;

const json = (response, body) => {
    response.setHeader('Content-Type', 'application/json; charset=utf-8');
    response.end(JSON.stringify(body));
};
const requestBody = async request => {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    return Buffer.concat(chunks).toString('utf8');
};
const multipartValue = (body, name) => {
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return body.match(new RegExp(`name="${escaped}"\\r\\n\\r\\n([^\\r\\n]*)`))?.[1] || '';
};
const server = createServer(async (request, response) => {
    const path = request.url.split('?')[0];
    if (path === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        return response.end(fixture());
    }
    if (scripts.includes(path.slice(1))) {
        response.setHeader('Content-Type', 'text/javascript; charset=utf-8');
        return response.end(await readFile(resolve(publicRoot, 'admin/assets/js', path.slice(1))));
    }
    if (request.method === 'POST' && path === '/modules') {
        const body = await requestBody(request);
        modulePostCount += 1;
        moduleIntentIds.push(multipartValue(body, 'authoring_request_id'));
        moduleExpectedVersions.push(multipartValue(body, 'authoring_version'));
        if (modulePostCount === 1) {
            moduleCreated = true;
            version = 19;
            await new Promise(resolve => response.once('close', resolve));
            return;
        }
        return json(response, {
            success: true,
            authoring_version: 15,
            module: modulePayload(),
        });
    }
    if (request.method === 'POST' && path === '/sections') {
        const body = await requestBody(request);
        const intent = multipartValue(body, 'authoring_request_id');
        const type = multipartValue(body, 'section_type') || 'lesson';
        const id = type === 'project' ? 35 : 34;
        sectionPostCount += 1;
        const receiptVersion = ++version;
        const payload = {
            success: true,
            authoring_version: receiptVersion,
            section: type === 'project'
                ? {id, module_id: 7, type, title: 'مشروع الوحدة', title_ar: 'مشروع الوحدة', order: 2, update_url: '/sections/35', delete_url: '/sections/35', row_label: 'مشروع عبور بعد الوحدة'}
                : {id, module_id: 7, type, title: 'المقطع الأول', title_ar: 'المقطع الأول', order: 1, update_url: '/sections/34', delete_url: '/sections/34', has_video: true, is_opened: true, row_label: 'مقطع · مجاني'},
        };
        sectionReceipts.set(intent, payload);
        const committedIndex = committedSections.findIndex(section => section.id === id);
        if (committedIndex >= 0) committedSections.splice(committedIndex, 1, payload.section);
        else committedSections.push(payload.section);
        if (type === 'lesson') version += 2;
        return json(response, payload);
    }
    if (request.method === 'GET' && path.startsWith('/section-receipts/')) {
        sectionReceiptGetCount += 1;
        const intent = path.split('/').at(-1);
        const payload = sectionReceipts.get(intent);
        return json(response, payload ? {
            ...payload,
            state: 'completed',
            receipt_authoring_version: payload.authoring_version,
            authoring_version: version,
        } : {state: 'absent', authoring_version: version});
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
    await page.evaluate(() => sessionStorage.setItem('rokn-course-studio-pending-module:41:3', JSON.stringify({
        expectedVersion: 14,
        fields: [['authoring_request_id', '00000000-0000-4000-8000-000000000041'], ['title_ar', 'مسودة حساب آخر']],
        savedAt: Date.now(),
    })));
    await page.reload();
    assert.equal(await page.locator('#studioInlineModuleEditor').isHidden(), true, 'one administrator must not restore another account pending module');

    await page.locator('#addFirstModule').click();
    await page.locator('#studioInlineModuleName').fill('الوحدة الأولى');
    const reconciledReload = page.waitForNavigation({waitUntil: 'load'});
    await page.locator('#studioModuleForm button[type="submit"]').click();
    try {
        await reconciledReload;
    } catch (error) {
        const state = await page.evaluate(() => ({
            toast: document.getElementById('courseStudioToast')?.textContent,
            busy: document.getElementById('studioModuleForm')?.getAttribute('aria-busy'),
            reconciliation: document.documentElement.getAttribute('data-authoring-reconciliation'),
        }));
        throw new Error(`reconciliation reload failed: posts=${modulePostCount} state=${JSON.stringify(state)}`, {cause: error});
    }
    await page.locator('#studioInlineModuleEditor:not([hidden])').waitFor();
    assert.equal(await page.locator('#studioInlineModuleName').inputValue(), 'الوحدة الأولى', 'unknown create must restore the module title');
    assert.equal(await page.locator('#studioModuleForm [name="authoring_version"]').inputValue(), '14', 'idempotent replay must retain the original expected version');
    assert.equal(await page.locator('.outline-module[data-module-id="7"]').count(), 1, 'server-created module must be visible exactly once after reconciliation');
    await page.locator('#studioModuleForm button[type="submit"]').click();
    await page.locator('.outline-module[data-module-id="7"]').waitFor();
    await page.waitForFunction(() => document.getElementById('studioModuleForm').getAttribute('aria-busy') === 'false');
    assert.equal(modulePostCount, 2, 'recovery must replay the create intent once');
    assert.ok(moduleIntentIds[0], 'the create intent must be present');
    assert.equal(moduleIntentIds[1], moduleIntentIds[0], 'recovery must reuse the original create intent');
    assert.deepEqual(moduleExpectedVersions, ['14', '14'], 'recovery must preserve the original request fingerprint');
    assert.equal(await page.locator('.outline-module[data-module-id="7"]').count(), 1, 'the replay must not duplicate the module row');
    assert.equal(await page.locator('.outline-module[data-module-id="7"] .outline-module__name strong').textContent(), 'الوحدة الأحدث', 'an old receipt must not overwrite a newer module title');
    assert.equal(await page.locator('#courseStudio').getAttribute('data-authoring-version'), '19', 'an old receipt must not downgrade the live authoring version');
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
    await page.locator('#lessonThumbnail').setInputFiles({name: 'cover.png', mimeType: 'image/png', buffer: Buffer.from('thumbnail-bytes')});
    const sectionCanonicalReload = page.waitForNavigation({waitUntil: 'load'});
    await page.locator('#studioInlineSaveSection').click();
    await sectionCanonicalReload;
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
    assert.equal(sectionPostCount, 1, 'a lost lesson response must resolve its receipt without uploading or posting the thumbnail twice');
    assert.equal(sectionReceiptGetCount, 1, 'a truncated successful JSON response must query its committed receipt before refreshing');
    assert.equal(await page.locator('#courseStudio').getAttribute('data-authoring-version'), '22', 'a concurrent advance must come from the refreshed canonical graph');

    await page.locator('[data-inline-editor-close]').first().click();
    await page.locator('[data-inline-editor-open="project"][data-module-id="7"]').click();
    await page.locator('#sectionTitle').fill('مشروع الوحدة');
    await page.locator('[name="project_requirements_ar"]').fill('ارفع نتيجة المشروع');
    await page.locator('#studioInlineSaveSection').click();
    await page.locator('.outline-item[data-section-id="35"]').waitFor();
    assert.equal(sectionPostCount, 2, 'a lost project response must resolve its receipt without a duplicate POST');
    assert.equal(sectionReceiptGetCount, 2, 'both invalid JSON and a lost network response must use the receipt endpoint');
    assert.equal(await page.locator('.outline-item[data-section-id="35"]').count(), 1, 'the recovered project receipt must create one canonical row');

    await page.evaluate(intentId => sessionStorage.setItem('rokn-course-studio-pending-module:42:3', JSON.stringify({
        expectedVersion: 14,
        fields: [['authoring_request_id', intentId], ['authoring_version', '14'], ['order', '1'], ['title_ar', 'الوحدة الأولى']],
        savedAt: Date.now(),
    })), moduleIntentIds[0]);
    moduleCreated = false;
    await page.reload();
    await page.locator('#studioInlineModuleEditor:not([hidden])').waitFor();
    const supersededReload = page.waitForNavigation({waitUntil: 'load'});
    await page.locator('#studioModuleForm button[type="submit"]').click();
    await supersededReload;
    assert.equal(await page.locator('.outline-module[data-module-id="7"]').count(), 0, 'a receipt for a deleted module must not recreate a fake row');
    assert.equal(
        await page.evaluate(() => sessionStorage.getItem('rokn-course-studio-pending-module:42:3')),
        null,
        'a superseded receipt must be cleared so the user can create a fresh unit'
    );

    assert.deepEqual(failures, [], failures.join('\n'));
    console.log('PASS create receipt recovery for module, truncated lesson JSON, concurrent canonical refresh, project, and immediate edit');
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
