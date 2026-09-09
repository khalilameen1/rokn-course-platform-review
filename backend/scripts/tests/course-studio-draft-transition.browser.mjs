import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const publicRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../public/admin/assets/js');
const scripts = ['request.js', 'course-studio-core.js', 'course-studio-outline.js',
    'course-studio-editor-coordinator.js', 'course-studio-section-editor.js',
    'course-studio-module-editor.js', 'course-studio-details.js'];
const section = (id, type = 'lesson') => ({id, type, order: id - 100, module_id: 7,
    title: `محتوى ${id}`, title_ar: `محتوى ${id}`, lesson_description_ar: 'الكابشن المحفوظ',
    update_url: `/sections/${id}`, delete_url: `/sections/${id}`, has_video: true,
    row_label: type === 'project' ? 'مشروع عبور' : 'مقطع',
    project_requirements_ar: 'المطلوب المحفوظ', project_submission_types: ['text'],
});
let modules;
let version;
let requests;
let rejectNextEdit;
let courseTitle;
const reset = () => {
    modules = [7, 8].map((id, index) => ({id, order: index + 1,
        title: `الوحدة ${id}`, title_ar: `الوحدة ${id}`,
        update_url: `/modules/${id}`, delete_url: `/modules/${id}`,
        sections: id === 7 ? [section(101), section(102), section(103, 'project')] : [],
    }));
    version = 1;
    requests = [];
    rejectNextEdit = false;
    courseTitle = 'الكورس';
};
const fixture = () => `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<meta name="csrf-token" content="local-test"></head><body>
<a id="otherCourse" href="/other-course">فتح كورس آخر</a>
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
<div class="outline-item-actions" data-module-actions="${module.id}"><button data-inline-editor-open="project" data-module-id="${module.id}">إضافة مشروع</button></div>
</div></article>`).join('')}</div>
<div id="studioInlineAuthoring"><section id="studioInlineEditor" hidden>
<form id="sectionForm" action="/sections" aria-busy="false">
<input type="hidden" name="_token" value="local-test"><input type="hidden" name="authoring_request_id"><input type="hidden" name="authoring_version">
<input type="hidden" name="_method" value="POST" disabled><input type="hidden" name="module_id"><input type="hidden" name="order">
<input type="hidden" name="section_type" id="section_type"><input type="hidden" id="bunny_video_claim" name="bunny_video_claim">
<input type="file" id="bunny_video" data-video-required="true">
<span id="studioInlineEditorEyebrow"></span><h3 id="studioInlineEditorTitle"></h3><div id="studioInlineFeedback" hidden></div>
<input name="title_ar" required><div id="studioInlineLessonFields"><textarea name="lesson_description_ar"></textarea>
<input type="file" name="lesson_thumbnail"><input name="lesson_duration_minutes"><input name="is_opened" type="checkbox"></div>
<div id="studioInlineProjectFields" hidden><textarea name="project_requirements_ar"></textarea>
<input name="project_submission_types[]" value="text" type="checkbox"><input name="project_submission_types[]" value="file" type="checkbox">
<input name="is_graduation_project" type="checkbox"></div>
<button id="studioInlineDeleteSection" type="button" hidden>حذف</button>
<button data-inline-editor-close type="button">إلغاء</button><button id="studioInlineSaveSection" type="submit"><span>حفظ</span></button>
</form></section><section id="studioInlineModuleEditor" hidden><form id="studioModuleForm" action="/modules" aria-busy="false">
<input type="hidden" name="_token" value="local-test"><input type="hidden" name="authoring_request_id"><input type="hidden" name="authoring_version">
<input type="hidden" name="_method" value="POST" disabled><input type="hidden" name="order"><input name="title_ar" required>
<div data-module-feedback hidden></div><div class="studio-inline-module__copy"><span></span></div>
<button id="studioInlineDeleteModule" type="button" hidden>حذف</button><button data-inline-module-close type="button">إلغاء</button>
<button type="submit">حفظ الوحدة</button></form></section></div>
<button data-studio-course-open="publish">إعدادات النشر</button><section id="studioCoursePanel" hidden>
<form id="studioCourseForm" action="/course"><input type="hidden" name="authoring_version"><input name="title_ar" value="${courseTitle}">
<button type="submit" name="publishing_intent" value="save">حفظ المسودة</button>
<button type="submit" name="publishing_intent" value="publish">نشر التعديلات</button></form><div data-course-feedback hidden></div></section>
<div id="courseStudioToast"></div></div>
<script>window.RoknCourseVideoUpload={isBusy:()=>false,setSectionContext:()=>{
document.getElementById('bunny_video').required=false;},resetAfterCommit:()=>{}};</script>
${scripts.map(file => `<script src="/${file}"></script>`).join('')}<script>window.RoknCourseStudio.start();</script></body></html>`;
const json = (response, body, status = 200) => {
    response.writeHead(status, {'Content-Type': 'application/json; charset=utf-8'});
    response.end(JSON.stringify(body));
};
const server = createServer(async (request, response) => {
    const path = request.url.split('?')[0];
    if (path === '/other-course') {
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        return response.end('<!doctype html><h1>الكورس الآخر</h1>');
    }
    if (path === '/' || path === '/published') {
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        return response.end(fixture());
    }
    if (scripts.includes(path.slice(1))) {
        response.writeHead(200, {'Content-Type': 'text/javascript'});
        return response.end(await readFile(resolve(publicRoot, path.slice(1))));
    }
    if (request.method !== 'POST') return json(response, {}, 404);
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    const raw = Buffer.concat(chunks).toString('utf8');
    const body = Object.fromEntries([...raw.matchAll(/name="([^"]+)"\r\n\r\n([^\r\n]*)/g)]
        .map(match => [match[1], match[2]]));
    requests.push({path, body});
    if (rejectNextEdit) {
        rejectNextEdit = false;
        return json(response, {message: 'صحح العنوان ثم أعد الحفظ', errors: {title_ar: ['صحح العنوان ثم أعد الحفظ']}}, 422);
    }
    assert.equal(Number(body.authoring_version), version);
    if (path === '/course') {
        courseTitle = body.title_ar;
        return json(response, {success: true, saved: true, authoring_version: ++version, course: {
            authoring_version: version, publishing_status: body.publishing_intent === 'publish' ? 'published' : 'draft',
            title: body.title_ar, studio_url: body.publishing_intent === 'publish' ? '/published' : '/',
        }});
    }
    const isSection = path.startsWith('/sections');
    const id = Number(path.split('/')[2]);
    const target = (isSection ? modules[0].sections : modules).find(item => item.id === id);
    target.title = target.title_ar = body.title_ar;
    if (isSection) target.lesson_description_ar = body.lesson_description_ar;
    return json(response, {success: true, authoring_version: ++version, [isSection ? 'section' : 'module']: target});
});

await new Promise(done => server.listen(0, '127.0.0.1', done));
let browser;
const failures = [];
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const page = await browser.newPage();
    let acceptDiscard = false;
    let dialogs = [];
    page.on('dialog', async dialog => {
        dialogs.push(dialog.message());
        await (acceptDiscard ? dialog.accept() : dialog.dismiss());
    });
    const visit = async () => {
        reset(); acceptDiscard = true;
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        dialogs = []; acceptDiscard = false;
    };
    const settled = () => page.waitForFunction(() => !document.getElementById('courseStudio').inert);
    const check = async (name, test) => {
        await visit();
        try { await test(); console.log(`PASS ${name}`); }
        catch (error) { failures.push(`${name}: ${error.message}`); console.log(`FAIL ${name}: ${error.message}`); }
    };
    const sectionEdit = id => page.locator(`[data-inline-section-edit="${id}"]`).click();
    const moduleEdit = id => page.locator(`[data-inline-module-edit][data-module-id="${id}"]`).click();
    const title = page.locator('#sectionForm [name="title_ar"]');

    await check('same-kind section replacement retains unsaved title and caption on decline', async () => {
        await sectionEdit(101);
        await title.fill('عنوان لم يحفظ');
        await page.locator('[name="lesson_description_ar"]').fill('كابشن لم يحفظ');
        await sectionEdit(102);
        assert.equal(await title.inputValue(), 'عنوان لم يحفظ');
        assert.equal(await page.locator('[name="lesson_description_ar"]').inputValue(), 'كابشن لم يحفظ');
        assert.equal(dialogs.length, 1);
        assert.equal(requests.length, 0);
        acceptDiscard = true;
        await sectionEdit(102);
        assert.equal(await title.inputValue(), 'محتوى 102');
    });
    await check('new section does not reset the current unsaved project', async () => {
        await sectionEdit(103);
        await page.locator('[name="project_requirements_ar"]').fill('متطلبات جديدة');
        await page.locator('[name="project_submission_types[]"][value="file"]').check();
        await page.locator('[data-inline-editor-open="project"][data-module-id="8"]').click();
        assert.equal(await page.locator('[name="project_requirements_ar"]').inputValue(), 'متطلبات جديدة');
        assert.equal(await page.locator('[name="project_submission_types[]"][value="file"]').isChecked(), true);
        assert.equal(dialogs.length, 1);
    });
    await check('same-kind module replacement and explicit close retain a declined rename', async () => {
        await moduleEdit(7);
        await page.locator('#studioModuleForm [name="title_ar"]').fill('اسم وحدة جديد');
        await moduleEdit(8);
        assert.equal(await page.locator('#studioModuleForm [name="title_ar"]').inputValue(), 'اسم وحدة جديد');
        await page.locator('[data-inline-module-close]').click();
        assert.equal(await page.locator('#studioInlineModuleEditor').isVisible(), true);
        assert.equal(dialogs.length, 2);
    });
    await check('cross-kind replacement does not hide the unsaved section', async () => {
        await sectionEdit(101);
        await title.fill('عنوان قبل تبديل الوحدة');
        await moduleEdit(8);
        assert.equal(await page.locator('#studioInlineEditor').isVisible(), true);
        assert.equal(await page.locator('#studioInlineModuleEditor').isVisible(), false);
        assert.equal(await title.inputValue(), 'عنوان قبل تبديل الوحدة');
        assert.equal(dialogs.length, 1);
    });
    await check('cross-kind replacement does not hide the unsaved module', async () => {
        await moduleEdit(7);
        await page.locator('#studioModuleForm [name="title_ar"]').fill('وحدة لم تحفظ');
        await sectionEdit(101);
        assert.equal(await page.locator('#studioInlineModuleEditor').isVisible(), true);
        assert.equal(await page.locator('#studioInlineEditor').isVisible(), false);
        assert.equal(dialogs.length, 1);
    });
    await check('failed save remains dirty and declining publish makes no server mutation', async () => {
        await sectionEdit(101);
        await title.fill('تعديل بعد رفض الحفظ');
        rejectNextEdit = true;
        await page.locator('#studioInlineSaveSection').click();
        await settled();
        await page.locator('[data-studio-course-open]').click();
        await page.locator('[name="publishing_intent"][value="publish"]').click();
        await settled();
        assert.equal(requests.length, 1, 'declined publish must not send the course PATCH');
        assert.equal(await title.inputValue(), 'تعديل بعد رفض الحفظ');
        assert.equal(dialogs.length, 1);
        acceptDiscard = true;
        await page.locator('[name="publishing_intent"][value="publish"]').click();
        await page.waitForURL('**/published');
        assert.equal(requests.length, 2);
        assert.equal(modules[0].sections[0].title, 'محتوى 101', 'discard must not implicitly save the section');
    });
    await check('selected video without a name and checkbox changes are unsaved data', async () => {
        await sectionEdit(101);
        await page.locator('#bunny_video').setInputFiles({name: 'lesson.mp4', mimeType: 'video/mp4', buffer: Buffer.from('local fixture')});
        await page.locator('[data-inline-editor-close]').click();
        assert.equal(await page.locator('#studioInlineEditor').isVisible(), true);
        assert.equal(dialogs.length, 1);
        await page.locator('#bunny_video').setInputFiles([]);
        await page.locator('[name="is_opened"]').check();
        await sectionEdit(102);
        assert.equal(await page.locator('[name="is_opened"]').isChecked(), true);
        assert.equal(dialogs.length, 2);
    });
    await check('unchanged or reverted forms switch without a confirmation', async () => {
        await sectionEdit(101);
        await title.fill('تغيير مؤقت');
        await title.fill('محتوى 101');
        await sectionEdit(102);
        await moduleEdit(7);
        await moduleEdit(8);
        assert.equal(dialogs.length, 0);
        assert.equal(await page.locator('#studioModuleForm [name="title_ar"]').inputValue(), 'الوحدة 8');
    });
    await check('saving course details does not discard or implicitly save an inline edit', async () => {
        await sectionEdit(101);
        await title.fill('يبقى في المحرر');
        await page.locator('[data-studio-course-open]').click();
        await page.locator('[name="publishing_intent"][value="save"]').click();
        await settled();
        assert.equal(dialogs.length, 0, 'a same-page course save does not discard the inline editor');
        assert.equal(await title.inputValue(), 'يبقى في المحرر');
        assert.equal(modules[0].sections[0].title, 'محتوى 101');
        await sectionEdit(102);
        assert.equal(await title.inputValue(), 'يبقى في المحرر');
        assert.equal(dialogs.length, 1);
    });
    await check('a version update from course details does not make unchanged content dirty', async () => {
        await sectionEdit(101);
        await page.locator('[data-studio-course-open]').click();
        await page.locator('[name="publishing_intent"][value="save"]').click();
        await settled();
        assert.equal(version, 2);
        await moduleEdit(7);
        assert.equal(dialogs.length, 0);
        assert.equal(await page.locator('#studioInlineModuleEditor').isVisible(), true);
    });
    await check('a rejected publish retains the editor and still requires discard on the next transition', async () => {
        await sectionEdit(103);
        await page.locator('[name="project_requirements_ar"]').fill('متطلبات محفوظة في المحرر فقط');
        await page.locator('[data-studio-course-open]').click();
        acceptDiscard = true;
        rejectNextEdit = true;
        await page.locator('[name="publishing_intent"][value="publish"]').click();
        await settled();
        assert.equal(requests.length, 1);
        assert.equal(version, 1);
        acceptDiscard = false;
        await moduleEdit(7);
        assert.equal(await page.locator('#studioInlineEditor').isVisible(), true);
        assert.equal(await page.locator('[name="project_requirements_ar"]').inputValue(), 'متطلبات محفوظة في المحرر فقط');
        assert.equal(dialogs.length, 2);
    });
    await check('successful section and module saves clear dirty state before the next action', async () => {
        await sectionEdit(101);
        await title.fill('المقطع المحفوظ الجديد');
        await page.locator('#studioInlineSaveSection').click();
        await settled();
        await moduleEdit(7);
        await page.locator('#studioModuleForm [name="title_ar"]').fill('الوحدة المحفوظة الجديدة');
        await page.locator('#studioModuleForm [type="submit"]').click();
        await settled();
        await sectionEdit(101);
        assert.equal(await title.inputValue(), 'المقطع المحفوظ الجديد');
        await page.locator('[data-studio-course-open]').click();
        await page.locator('[name="publishing_intent"][value="publish"]').click();
        await page.waitForURL('**/published');
        assert.equal(dialogs.length, 0);
        assert.equal(requests.length, 3);
    });
    const leaveCourse = async () => {
        const prompt = page.waitForEvent('dialog', {timeout: 1500}).catch(() => null);
        await page.locator('#otherCourse').click({noWaitAfter: true});
        const dialog = await prompt;
        assert.equal(dialog?.type(), 'beforeunload', 'leaving unsaved authoring must require explicit discard');
        assert.equal(new URL(page.url()).pathname, '/', 'declining must keep the current course editor');
    };
    await check('leaving the course preserves a declined unsaved section and caption', async () => {
        await sectionEdit(101);
        await title.fill('عنوان قبل فتح كورس آخر');
        await page.locator('[name="lesson_description_ar"]').fill('كابشن قبل المغادرة');
        await leaveCourse();
        assert.equal(await title.inputValue(), 'عنوان قبل فتح كورس آخر');
        assert.equal(await page.locator('[name="lesson_description_ar"]').inputValue(), 'كابشن قبل المغادرة');
        assert.equal(requests.length, 0);
    });
    await check('leaving the course preserves a declined unsaved module name', async () => {
        await moduleEdit(7);
        await page.locator('#studioModuleForm [name="title_ar"]').fill('وحدة قبل المغادرة');
        await leaveCourse();
        assert.equal(await page.locator('#studioModuleForm [name="title_ar"]').inputValue(), 'وحدة قبل المغادرة');
        assert.equal(requests.length, 0);
    });
    await check('a rejected course detail save still protects the title when leaving', async () => {
        await page.locator('[data-studio-course-open]').click();
        await page.locator('#studioCourseForm [name="title_ar"]').fill('اسم الكورس غير المحفوظ');
        rejectNextEdit = true;
        await page.locator('[name="publishing_intent"][value="save"]').click();
        await settled();
        await leaveCourse();
        assert.equal(await page.locator('#studioCourseForm [name="title_ar"]').inputValue(), 'اسم الكورس غير المحفوظ');
        assert.equal(requests.length, 1);
        await page.locator('[name="publishing_intent"][value="save"]').click();
        await settled();
        const beforeLeaving = dialogs.length;
        await page.locator('#otherCourse').click();
        await page.waitForURL('**/other-course');
        assert.equal(dialogs.length, beforeLeaving, 'successful retry must retire the saved baseline');
        assert.equal(requests.length, 2);
    });
    await check('unchanged and reverted course fields may leave without a warning', async () => {
        await page.locator('[data-studio-course-open]').click();
        await page.locator('#studioCourseForm [name="title_ar"]').fill('تعديل مؤقت');
        await page.locator('#studioCourseForm [name="title_ar"]').fill('الكورس');
        await page.locator('#otherCourse').click();
        await page.waitForURL('**/other-course');
        assert.equal(dialogs.length, 0);
        assert.equal(requests.length, 0);
    });
    await check('a saved course survives leaving and returning while its next edit is protected', async () => {
        await page.locator('[data-studio-course-open]').click();
        await page.locator('#studioCourseForm [name="title_ar"]').fill('العنوان المحفوظ');
        await page.locator('[name="publishing_intent"][value="save"]').click();
        await settled();
        await page.locator('#otherCourse').click();
        await page.waitForURL('**/other-course');
        assert.equal(dialogs.length, 0);
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        await page.locator('[data-studio-course-open]').click();
        assert.equal(await page.locator('#studioCourseForm [name="title_ar"]').inputValue(), 'العنوان المحفوظ');
        await page.locator('#studioCourseForm [name="title_ar"]').fill('تعديل تالٍ غير محفوظ');
        await leaveCourse();
        assert.equal(requests.length, 1);
    });
    await check('explicitly discarding a module clears its navigation warning without saving it', async () => {
        await moduleEdit(7);
        await page.locator('#studioModuleForm [name="title_ar"]').fill('اسم سيُتجاهل');
        acceptDiscard = true;
        await page.locator('[data-inline-module-close]').click();
        await page.locator('#otherCourse').click();
        await page.waitForURL('**/other-course');
        assert.equal(dialogs.length, 1, 'only the explicit close confirmation is needed');
        assert.equal(requests.length, 0);
    });
    await check('explicitly accepting document navigation never submits unsaved content', async () => {
        await sectionEdit(101);
        await title.fill('عنوان لم يُرسل');
        acceptDiscard = true;
        await page.locator('#otherCourse').click();
        await page.waitForURL('**/other-course');
        assert.equal(dialogs.length, 1);
        assert.equal(requests.length, 0);
        assert.equal(modules[0].sections[0].title, 'محتوى 101');
    });
    assert.deepEqual(failures, [], failures.join('\n'));
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
