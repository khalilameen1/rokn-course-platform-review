<?php

declare(strict_types=1);

/** Disposable local HTTP harness. No auth bypass routes; tests use password + TOTP. */
if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    exit(1);
}
if (PHP_SAPI === 'cli-server' && ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$root = dirname(__DIR__);
$storage = $root.'/storage/framework/dashboard-browser';
foreach (['', '/framework/views', '/framework/sessions', '/framework/cache/data', '/logs', '/app/public'] as $directory) {
    if (! is_dir($storage.$directory)) {
        mkdir($storage.$directory, 0777, true);
    }
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('browser-fixture-', 2)),
    'APP_URL' => 'http://127.0.0.1:8793', 'APP_DOMAIN' => '127.0.0.1',
    'APP_CONFIG_CACHE' => $storage.'/unused-config.php',
    'LARAVEL_STORAGE_PATH' => $storage,
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $storage.'/database.sqlite',
    'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'rokn_dashboard_browser',
    'SESSION_SECURE_COOKIE' => 'false', 'SESSION_DOMAIN' => '',
    'CACHE_STORE' => 'array', 'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
    'SENTRY_LARAVEL_DSN' => '', 'SENTRY_DSN' => '',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $asset = realpath($root.'/public/'.ltrim((string) $path, '/'));
    $public = realpath($root.'/public');
    if ($asset && str_starts_with($asset, $public.DIRECTORY_SEPARATOR) && is_file($asset)
        && strtolower(pathinfo($asset, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }
    require $root.'/public/index.php';

    return true;
}
require $root.'/vendor/autoload.php';
if (($argv[1] ?? '') === '--otp') {
    echo (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP');
    exit;
}
if (($argv[1] ?? '') !== '--prepare') {
    fwrite(STDERR, "Use --prepare or --otp, or serve this router on localhost\n");
    exit(1);
}
if (! is_file($storage.'/database.sqlite')) {
    touch($storage.'/database.sqlite');
}
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
foreach (['admin', 'moderator', 'client'] as $role) {
    $user = App\Models\User::query()->firstOrNew(['email' => $role.'@dashboard.example.test']);
    $user->forceFill([
        'name_ar' => 'حساب المتصفح '.$role, 'name' => 'Browser '.$role,
        'role' => $role, 'active' => true,
        'password' => Illuminate\Support\Facades\Hash::make('Local-browser-fixture-2026'),
        'admin_totp_secret' => 'JBSWY3DPEHPK3PXP', 'admin_totp_confirmed_at' => now(),
        'admin_totp_last_used_step' => null,
    ])->save();
}
echo "Disposable dashboard browser database prepared\n";
