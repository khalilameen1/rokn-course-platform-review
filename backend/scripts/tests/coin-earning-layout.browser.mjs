import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {dirname, extname, resolve, sep} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const publicRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../public');
const projectRoot = resolve(publicRoot, '..');
const viewSource = await readFile(resolve(projectRoot, 'resources/views/admin/coin_earning_methods/index.blade.php'), 'utf8');
assert.match(viewSource, /class="reward-rule-card"/);
assert.match(viewSource, /form="reward-rule-\{\{ \$rule->id \}\}"/);
assert.doesNotMatch(viewSource, /reward-rules\.update[\s\S]{0,180}h-100/);
const fields = ['الاسم بالعربية', 'الاسم بالإنجليزية', 'العملات', 'الفترة والشرط', 'حد يومي', 'حد 30 يومًا'];
const fieldMarkup = fields.map((label, index) => `
    <div class="reward-rule-field"><label>${label}</label><input class="form-control" value="${index < 2 ? 'إتمام أول كورس تعليمي' : 120}"></div>`).join('');
const fixture = `<!doctype html><html dir="rtl" lang="ar"><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/admin/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/admin/assets/css/custom-global.css">
<link rel="stylesheet" href="/admin/assets/css/admin-learning-views.css">
<script src="/admin/assets/js/reward-rule-form.js" defer></script>
<link rel="stylesheet" href="/admin/assets/css/admin-shell.css">
<style>.fixture-content{margin-right:280px;padding:20px}@media(max-width:768px){.fixture-content{margin-right:0;padding:12px}}</style>
<body><main class="fixture-content admin-learning admin-learning--coins admin-page">
 <div class="admin-page__header"><div class="admin-page__heading"><h1 class="admin-page__title">طرق ربح العملات</h1></div></div>
 <section class="card coin-panel"><div class="card-body p-4">
  <form id="reward-rule-create">
   <select id="create-reward-event" name="event_key">
    <option value="">اختر الحدث</option><option value="welcome_bonus">هدية التسجيل</option><option value="study_session">جلسة دراسة</option><option value="streak_milestone">الاستمرارية</option><option value="first_project_passed">أول مشروع</option>
   </select>
   <div data-reward-field="interval"><label for="create-reward-interval">الفترة</label><input id="create-reward-interval" name="interval_count" type="number" min="1" value="1" required></div>
   <div data-reward-field="daily"><label for="create-reward-daily">الحد اليومي</label><input id="create-reward-daily" name="daily_cap" type="number" min="0" value="7"></div>
   <div data-reward-field="rolling"><label for="create-reward-rolling">الحد خلال 30 يومًا</label><input id="create-reward-rolling" name="rolling_30_day_cap" type="number" min="0" value="30"></div>
  </form>
  <div class="row"><div class="col-12 mb-3"><div class="reward-rule-card">
   <form id="reward-rule-7" class="reward-rule-form"><div class="reward-rule-fields">${fieldMarkup}</div></form>
   <div class="reward-rule-actions"><button type="submit" form="reward-rule-7" class="btn btn-sm btn-outline-primary">حفظ</button><form><button class="btn btn-sm btn-outline-danger">حذف القاعدة</button></form></div>
  </div></div><div class="col-12"><h2 id="next-rule">المكافأة التالية</h2></div></div>
 </div></section>
 <div class="row"><div class="col-md-6 col-lg-4"><div class="method-card">
  <div class="method-card__head"><h5 class="method-card__title">تابع منصة ركن على إحدى الشبكات الاجتماعية</h5><div class="method-card__badges"><span class="admin-status admin-status--active">نشط</span><span class="admin-status admin-status--muted">مرة واحدة</span></div></div>
 </div></div></div>
</main></body></html>`;

const server = createServer(async (request, response) => {
    if (request.url === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        return response.end(fixture);
    }
    const path = resolve(publicRoot, '.' + request.url.split('?')[0]);
    if (!path.startsWith(publicRoot + sep)) { response.writeHead(403); return response.end(); }
    try {
        response.setHeader('Content-Type', extname(path) === '.css' ? 'text/css' : (extname(path) === '.js' ? 'text/javascript' : 'application/octet-stream'));
        response.end(await readFile(path));
    } catch { response.writeHead(404); response.end(); }
});

await new Promise(ready => server.listen(0, '127.0.0.1', ready));
let browser;
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const page = await browser.newPage();
    const fieldState = async eventKey => {
        await page.selectOption('#create-reward-event', eventKey);
        return page.locator('#reward-rule-create').evaluate(form => {
            const state = field => {
                const wrapper = form.querySelector(`[data-reward-field="${field}"]`);
                const input = wrapper.querySelector('input');
                return {
                    hidden: wrapper.hidden,
                    disabled: input.disabled,
                    required: input.required,
                    min: input.min,
                    value: input.value,
                    label: wrapper.querySelector('label').textContent.trim(),
                };
            };
            return {
                interval: state('interval'),
                daily: state('daily'),
                rolling: state('rolling'),
                keys: [...new FormData(form).keys()],
            };
        });
    };
    for (const width of [360, 768, 1024, 1440]) {
        await page.setViewportSize({width, height: 900});
        await page.goto(`http://127.0.0.1:${server.address().port}`);

        const fieldBoxes = await page.locator('.reward-rule-field').evaluateAll(elements => elements.map(element => element.getBoundingClientRect().toJSON()));
        const minimumFieldWidth = width <= 360 ? 220 : 260;
        assert.ok(fieldBoxes.every(box => box.width >= minimumFieldWidth), `Reward fields are too narrow at ${width}px`);

        const card = await page.locator('.reward-rule-card').boundingBox();
        const deleteButton = await page.getByRole('button', {name: 'حذف القاعدة'}).boundingBox();
        const nextRule = await page.locator('#next-rule').boundingBox();
        assert.ok(deleteButton.y + deleteButton.height <= card.y + card.height + 1, `Delete action escapes its card at ${width}px`);
        assert.ok(deleteButton.y + deleteButton.height < nextRule.y, `Delete action overlaps the next rule at ${width}px`);
        assert.ok(card.x >= 0 && card.x + card.width <= width, `Reward card escapes the viewport at ${width}px`);

        const title = await page.locator('.method-card__title').boundingBox();
        const badges = await page.locator('.method-card__badges').boundingBox();
        const horizontallySeparate = title.x + title.width <= badges.x || badges.x + badges.width <= title.x;
        const verticallySeparate = title.y + title.height <= badges.y || badges.y + badges.height <= title.y;
        assert.ok(horizontallySeparate || verticallySeparate, `Method badges overlap its title at ${width}px`);
    }

    let state = await fieldState('welcome_bonus');
    assert.deepEqual(state.keys, ['event_key']);
    assert.ok(state.interval.hidden && state.interval.disabled);
    assert.ok(state.daily.hidden && state.daily.disabled);
    assert.ok(state.rolling.hidden && state.rolling.disabled);

    state = await fieldState('study_session');
    assert.equal(state.interval.hidden, false);
    assert.equal(state.interval.min, '1');
    assert.equal(state.interval.label, 'مدة الدراسة بالدقائق');
    assert.equal(state.daily.hidden, false);
    assert.equal(state.rolling.hidden, false);
    assert.equal(state.rolling.required, true);
    assert.deepEqual(state.keys, ['event_key', 'interval_count', 'daily_cap', 'rolling_30_day_cap']);

    state = await fieldState('streak_milestone');
    assert.equal(state.interval.hidden, false);
    assert.equal(state.interval.min, '2');
    assert.equal(state.interval.value, '2');
    assert.equal(state.interval.label, 'مدة الاستمرارية بالأيام');
    assert.ok(state.daily.hidden && state.daily.disabled);
    assert.equal(state.rolling.hidden, false);
    assert.deepEqual(state.keys, ['event_key', 'interval_count', 'rolling_30_day_cap']);

    state = await fieldState('first_project_passed');
    assert.ok(state.interval.hidden && state.interval.disabled);
    assert.ok(state.daily.hidden && state.daily.disabled);
    assert.equal(state.rolling.hidden, false);
    assert.equal(state.rolling.required, false);
    assert.equal(state.rolling.label, 'سقف مكافأة أول مشروع');
    assert.deepEqual(state.keys, ['event_key', 'rolling_30_day_cap']);

    await page.locator('#reward-rule-create').evaluate(form => {
        form.elements.interval_count.value = '25';
        form.elements.daily_cap.disabled = false;
        form.elements.daily_cap.value = '11';
        form.elements.rolling_30_day_cap.value = '90';
    });
    state = await fieldState('welcome_bonus');
    assert.deepEqual(state.keys, ['event_key'], 'Disabled values leaked after changing the event');
    console.log('PASS coin earning layout at 360, 768, 1024 and 1440');
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
