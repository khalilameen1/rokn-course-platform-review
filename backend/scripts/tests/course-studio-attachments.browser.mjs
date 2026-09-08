import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const backendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const scripts = ['request.js', 'course-studio-core.js', 'course-studio-attachments.js', 'course-studio.js'];
// Exercise the actual shared form and its browser behaviour, not a second
// handwritten source/target editor that could drift from the Blade partial.
const formMarkup = (await readFile(resolve(backendRoot, 'resources/views/admin/course-pdfs/partials/form.blade.php'), 'utf8'))
    .replace(/@error\([^)]*\)[\s\S]*?@enderror/g, '')
    .replace(/\{\{[\s\S]*?\}\}/g, expression => expression.includes('allowed_upload_extensions')
        ? '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.webp,.zip'
        : '1');
const sharedScript = await readFile(resolve(backendRoot, 'resources/views/admin/course-pdfs/partials/form-scripts.blade.php'), 'utf8');
let version = 7;
let nextId = 3;
let validationFailure = false;
const requests = [];
const row = (id, attributes = {}) => ({
    id, title: `مرفق ${id}`, title_en: '', description: '', description_en: '',
    source_type: 'upload', platform: 'mobile', external_url: null,
    formatted_file_size: '2 KB', is_active: true, order: id,
    preview_url: `/pdfs/${id}/preview`, update_url: `/pdfs/${id}`,
    toggle_url: `/pdfs/${id}/toggle`, delete_url: `/pdfs/${id}`,
    ...attributes,
});
const records = new Map([
    [1, row(1)],
    [2, row(2, {source_type: 'external', platform: 'computer', external_url: 'https://www.dropbox.com/s/example/file.zip?dl=1', formatted_file_size: null})],
]);
const fixture = () => `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><meta name="csrf-token" content="fixture-token"><link rel="stylesheet" href="/course-studio.css"><style>body{margin:0}#courseStudio{max-width:960px;margin:auto}input,select,textarea{box-sizing:border-box}.form-control{padding:10px}.form-text{display:block}[hidden]{display:none!important}</style></head><body>
<div id="courseStudio" class="course-studio" data-course-id="3" data-actor-id="42" data-can-author="1" data-authoring-version="${version}">
 <script type="application/json" id="courseAuthoringGraph">{}</script>
 <button type="button" data-studio-attachments-open>المرفقات</button>
 <section id="studioCourseAttachments" class="studio-attachments-panel" hidden>
  <button type="button" data-studio-attachments-close>إغلاق</button>
  <script type="application/json" id="coursePdfAuthoringGraph">${JSON.stringify({store_url: '/pdfs', reorder_url: '/pdfs/reorder', pdfs: [...records.values()]})}</script>
  <div class="studio-attachments-panel__body">
   <div id="studioCoursePdfList" class="studio-attachments-list">${[...records.values()].map(pdf => `<article class="studio-attachment" data-pdf-id="${pdf.id}"><button data-studio-pdf-edit="${pdf.id}">تعديل</button><button data-studio-pdf-toggle="${pdf.id}">ظهور</button></article>`).join('')}</div>
   <div id="studioCoursePdfEmpty" hidden>لا توجد مرفقات</div><button type="button" data-studio-pdf-add>إضافة</button>
   <div id="studioCoursePdfEditor" class="studio-attachment-editor" hidden><form id="coursePdfForm" action="/pdfs" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="fixture-token"><input type="hidden" name="authoring_request_id"><input type="hidden" name="authoring_version" value="${version}"><input type="hidden" name="_method" value="POST" disabled>
    <div data-pdf-feedback hidden></div>${formMarkup}<button type="button" data-studio-pdf-delete hidden>حذف</button>
   </form></div>
  </div>
 </section><div id="courseStudioToast"></div>
</div>
<script>window.Sortable=class{constructor(element,options){window.attachmentSortable={element,options};}};</script>
${scripts.map(name => `<script src="/${name}"></script>`).join('')}
${sharedScript}
</body></html>`;

const json = (response, value, status = 200) => {
    response.writeHead(status, {'Content-Type': 'application/json; charset=utf-8'});
    response.end(JSON.stringify(value));
};
const server = createServer(async (request, response) => {
    try {
        const path = request.url.split('?')[0];
        if (path === '/') {
            response.setHeader('Content-Type', 'text/html; charset=utf-8');
            return response.end(fixture());
        }
        if (scripts.includes(path.slice(1))) {
            response.setHeader('Content-Type', 'text/javascript; charset=utf-8');
            return response.end(await readFile(resolve(backendRoot, 'public/admin/assets/js', path.slice(1))));
        }
        if (path === '/course-studio.css') {
            response.setHeader('Content-Type', 'text/css');
            return response.end(await readFile(resolve(backendRoot, 'public/admin/assets/css/course-studio.css')));
        }
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const body = Buffer.concat(chunks);
        if (path === '/pdfs/reorder') {
            const {order} = JSON.parse(body);
            order.forEach((id, index) => records.set(id, {...records.get(id), order: index + 1}));
            return json(response, {success: true, authoring_version: ++version, pdfs: order.map(id => records.get(id))});
        }
        const id = Number(path.match(/^\/pdfs\/(\d+)/)?.[1]);
        if (path.endsWith('/toggle')) {
            const updated = {...records.get(id), is_active: !records.get(id).is_active};
            records.set(id, updated);
            return json(response, {success: true, authoring_version: ++version, pdf: updated});
        }
        if (request.method === 'DELETE') {
            records.delete(id);
            return json(response, {success: true, authoring_version: ++version, pdf: {id, deleted: true}});
        }
        if (request.method !== 'POST') return json(response, {}, 404);
        const data = await new Request('http://localhost/fixture', {method: 'POST', headers: request.headers, body}).formData();
        const fields = Object.fromEntries([...data.entries()].map(([name, value]) => [name, typeof value === 'string' ? value : {name: value.name, size: value.size}]));
        requests.push(fields);
        if (validationFailure) {
            validationFailure = false;
            return json(response, {errors: {external_url: ['اختر رابط ملف متاح للتنزيل']}}, 422);
        }
        const source = String(data.get('source_type'));
        const upload = data.get('pdf_file');
        const recordId = id || nextId++;
        const updated = row(recordId, {
            ...records.get(recordId), title: String(data.get('title')), title_en: String(data.get('title_en') || ''),
            description: String(data.get('description') || ''), description_en: String(data.get('description_en') || ''),
            source_type: source, platform: String(data.get('platform')), external_url: source === 'external' ? String(data.get('external_url')) : null,
            formatted_file_size: source === 'external' ? null : upload?.size ? `${upload.size} bytes` : records.get(recordId)?.formatted_file_size,
            is_active: data.getAll('is_active').at(-1) === '1', order: Number(data.get('order')),
        });
        records.set(recordId, updated);
        return json(response, {success: true, authoring_version: ++version, pdf: updated});
    } catch (error) {
        json(response, {message: error.message}, 500);
    }
});
await new Promise(resolveListen => server.listen(0, '127.0.0.1', resolveListen));
let browser;
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const page = await browser.newPage();
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.goto(`http://127.0.0.1:${server.address().port}`);
    const source = page.locator('#attachmentSource');
    const target = page.locator('#attachmentPlatform');
    const url = page.locator('#attachmentExternalUrl');
    const file = page.locator('#pdfFile');
    const submit = page.locator('#coursePdfForm button[type="submit"]');
    const title = page.locator('#coursePdfForm [name="title"]');
    const waitIdle = () => page.waitForFunction(() => document.getElementById('coursePdfForm').getAttribute('aria-busy') !== 'true' && !document.getElementById('courseStudio').inert);
    const save = async () => { await submit.click(); await waitIdle(); };
    const edit = async id => { await page.locator(`[data-studio-pdf-edit="${id}"]`).click(); };
    const pdfFile = {name: 'guide.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.7 attachment fixture')};

    await page.locator('[data-studio-attachments-open]').click();
    await page.locator('[data-studio-pdf-add]').click();
    assert.equal(await source.inputValue(), 'upload');
    assert.equal(await target.inputValue(), 'mobile');
    assert.equal(await file.getAttribute('required'), '');
    assert.equal(await url.isDisabled(), true);
    await title.fill('ملفات التطبيق');
    await file.setInputFiles(pdfFile);
    await source.selectOption('external');
    assert.equal(await file.evaluate(input => input.files.length), 0, 'switching to link must discard a selected stale upload');
    assert.equal(await file.isDisabled(), true);
    assert.equal(await url.getAttribute('maxlength'), '2000');
    const signedUrl = 'https://files.example.com/project.zip?token=A%2Fb%3D&download=1';
    await url.fill(signedUrl);
    await target.selectOption('computer');
    await save();
    assert.equal(records.get(3).source_type, 'external');
    assert.equal(records.get(3).platform, 'computer');
    assert.equal(requests.at(-1).external_url, signedUrl, 'authoring must preserve signed query bytes');
    assert.equal('pdf_file' in requests.at(-1), false);
    const externalCard = page.locator('[data-pdf-id="3"]');
    assert.match(await externalCard.locator('small').textContent(), /رابط · للكمبيوتر · نسخ الرابط/);
    assert.equal(await externalCard.locator('.fa-file-pdf-o').count(), 0);
    assert.doesNotMatch(await externalCard.textContent(), /0 bytes|null|undefined/);

    await edit(3);
    assert.equal(await source.inputValue(), 'external');
    assert.equal(await target.inputValue(), 'computer');
    assert.equal(await url.inputValue(), signedUrl);
    await title.fill('رابط معدّل');
    validationFailure = true;
    await save();
    assert.match(await page.locator('[data-pdf-feedback]').textContent(), /اختر رابط ملف/);
    assert.equal(await title.inputValue(), 'رابط معدّل');
    assert.equal(await url.inputValue(), signedUrl);
    assert.equal(await target.inputValue(), 'computer');
    await save();

    await edit(3);
    await source.selectOption('upload');
    assert.equal(await url.inputValue(), '');
    assert.equal(await url.isDisabled(), true);
    assert.equal(await file.getAttribute('required'), '', 'an external row has no existing uploaded file');
    const beforeInvalid = requests.length;
    await submit.click();
    assert.equal(requests.length, beforeInvalid);
    await file.setInputFiles({name: 'page.html', mimeType: 'text/html', buffer: Buffer.from('not a PDF')});
    assert.equal(await file.evaluate(input => input.checkValidity()), false);
    await file.evaluate(input => {
        const transfer = new DataTransfer();
        transfer.items.add(new File([new Uint8Array(50 * 1024 * 1024 + 1)], 'too-large.zip', {type: 'application/zip'}));
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', {bubbles: true}));
    });
    assert.match(await file.evaluate(input => input.validationMessage), /50/);
    assert.equal(await file.evaluate(input => input.checkValidity()), false);
    await file.setInputFiles(pdfFile);
    await target.selectOption('mobile');
    await save();
    assert.equal(records.get(3).source_type, 'upload');
    assert.equal(records.get(3).platform, 'mobile');
    assert.equal('external_url' in requests.at(-1), false);
    assert.equal(requests.at(-1).pdf_file.name, 'guide.pdf');

    await edit(1);
    assert.equal(await file.getAttribute('required'), null, 'metadata editing keeps the existing uploaded file');
    await target.selectOption('computer');
    await save();
    assert.equal(records.get(1).platform, 'computer', 'source and target must remain independent');
    assert.equal(records.get(1).formatted_file_size, '2 KB');

    await edit(3);
    await file.setInputFiles({name: 'replacement.zip', mimeType: 'application/zip', buffer: Buffer.from('ZIP fixture payload')});
    await save();
    assert.equal(requests.at(-1).pdf_file.name, 'replacement.zip');
    await edit(3);
    await file.setInputFiles({name: 'reference.png', mimeType: 'image/png', buffer: Buffer.from('image fixture payload')});
    await save();
    assert.equal(requests.at(-1).pdf_file.name, 'reference.png');
    await edit(3);
    await source.selectOption('external');
    await url.fill('https://drive.google.com/file/d/public-file/view?usp=sharing');
    assert.equal(await target.inputValue(), 'mobile');
    assert.match(await page.locator('[data-attachment-delivery-hint]').textContent(), /دون تسجيل دخول/);
    await save();

    await edit(3);
    await title.fill('عنوان محفوظ بعد الإخفاء');
    await page.locator('[data-studio-pdf-toggle="3"]').click();
    await waitIdle();
    assert.equal(await page.locator('#isActive').isChecked(), false, 'hiding an open editor row must not be undone by its next save');
    assert.equal(await source.inputValue(), 'external');
    await page.evaluate(() => {
        const {element, options} = window.attachmentSortable;
        element.prepend(element.querySelector('[data-pdf-id="3"]'));
        options.onEnd({oldIndex: 2, newIndex: 0});
    });
    await waitIdle();
    assert.equal(await page.locator('#coursePdfForm [name="order"]').inputValue(), '1', 'saving the open editor must preserve a new drag order');
    await save();
    assert.equal(records.get(3).is_active, false);
    assert.equal(records.get(3).order, 1);
    assert.equal(records.get(3).source_type, 'external');
    assert.equal(records.get(3).platform, 'mobile');

    await edit(3);
    for (const width of [360, 1024]) {
        await page.setViewportSize({width, height: 900});
        const bounds = await page.locator('.attachment-source-controls').evaluate(element => {
            const container = element.getBoundingClientRect();
            return [...element.querySelectorAll('select')].map(select => ({left: select.getBoundingClientRect().left, right: select.getBoundingClientRect().right, start: container.left, end: container.right}));
        });
        assert.ok(bounds.every(value => value.left >= value.start - 1 && value.right <= value.end + 1), `source and target choices must fit at ${width}px`);
    }
    await page.locator('[data-studio-pdf-delete]').click();
    await waitIdle();
    assert.equal(records.has(3), false);
    await page.locator('[data-studio-pdf-add]').click();
    assert.equal(await source.inputValue(), 'upload');
    assert.equal(await target.inputValue(), 'mobile');
    assert.equal(await url.inputValue(), '');
    assert.equal(await file.evaluate(input => input.files.length), 0);
    await source.selectOption('external');
    await url.fill('https://files.example.com/discard.zip');
    await page.locator('[data-studio-pdf-cancel]').click();
    await page.locator('[data-studio-pdf-add]').click();
    assert.equal(await source.inputValue(), 'upload');
    assert.equal(await url.inputValue(), '');
    assert.deepEqual(pageErrors, []);
    console.log('PASS attachment authoring: source/target roundtrip, file replacement, validation recovery, visibility, reorder, deletion and narrow layout');
} finally {
    await browser?.close();
    await new Promise(resolveClose => server.close(resolveClose));
}
