<?php

declare(strict_types=1);

// Offline QA only: these are sample pictures, never issued credentials.
use App\Models\Certificate;
use App\Services\CertificateArtworkRenderer;
use App\Services\CertificatePdfService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Certificate preview is a CLI-only QA command.');
}
$environment = getenv('APP_ENV');
if ($environment !== false && !in_array($environment, ['local', 'testing'], true)) {
    throw new RuntimeException('Certificate preview is only available in local/testing environments.');
}

// Do not load a developer's .env or a production config cache. The command
// needs bundled assets and the local renderer only, never service credentials.
$previewEnvironment = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_URL' => 'https://preview.invalid',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'CACHE_DRIVER' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'null',
    'NIGHTWATCH_ENABLED' => 'false',
    'APP_CONFIG_CACHE' => sys_get_temp_dir().'/rokn-certificate-preview-config-'.bin2hex(random_bytes(12)).'.php',
];
foreach ($previewEnvironment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.certificate-preview-disabled');
$app->make(Kernel::class)->bootstrap();
DB::connection()->beforeExecuting(static function (): void {
    throw new LogicException('Certificate previews must not execute SQL.');
});

$directory = storage_path('app/certificate-artwork');
File::ensureDirectoryExists($directory);
config([
    'certificate.disk' => 'certificate_preview',
    'filesystems.disks.certificate_preview' => [
        'driver' => 'local', 'root' => $directory, 'throw' => true,
    ],
]);
$renderer = app(CertificateArtworkRenderer::class);
$examples = [
    ['key' => 'theory', 'learner' => 'أحمد حسن', 'course' => 'مبادئ التسويق', 'projects' => false],
    ['key' => 'practical', 'learner' => 'أحمد حسن', 'course' => 'مونتاج الريلز', 'projects' => true],
    ['key' => 'long-name-qa', 'learner' => 'عبدالرحمن محمد عبدالعزيز إبراهيم', 'course' => 'تصميم الإعلانات التجارية لمنصات التواصل الاجتماعي', 'projects' => true],
];
$manifest = [
    'sample_only' => true,
    'issued' => false,
    'renderer' => CertificateArtworkRenderer::VERSION,
    'files' => [],
];

foreach ($examples as $example) {
    $destination = [
        'type' => $example['projects'] ? 'portfolio' : 'certificate',
        'url' => 'https://preview.invalid/'.($example['projects']
            ? '@rokn-000000000000000000000000'
            : 'c/00000000-0000-4000-8000-000000000000'),
        'title' => $example['projects'] ? 'شاهد أعماله' : 'تحقق من الشهادة',
        'hint' => 'معاينة فقط',
    ];
    $certificate = (new Certificate())->forceFill([
        'public_id' => '00000000-0000-4000-8000-000000000000',
        'course_id' => 1,
        'status' => 'active',
        'verification_level' => 'completion',
        'holder_name' => $example['learner'],
        'course_name' => $example['course'],
        'certificate_design_version' => CertificateArtworkRenderer::VERSION,
        'certificate_text_template_key' => $example['projects'] ? 'projects' : 'knowledge',
        'certificate_text' => 'أتم كورس',
        'certificate_completion_text' => $example['projects'] ? 'واجتاز مشروعاته' : '',
        'certificate_curriculum_revision' => 1,
        'certificate_project_evidence' => $example['projects'] ? [['project_id' => 1, 'submission_id' => 1]] : [],
        'certificate_qr_snapshot' => $destination,
        'generated_at' => '2026-09-15 12:00:00',
    ]);
    $snapshot = $certificate->getAttributes();
    $png = $renderer->render($certificate, $destination);
    if ($certificate->exists || $certificate->getAttributes() !== $snapshot) {
        throw new RuntimeException('Preview rendering modified the sample credential.');
    }
    $size = getimagesizefromstring($png);
    if (!is_array($size) || $size[0] !== 2800 || $size[1] !== 1900 || $size[2] !== IMAGETYPE_PNG) {
        throw new RuntimeException('The production renderer returned unexpected artwork dimensions.');
    }
    $filename = 'rokn-certificate-'.$example['key'].'.png';
    File::put($directory.'/'.$filename, $png);
    // Exercise the production delivery service on the same sample pixels.
    // The unsaved model stays sample-only; no credential row is created.
    $certificate->image_path = $filename;
    $pdf = app(CertificatePdfService::class)->download($certificate);
    File::put($directory.'/rokn-certificate-'.$example['key'].'.pdf', $pdf->getContent());
    $manifest['files'][] = [
        'filename' => $filename,
        'width' => $size[0],
        'height' => $size[1],
        'sha256' => hash('sha256', $png),
        'qr_destination' => $destination['url'],
        'completion_text' => $certificate->certificate_completion_text,
    ];
}
File::put($directory.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, "Rendered three sample-only production certificate PNGs to storage/app/certificate-artwork. No credentials were issued.\n");
