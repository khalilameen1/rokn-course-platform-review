<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

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
    // This is real HTTP, not PHPUnit: Livewire's unit-test fake disk is not
    // persistent between separate HTTP requests. Keep isolation explicit below.
    'APP_ENV' => 'local', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('browser-fixture-', 2)),
    'APP_URL' => 'http://127.0.0.1:8793', 'APP_DOMAIN' => '127.0.0.1',
    'APP_CONFIG_CACHE' => $storage.'/unused-config.php',
    'LARAVEL_STORAGE_PATH' => $storage,
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $storage.'/database.sqlite',
    'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'rokn_dashboard_browser',
    'SESSION_SECURE_COOKIE' => 'false', 'SESSION_DOMAIN' => '',
    // Filament's notification mailbox must survive its follow-up HTTP request.
    'CACHE_STORE' => 'file', 'CACHE_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
    'SENTRY_LARAVEL_DSN' => '', 'SENTRY_DSN' => '',
    'FILESYSTEM_DRIVER' => 'local', 'PUBLIC_STORAGE_DRIVER' => 'local',
    'SHARED_PUBLIC_STORAGE_PATH' => $storage.'/app/public',
    'FEEDBACK_STORAGE_DRIVER' => 'local', 'FEEDBACK_SHARED_STORAGE_PATH' => $storage.'/app/feedback',
    'SECURITY_QUARANTINE_STORAGE_DRIVER' => 'local', 'SECURITY_QUARANTINE_PATH' => $storage.'/app/quarantine',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with((string) $path, '/storage/')) {
        $portrait = realpath($storage.'/app/public/'.substr($path, strlen('/storage/')));
        if ($portrait && str_starts_with($portrait, realpath($storage.'/app/public').DIRECTORY_SEPARATOR) && is_file($portrait)) {
            header('Content-Type: '.mime_content_type($portrait));
            header('X-Content-Type-Options: nosniff');
            readfile($portrait);

            return true;
        }
    }
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
    echo (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP');
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
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate', ['--force' => true]);
foreach (['admin', 'moderator', 'client'] as $role) {
    $user = User::query()->firstOrNew(['email' => $role.'@dashboard.example.test']);
    $user->forceFill([
        'name_ar' => 'حساب المتصفح '.$role, 'name' => 'Browser '.$role,
        'role' => $role, 'active' => true,
        'password' => Hash::make('Local-browser-fixture-2026'),
        'admin_totp_secret' => 'JBSWY3DPEHPK3PXP', 'admin_totp_confirmed_at' => now(),
        'admin_totp_last_used_step' => null,
    ])->save();
}
echo "Disposable dashboard browser database prepared\n";
