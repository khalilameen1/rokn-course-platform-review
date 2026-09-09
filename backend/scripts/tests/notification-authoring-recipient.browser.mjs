import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createServer} from 'node:http';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.ROKN_PLAYWRIGHT_MODULE || 'playwright');
const backendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
// Render the real notification form and its included production draft script.
// Only the surrounding dashboard layout is omitted; no authoring JS is copied.
const renderPhp = String.raw`
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_DRIVER' => 'array',
    'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'NIGHTWATCH_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rokn-notification-browser-'.bin2hex(random_bytes(8));
$app->useStoragePath($storage);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['view.compiled' => $storage.'/framework/views']);
Illuminate\Support\Facades\File::ensureDirectoryExists(config('view.compiled'));
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::fake();
auth()->setUser((new App\Models\User())->forceFill(['id' => 77, 'name' => 'Local administrator', 'role' => 'admin']));
view()->share('errors', new Illuminate\Support\ViewErrorBag());
$source = str_replace("@extends('admin.layouts.app')", '', file_get_contents('resources/views/admin/notifications/create.blade.php'));
try {
    $pages = [];
    foreach ([0, 101, 202] as $id) {
        $pages[$id] = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"></head><body>'.
            Illuminate\Support\Facades\Blade::render($source."\n@yield('content')", [
                'targetStudent' => $id ? (object) ['id' => $id, 'name' => 'Student '.$id, 'email' => $id.'@example.test'] : null,
                'courseSearch' => '', 'courses' => collect([(object) ['id' => 11, 'name_ar' => 'الكورس']]),
            ], true).'</body></html>';
        view()->flushState();
    }
    echo json_encode($pages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} finally {
    Illuminate\Support\Facades\File::deleteDirectory($storage);
}
`;
const pages = JSON.parse(execFileSync(process.env.ROKN_PHP_BINARY || 'php', [
    ...JSON.parse(process.env.ROKN_PHP_ARGS || '[]'), '-r', renderPhp,
], {cwd: backendRoot, encoding: 'utf8'}));
const server = createServer((request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    if (request.method !== 'GET' || url.pathname !== '/admin/notifications/create') {
        response.writeHead(405); response.end(); return;
    }
    response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
    response.end(pages[url.searchParams.get('user_id') || '0']);
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
let browser;
const failures = [];
try {
    browser = await chromium.launch({channel: 'chrome', headless: true});
    const base = `http://127.0.0.1:${server.address().port}/admin/notifications/create`;
    const check = async (name, operation) => {
        const context = await browser.newContext();
        const page = await context.newPage();
        page.on('dialog', dialog => dialog.accept());
        // Only our local rendered fixtures are reachable; submissions are inspected,
        // never sent to an application or a push provider.
        await page.route('**/*', route => route.request().url().startsWith(base)
            ? route.continue() : route.abort());
        const visit = (id = 0, extra = '') => page.goto(`${base}?user_id=${id}${extra}`);
        const form = page.locator('form[method="POST" i]');
        const title = form.locator('[name="title_ar"]');
        const payload = () => form.evaluate(element => Object.fromEntries(new FormData(element)));
        const compose = async (text = 'رسالة الطالب الأول') => {
            await title.fill(text);
            await form.locator('[name="message_ar"]').fill('تفاصيل تخص صاحب الرسالة');
            await form.locator('[name="send_at"]').fill('2026-10-10T12:30');
            await form.locator('[name="send_at"]').dispatchEvent('change');
        };
        try { await operation({page, visit, form, title, payload, compose}); console.log(`PASS ${name}`); }
        catch (error) { failures.push(`${name}: ${error.message}`); console.log(`FAIL ${name}: ${error.message}`); }
        finally { await context.close(); }
    };

    await check('student A draft cannot replace student B recipient or content', async h => {
        await h.visit(101); await h.compose();
        await h.visit(202);
        assert.match(await h.form.innerText(), /Student 202/);
        assert.equal((await h.payload()).user_id, '202');
        assert.equal(await h.title.inputValue(), '');
    });
    await check('individual content never becomes a broadcast draft', async h => {
        await h.visit(101); await h.compose(); await h.visit();
        assert.equal(await h.title.inputValue(), '');
        assert.equal((await h.payload()).user_id, undefined);
        assert.equal((await h.payload()).audience, 'all');
    });
    await check('broadcast content and audience never overwrite an individual form', async h => {
        await h.visit(); await h.compose('رسالة جماعية');
        await h.form.locator('[name="course_id"]').selectOption('11');
        await h.form.locator('[name="audience"]').selectOption('enrolled');
        await h.visit(202);
        assert.equal(await h.title.inputValue(), '');
        assert.equal((await h.payload()).user_id, '202');
        assert.equal((await h.payload()).audience, 'all');
    });
    await check('returning to each student restores only that student draft', async h => {
        await h.visit(101); await h.compose('مسودة الأول');
        await h.visit(202); await h.compose('مسودة الثاني');
        await h.visit(101);
        assert.equal(await h.title.inputValue(), 'مسودة الأول');
        assert.equal((await h.payload()).user_id, '101');
        await h.visit(202);
        assert.equal(await h.title.inputValue(), 'مسودة الثاني');
        assert.equal((await h.payload()).user_id, '202');
    });
    await check('same-recipient reload and course search preserve body and schedule', async h => {
        await h.visit(101); await h.compose();
        await h.page.reload();
        const restored = await h.payload();
        assert.equal(restored.title_ar, 'رسالة الطالب الأول');
        assert.equal(restored.message_ar, 'تفاصيل تخص صاحب الرسالة');
        assert.equal(restored.send_at, '2026-10-10T12:30');
        await h.visit(101, '&course_search=11');
        const searched = await h.payload();
        assert.equal(searched.user_id, '101');
        assert.equal(searched.title_ar, restored.title_ar);
        assert.equal(searched.send_at, restored.send_at);
        assert.equal(searched.authoring_request_id, restored.authoring_request_id);
    });
} finally {
    await browser?.close();
    await new Promise(done => server.close(done));
}
assert.equal(failures.length, 0, failures.join('\n'));
