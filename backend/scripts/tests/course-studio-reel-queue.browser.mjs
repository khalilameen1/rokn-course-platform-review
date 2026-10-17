import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

// Real studio, queue, transfer and tus-js-client modules in headless Chrome.
// The Rokn API is a local fixture server that enforces the same version and
// claim rules as the backend; Bunny's TUS endpoint is intercepted.
const playwright = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const {chromium} = playwright.default || playwright;
const backendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const scripts = {
    'tus.min.js': 'node_modules/tus-js-client/dist/tus.min.js',
    ...Object.fromEntries([
        'request.js',
        'course-studio-core.js',
        'course-studio-outline.js',
        'course-studio-editor-coordinator.js',
        'course-studio-section-editor.js',
        'course-studio-reel-queue.js',
        'course-studio.js',
        'course-studio-bunny-upload-records.js',
        'course-studio-bunny-upload-transfer.js',
        'course-studio-bunny-upload-queue.js',
    ].map(file => [file, `public/admin/assets/js/${file}`])),
};
const queuePartial = await readFile(resolve(backendRoot, 'resources/views/admin/courses/partials/show/reel-upload-queue.blade.php'), 'utf8');

let version = 14;
let nextSectionId = 100;
const sections = [];
const initCalls = [];
const sectionPosts = [];
const renewCalls = [];
let failInitFor = new Set();

const fixture = () => `<!doctype html><html lang="ar" dir="rtl"><head>
<meta charset="utf-8"><meta name="csrf-token" content="browser-test-token">
</head><body>
<div id="courseStudio" data-course-id="3" data-actor-id="42" data-can-author="1" data-authoring-version="${version}">
 <script type="application/json" id="courseAuthoringGraph">${JSON.stringify({authoring_version: version, modules: [{id: 7, title: 'الوحدة الأولى', title_ar: 'الوحدة الأولى', order: 1, update_url: '/modules/7', delete_url: '/modules/7', sections: []}], module_reorder_url: '/modules/reorder', section_reorder_url: '/sections/reorder'})}</script>
 ${queuePartial.replace(/\{\{--[\s\S]*?--\}\}/g, '')}
 <div id="studioModulesList"><article class="outline-module" data-module-id="7">
  <div class="outline-module__content studio-sortable-sections" id="module-7-content" data-module-id="7">
   <div class="outline-item-actions" data-module-actions="7">
    <button type="button" data-reel-queue-open data-module-id="7">رفع عدة مقاطع</button>
    <button type="button" data-inline-editor-open="project" data-module-id="7">مشروع</button>
   </div>
  </div>
 </article></div>
 <div id="studioInlineAuthoring">
  <section id="studioInlineEditor" hidden>
   <form id="sectionForm" action="/sections" data-course-id="3" data-create-receipt-url="/section-receipts/__INTENT__"
     data-bunny-upload-init="/init" data-bunny-upload-renew="/renew" aria-busy="false">
    <input name="_token" value="browser-test-token"><input name="authoring_request_id">
    <input name="authoring_version" value="${version}"><input name="_method" value="POST" disabled>
    <input name="module_id"><input name="order"><input name="section_type" id="section_type" value="lesson">
    <input name="bunny_video_claim" id="bunny_video_claim"><input id="bunny_video" type="file" data-video-required="true">
    <span id="studioInlineEditorEyebrow"></span><h3 id="studioInlineEditorTitle"></h3>
    <div id="studioInlineFeedback" hidden></div>
    <input name="title_ar" id="title_ar">
    <div id="studioInlineLessonFields"><input name="lesson_description_ar"><input name="lesson_duration_minutes"><input name="is_opened" type="checkbox"></div>
    <div id="studioInlineProjectFields" hidden><textarea name="project_requirements_ar"></textarea><input name="project_submission_types[]" value="text" type="checkbox"><input name="is_graduation_project" type="checkbox"></div>
    <button id="studioInlineDeleteSection" type="button" hidden>حذف</button>
    <button data-inline-editor-close type="button">إغلاق</button>
    <button id="studioInlineSaveSection" type="submit"><span>حفظ المقطع</span></button>
   </form>
  </section>
 </div>
 <div id="courseStudioToast"></div>
</div>
<script>window.RoknCourseVideoUpload={isBusy:()=>false,setSectionContext:()=>true,resetAfterCommit:()=>true};</script>
${Object.keys(scripts).map(file => `<script src="/${file}"></script>`).join('')}
</body></html>`;

const json = (response, status, body) => {
    response.statusCode = status;
    response.setHeader('Content-Type', 'application/json; charset=utf-8');
    response.end(JSON.stringify(body));
};
const requestBody = async request => {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    return Buffer.concat(chunks).toString('utf8');
};
const multipartValue = (body, name) => body.match(new RegExp(`name="${name}"\\r\\n\\r\\n([^\\r\\n]*)`))?.[1] || '';
const authorization = {
    headers: {AuthorizationSignature: 'signature', AuthorizationExpire: '4102444800', VideoId: 'video', LibraryId: '1'},
    authorization_expires_in_seconds: 3600,
    authorization_expires_at: '2099-01-01T00:00:00Z',
};

const server = createServer(async (request, response) => {
    const path = request.url.split('?')[0];
    if (path === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        return response.end(fixture());
    }
    if (scripts[path.slice(1)]) {
        response.setHeader('Content-Type', 'text/javascript; charset=utf-8');
        return response.end(await readFile(resolve(backendRoot, scripts[path.slice(1)])));
    }
    if (request.method === 'POST' && path === '/init') {
        const body = JSON.parse(await requestBody(request));
        initCalls.push(body);
        if (failInitFor.has(body.original_name)) {
            return json(response, 410, {success: false, code: 'bunny_upload_operation_unavailable', message: 'انتهت صلاحية عملية الرفع'});
        }
        if (Number(body.authoring_version) !== version) {
            return json(response, 410, {success: false, code: 'bunny_upload_operation_unavailable', message: 'تغيّرت المسودة منذ فتح الصفحة'});
        }
        return json(response, 200, {success: true, data: {
            ...authorization,
            upload_endpoint: 'https://video.bunnycdn.com/tusupload',
            claim: `claim:${version}:${body.original_name}`,
            claim_expires_at: '2099-01-01T00:00:00Z',
        }});
    }
    if (request.method === 'POST' && path === '/renew') {
        const {claim} = JSON.parse(await requestBody(request));
        renewCalls.push(claim);
        if (String(claim).split(':')[1] !== String(version)) {
            return json(response, 410, {success: false, code: 'bunny_upload_claim_unavailable', message: 'تغيّرت المسودة أثناء الرفع'});
        }
        return json(response, 200, {success: true, data: {...authorization, claim, claim_expires_at: '2099-01-01T00:00:00Z'}});
    }
    if (request.method === 'POST' && path === '/sections') {
        const body = await requestBody(request);
        const fields = Object.fromEntries(['authoring_version', 'module_id', 'order', 'section_type', 'title_ar', 'bunny_video_claim', 'authoring_request_id', 'is_opened']
            .map(name => [name, multipartValue(body, name)]));
        sectionPosts.push(fields);
        if (Number(fields.authoring_version) !== version) {
            return json(response, 409, {success: false, code: 'authoring_conflict', message: 'تغيّرت المسودة'});
        }
        if (fields.bunny_video_claim.split(':')[1] !== String(version)) {
            return json(response, 422, {message: 'invalid', errors: {
                bunny_video_claim: ["تغيّرت المسودة أثناء الرفع\nأعد تحميل الصفحة قبل المتابعة"],
                bunny_video_claim_terminal: ['claim_unavailable'],
            }});
        }
        version += 1;
        const id = nextSectionId++;
        const section = {id, module_id: 7, type: 'lesson', title: fields.title_ar, title_ar: fields.title_ar,
            order: Number(fields.order), update_url: `/sections/${id}`, delete_url: `/sections/${id}`, has_video: true,
            row_label: 'مقطع'};
        sections.push(section);
        return json(response, 200, {success: true, authoring_version: version, section});
    }
    response.statusCode = 404;
    response.end('not found');
});

const corsHeaders = {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Headers': '*',
    'Access-Control-Allow-Methods': 'GET,POST,PATCH,HEAD,OPTIONS',
    'Access-Control-Expose-Headers': 'Location,Upload-Offset,Upload-Length,Tus-Resumable',
    'Tus-Resumable': '1.0.0',
};

const video = (name, bytes = 4) => ({name, mimeType: 'video/mp4', buffer: Buffer.alloc(bytes, 1)});

await new Promise(done => server.listen(0, '127.0.0.1', done));
let browser;
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const context = await browser.newContext();
    const page = await context.newPage();
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    const bunny = {post: [], head: 0, patch: 0};
    let holdPatch = null;
    let releaseHold = null;
    await page.route('https://video.bunnycdn.com/**', async route => {
        const method = route.request().method();
        if (method === 'OPTIONS') return route.fulfill({status: 204, headers: corsHeaders});
        if (method === 'POST') {
            const metadata = route.request().headers()['upload-metadata'] || '';
            const filename = Buffer.from(metadata.match(/filename ([^,]+)/)?.[1] || '', 'base64').toString('utf8');
            bunny.post.push(filename);
            return route.fulfill({status: 201, headers: {...corsHeaders, Location: `/tusupload/${bunny.post.length}`}});
        }
        if (method === 'HEAD') {
            bunny.head += 1;
            return route.fulfill({status: 200, headers: {...corsHeaders, 'Upload-Offset': '0', 'Upload-Length': '4'}});
        }
        if (method === 'PATCH') {
            bunny.patch += 1;
            if (holdPatch && route.request().url().endsWith(`/tusupload/${holdPatch}`)) {
                holdPatch = null;
                await new Promise(resolve => { releaseHold = resolve; });
                return route.fulfill({status: 204, headers: {...corsHeaders, 'Upload-Offset': '4'}}).catch(() => {});
            }
            return route.fulfill({status: 204, headers: {...corsHeaders, 'Upload-Offset': '4'}});
        }
        return route.abort();
    });
    await page.goto(`http://127.0.0.1:${server.address().port}/`);

    const pick = async files => {
        const chooser = page.waitForEvent('filechooser');
        await page.locator('[data-reel-queue-open][data-module-id="7"]').click();
        await (await chooser).setFiles(files);
    };
    const rowStates = () => page.$$eval('[data-reel-queue-item]', rows => rows.map(row => [
        row.querySelector('.studio-reel-queue__name').textContent, row.dataset.state,
    ]));
    const outlineTitles = () => page.$$eval('#module-7-content > .outline-item strong', nodes => nodes.map(node => node.textContent));

    // 1. Three files; the one whose allocation fails does not stop the others.
    failInitFor = new Set(['bad.mp4']);
    await pick([video('02 الريل الثاني.mp4'), video('bad.mp4'), video('01 المقدمة.mp4')]);
    await page.waitForFunction(() => !window.RoknCourseReelQueue.isBusy());
    assert.deepEqual(await rowStates(), [
        ['01 المقدمة', 'done'], ['02 الريل الثاني', 'done'], ['bad', 'failed'],
    ], 'numbered files run in natural order and a failure does not stop the queue');
    assert.deepEqual(initCalls.map(call => [call.original_name, call.authoring_version]), [
        ['01 المقدمة.mp4', 14], ['02 الريل الثاني.mp4', 15], ['bad.mp4', 16],
    ], 'each reel allocates only after the previous reel advanced the draft');
    assert.deepEqual(sectionPosts.map(post => [post.title_ar, post.order, post.authoring_version, post.section_type, post.is_opened]), [
        ['01 المقدمة', '1', '14', 'lesson', '0'], ['02 الريل الثاني', '2', '15', 'lesson', '0'],
    ]);
    assert.deepEqual(await outlineTitles(), ['01 المقدمة', '02 الريل الثاني']);
    assert.equal(await page.locator('#studioReelQueue [data-reel-queue-item]:last-child .studio-reel-queue__error').textContent(),
        'انتهت صلاحية عملية الرفع');
    assert.equal(await page.locator('#studioInlineAuthoring').evaluate(node => node.parentElement.id), 'courseStudio');

    // 2. Retry the failed reel after the cause is gone, with an edited title.
    failInitFor = new Set();
    const badRow = page.locator('[data-reel-queue-item]:last-child');
    await badRow.locator('input').fill('الريل الثالث');
    await badRow.locator('input').dispatchEvent('change');
    await badRow.locator('[data-reel-queue-action="retry"]').click();
    await page.waitForFunction(() => !window.RoknCourseReelQueue.isBusy());
    assert.deepEqual((await rowStates()).at(-1), ['الريل الثالث', 'done']);
    assert.deepEqual(await outlineTitles(), ['01 المقدمة', '02 الريل الثاني', 'الريل الثالث']);
    assert.equal(sectionPosts.at(-1).order, '3');
    assert.equal(await page.evaluate(() => document.getElementById('courseStudio').dataset.authoringVersion), '17');

    // 3. A held upload locks the studio except the queue controls; cancel and
    // retry resume the same Bunny upload instead of allocating again.
    await page.locator('[data-reel-queue-clear]').click();
    holdPatch = bunny.post.length + 1;
    await pick([video('hold.mp4')]);
    while (!releaseHold) await new Promise(done => setTimeout(done, 10));
    assert.equal(await page.locator('[data-reel-queue-open]').isDisabled(), true, 'another batch cannot start mid-queue');
    assert.equal(await page.locator('[data-inline-editor-open="project"]').isDisabled(), true, 'other authoring is locked');
    const holdRow = page.locator('[data-reel-queue-item]').last();
    assert.equal(await holdRow.locator('[data-reel-queue-action="cancel"]').isEnabled(), true);
    await holdRow.locator('[data-reel-queue-action="cancel"]').click();
    releaseHold();
    await page.waitForFunction(() => document.querySelector('[data-reel-queue-item]:last-child')?.dataset.state === 'cancelled');
    assert.equal(await page.locator('[data-reel-queue-open]').isDisabled(), false, 'a stopped queue releases the studio');
    const patchesBeforeRetry = bunny.patch;
    await holdRow.locator('[data-reel-queue-action="retry"]').click();
    await page.waitForFunction(() => !window.RoknCourseReelQueue.isBusy());
    assert.equal(await holdRow.getAttribute('data-state'), 'done');
    assert.equal(bunny.post.filter(name => name === 'hold.mp4').length, 1, 'retry resumes the existing TUS upload');
    assert.equal(initCalls.filter(call => call.original_name === 'hold.mp4').length, 1, 'retry keeps the original claim');
    assert.equal(bunny.head, 1);
    assert.deepEqual(renewCalls, ['claim:17:hold.mp4'], 'a resumed record renews its short-lived authorization');
    assert.equal(bunny.patch, patchesBeforeRetry + 1);
    assert.deepEqual(await outlineTitles(), ['01 المقدمة', '02 الريل الثاني', 'الريل الثالث', 'hold']);
    assert.equal(await page.evaluate(() => Object.keys(localStorage).filter(key => key.startsWith('rokn:bunny-upload:')).length), 0,
        'saved reels leave no resume records behind');

    assert.deepEqual(failures, [], failures.join('\n'));
    console.log('PASS multi-reel queue: sequential allocation and save, failure isolation, retry with edited title, studio lock, cancel and resume');
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
