import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const publicRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../public/admin/assets/js');
const scripts = ['request.js', 'course-studio-core.js', 'course-studio-outline.js',
    'course-studio-editor-coordinator.js', 'course-studio-section-editor.js', 'course-studio-module-editor.js'];
const section = (id, order, type = 'lesson') => ({
    id, order, type, module_id: 7, title: `محتوى ${id}`, title_ar: `محتوى ${id}`,
    update_url: `/sections/${id}`, delete_url: `/sections/${id}`, has_video: true,
    row_label: type === 'project' ? 'مشروع عبور بعد الوحدة' : 'مقطع',
    project_requirements_ar: 'نفّذ المشروع', project_submission_types: ['text'],
});
const unit = (id, order, sections = []) => ({id, order, sections,
    title: `الوحدة ${id}`, title_ar: `الوحدة ${id}`, update_url: `/modules/${id}`, delete_url: `/modules/${id}`});
let modules;
let version;
let requests;
let rejectNextEdit;
const reset = () => {
    modules = [unit(7, 1, [section(101, 1), section(102, 2), section(103, 3), section(104, 4, 'project')]),
        unit(8, 2), unit(9, 3)];
    version = 1;
    requests = [];
    rejectNextEdit = false;
};
const fixture = () => `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<meta name="csrf-token" content="local-test"></head><body>
<div id="courseStudio" data-course-id="3" data-actor-id="42" data-can-author="1" data-authoring-version="${version}">
<script type="application/json" id="courseAuthoringGraph">${JSON.stringify({modules, authoring_version: version,
    section_reorder_url: '/sections/reorder', module_reorder_url: '/modules/reorder'})}</script>
<div id="studioModulesList">${modules.map(module => `<article class="outline-module" data-module-id="${module.id}">
<header class="outline-module__header"><span class="outline-module__number"></span>
<span class="outline-module__name"><small></small><strong>${module.title}</strong></span>
<span class="outline-module__count"></span><button data-inline-module-edit data-module-id="${module.id}">تعديل الوحدة</button></header>
<div class="studio-sortable-sections outline-module__content" data-module-id="${module.id}">
${module.sections.map(item => `<div class="outline-item" data-section-id="${item.id}" data-section-type="${item.type}">
<span class="outline-item__copy"><strong>${item.title}</strong><small>${item.row_label}</small></span>
<button data-inline-section-edit="${item.id}">تعديل المحتوى</button></div>`).join('')}
<div class="outline-item-actions" data-module-actions="${module.id}">${module.sections.some(item => item.type === 'project')
    ? '<span data-project-present>المشروع مضاف</span>'
    : `<button data-inline-editor-open="project" data-module-id="${module.id}">إضافة مشروع</button>`}</div></div></article>`).join('')}</div>
<div id="studioInlineAuthoring"><section id="studioInlineEditor" hidden>
<form id="sectionForm" action="/sections" aria-busy="false">
<input name="_token" value="local-test"><input name="authoring_request_id"><input name="authoring_version">
<input name="_method" value="POST" disabled><input type="hidden" name="module_id"><input type="hidden" name="order">
<input name="section_type" id="section_type"><input id="bunny_video_claim" name="bunny_video_claim">
<input type="file" id="bunny_video" data-video-required="true">
<span id="studioInlineEditorEyebrow"></span><h3 id="studioInlineEditorTitle"></h3><div id="studioInlineFeedback" hidden></div>
<input name="title_ar"><div id="studioInlineLessonFields"><textarea name="lesson_description_ar"></textarea>
<input name="lesson_duration_minutes"><input name="is_opened" type="checkbox"></div>
<div id="studioInlineProjectFields" hidden><textarea name="project_requirements_ar"></textarea>
<input name="project_submission_types[]" value="text" type="checkbox"><input name="is_graduation_project" type="checkbox"></div>
<button id="studioInlineDeleteSection" type="button" hidden>حذف</button>
<button data-inline-editor-close type="button">إغلاق</button><button id="studioInlineSaveSection" type="submit"><span>حفظ</span></button>
</form></section><section id="studioInlineModuleEditor" hidden><form id="studioModuleForm" action="/modules" aria-busy="false">
<input name="_token" value="local-test"><input name="authoring_request_id"><input name="authoring_version">
<input name="_method" value="POST" disabled><input type="hidden" name="order"><input name="title_ar">
<div data-module-feedback hidden></div><div class="studio-inline-module__copy"><span></span></div>
<button id="studioInlineDeleteModule" type="button" hidden>حذف</button><button data-inline-module-close type="button">إغلاق</button>
<button type="submit">حفظ الوحدة</button></form></section></div><div id="courseStudioToast"></div></div>
<script>window.RoknCourseVideoUpload={isBusy:()=>false,setSectionContext:()=>{
document.getElementById('bunny_video').required=false;},resetAfterCommit:()=>{}};
window.Sortable=function(node, options){node.testSortable=options;};</script>
${scripts.map(file => `<script src="/${file}"></script>`).join('')}<script>window.RoknCourseStudio.start();</script></body></html>`;
const json = (response, body, status = 200) => {
    response.writeHead(status, {'Content-Type': 'application/json; charset=utf-8'});
    response.end(JSON.stringify(body));
};
const bodyOf = async request => {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    const raw = Buffer.concat(chunks).toString('utf8');
    if (request.headers['content-type']?.includes('application/json')) return JSON.parse(raw);
    return Object.fromEntries([...raw.matchAll(/name="([^"]+)"\r\n\r\n([^\r\n]*)/g)]
        .map(match => [match[1], match[2]]));
};
// Mirror the existing server's placement contract: explicit order moves the
// target; an omitted edit order keeps its current position under the course lock.
const normalize = items => items.forEach((item, index) => { item.order = index + 1; });
const place = (items, target, requestedOrder) => {
    const rest = items.filter(item => item.id !== target.id);
    const position = target.type === 'project' ? rest.length
        : Math.min(rest.filter(item => item.type !== 'project').length, Math.max(0, requestedOrder - 1));
    rest.splice(position, 0, target);
    normalize(rest);
    return rest;
};
const server = createServer(async (request, response) => {
    const path = request.url.split('?')[0];
    if (path === '/') {
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        return response.end(fixture());
    }
    if (scripts.includes(path.slice(1))) {
        response.writeHead(200, {'Content-Type': 'text/javascript'});
        return response.end(await readFile(resolve(publicRoot, path.slice(1))));
    }
    if (request.method === 'GET') return json(response, {message: 'not found'}, 404);
    const body = await bodyOf(request);
    requests.push({path, method: request.method, body});
    assert.equal(Number(body.authoring_version), version);
    if (path.endsWith('/reorder')) {
        if (body.modules) modules = body.modules.map(item => modules.find(module => module.id === item.id));
        if (body.sections) modules[0].sections = body.sections.map(item => modules[0].sections.find(section => section.id === item.id));
        normalize(modules);
        modules.forEach(module => normalize(module.sections));
        return json(response, {success: true, authoring_version: ++version, modules});
    }
    const isSection = path.startsWith('/sections');
    const id = Number(path.split('/')[2]) || null;
    let items = isSection ? modules[0].sections : modules;
    if (request.method === 'DELETE') {
        const target = items.find(item => item.id === id);
        const sectionIds = isSection ? [] : target.sections.map(item => item.id);
        items.splice(items.indexOf(target), 1);
        normalize(items);
        return json(response, {success: true, authoring_version: ++version,
            ...(isSection ? {deleted_section_id: id} : {deleted_module_id: id, section_ids: sectionIds})});
    }
    if (id && rejectNextEdit) {
        rejectNextEdit = false;
        return json(response, {message: 'تعذر حفظ التعديل الآن', errors: {title_ar: ['تعذر حفظ التعديل الآن']}}, 422);
    }
    const target = id ? items.find(item => item.id === id) : isSection ? section(105, 1) : unit(10, 1);
    target.title = target.title_ar = body.title_ar;
    if (isSection && body.lesson_description_ar !== undefined) target.lesson_description_ar = body.lesson_description_ar;
    items = place(items, target, body.order === undefined ? target.order : Number(body.order));
    if (isSection) modules[0].sections = items;
    else modules = items;
    return json(response, {success: true, authoring_version: ++version, [isSection ? 'section' : 'module']: target});
});

await new Promise(done => server.listen(0, '127.0.0.1', done));
let browser;
const failures = [];
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const page = await browser.newPage();
    page.on('dialog', dialog => dialog.accept());
    const visit = async () => { reset(); await page.goto(`http://127.0.0.1:${server.address().port}`); };
    const settled = () => page.waitForFunction(() => !document.getElementById('courseStudio').inert);
    const close = async () => {
        if (await page.locator('#studioInlineEditor').isVisible()) await page.locator('[data-inline-editor-close]').click();
        if (await page.locator('#studioInlineModuleEditor').isVisible()) await page.locator('[data-inline-module-close]').click();
    };
    const ids = kind => (kind === 'section' ? modules[0].sections : modules).map(item => item.id);
    const visibleIds = kind => page.locator(kind === 'section'
        ? '.studio-sortable-sections[data-module-id="7"] > .outline-item'
        : '#studioModulesList > .outline-module').evaluateAll((nodes, kind) =>
        nodes.map(node => Number(kind === 'section' ? node.dataset.sectionId : node.dataset.moduleId)), kind);
    for (const kind of ['section', 'module']) {
        for (const mutation of ['insert', 'delete']) {
            await visit();
            const isSection = kind === 'section';
            const form = isSection ? '#sectionForm' : '#studioModuleForm';
            if (mutation === 'insert') {
                await page.locator(isSection ? '.studio-sortable-sections[data-module-id="7"] > .outline-item-insert[data-insert-order="2"]'
                    : '.outline-module-insert[data-insert-order="2"]').click();
                await page.locator(`${form} [name="title_ar"]`).fill('العنصر المدرج');
                if (isSection) await page.locator('#bunny_video_claim').fill('completed-local-video-claim');
                await page.locator(`${form} button[type="submit"]`).click();
                await settled();
                assert.equal(requests.at(-1).body.order, '2', 'explicit insertion must retain its requested position');
                assert.deepEqual(ids(kind), isSection ? [101, 105, 102, 103, 104] : [7, 10, 8, 9]);
            } else {
                await page.locator(isSection ? '[data-inline-section-edit="101"]' : '[data-inline-module-edit][data-module-id="7"]').click();
                await page.locator(isSection ? '#studioInlineDeleteSection' : '#studioInlineDeleteModule').click();
                await settled();
                assert.deepEqual(ids(kind), isSection ? [102, 103, 104] : [8, 9]);
            }
            await close();
            const expected = ids(kind);
            await page.locator(isSection ? '[data-inline-section-edit="102"]' : '[data-inline-module-edit][data-module-id="8"]').click();
            await page.locator(`${form} [name="title_ar"]`).fill('العنوان المعدّل دون نقل');
            if (isSection) await page.locator('[name="lesson_description_ar"]').fill('شرح المقطع بعد التعديل');
            await page.locator(`${form} button[type="submit"]`).click();
            await settled();
            try {
                assert.deepEqual(ids(kind), expected, `${kind} content edit after ${mutation} must not silently move it`);
                assert.equal(requests.at(-1).body.order, undefined, 'content-only PATCH must not send an unchosen layout change');
                assert.deepEqual(await visibleIds(kind), expected, 'saved order and visible order must agree');
                await page.reload();
                assert.deepEqual(await visibleIds(kind), expected, 'reopening must retain the same saved layout');
            } catch (error) { failures.push(error.message); }
        }
    }

    for (const kind of ['section', 'module']) {
        await visit();
        await page.evaluate(kind => {
            const list = document.querySelector(kind === 'section' ? '.studio-sortable-sections[data-module-id="7"]' : '#studioModulesList');
            const nodes = list.querySelectorAll(kind === 'section' ? ':scope > .outline-item' : ':scope > .outline-module');
            nodes[0].before(nodes[1]);
            list.testSortable.onEnd({from: list, to: list, oldIndex: 1, newIndex: 0});
        }, kind);
        await page.waitForFunction(() => document.getElementById('courseStudio').dataset.authoringVersion === '2');
        await settled();
        assert.deepEqual(ids(kind), kind === 'section' ? [102, 101, 103, 104] : [8, 7, 9], 'explicit drag reorder must remain supported');
        assert.deepEqual(await visibleIds(kind), ids(kind));
    }

    await visit();
    await page.locator('[data-inline-section-edit="102"]').click();
    await page.locator('#sectionForm [name="title_ar"]').fill('عنوان يحتفظ به بعد الفشل');
    rejectNextEdit = true;
    await page.locator('#studioInlineSaveSection').click();
    await settled();
    assert.equal(await page.locator('#studioInlineEditor').isVisible(), true);
    assert.equal(await page.locator('#sectionForm [name="title_ar"]').inputValue(), 'عنوان يحتفظ به بعد الفشل');
    assert.equal(version, 1, 'a rejected edit must not advance saved state');
    await page.locator('#studioInlineSaveSection').click();
    await settled();
    assert.equal(modules[0].sections[1].title, 'عنوان يحتفظ به بعد الفشل');
    assert.deepEqual(ids('section'), [101, 102, 103, 104]);
    assert.deepEqual(failures, [], failures.join('\n'));
    console.log('PASS section/module insert/delete then content edit, explicit drag reorder, reload and failed-save retry');
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
