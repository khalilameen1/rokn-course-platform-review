<?php

declare(strict_types=1);
use App\Models\AdminAuditLog;
use App\Models\AdminNotification;
use App\Models\AiInputAttachment;
use App\Models\ApiToken;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\Contact;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\FeedbackReport;
use App\Models\NotificationCampaign;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentReconciliationFinding;
use App\Models\PortfolioItem;
use App\Models\Project;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ProductionCourseCodeSchema;

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
    // Schema assembly uses the test runner's mutable-schema policy. Real HTTP
    // still runs as local so FilePond uses persistent, not fake test uploads.
    'APP_ENV' => PHP_SAPI === 'cli' ? 'testing' : 'local', 'APP_DEBUG' => 'false',
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
try {
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    // Every run owns a fresh disposable database. Never reset a financial receipt
    // through a model update: live settlement facts deliberately remain write-once.
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'sqlite'
        || realpath($connection->getDatabaseName()) !== realpath($storage.'/database.sqlite')) {
        throw new RuntimeException('Dashboard browser preparation requires its own isolated SQLite database.');
    }
    Model::clearBootedModels();
    Artisan::call('migrate:fresh', ['--force' => true]);
    require_once $root.'/tests/Support/ProductionCourseCodeSchema.php';
    ProductionCourseCodeSchema::applySqliteBridge();
    Model::clearBootedModels();
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
    $student = User::query()->where('email', 'client@dashboard.example.test')->firstOrFail();
    User::query()->forceCreate(['name_ar' => 'مسؤول استعادة الدخول', 'email' => 'browser-recovery@dashboard.example.test', 'role' => 'moderator', 'active' => true,
        'password' => Hash::make('Recovery-fixture-password-2026'), 'admin_totp_secret' => 'JBSWY3DPEHPK3PXP', 'admin_totp_confirmed_at' => now()]);
    BunnyVideoCleanupCandidate::query()->create(['video_guid' => 'browser-cleanup-video', 'reason' => 'superseded_video', 'requires_review' => true, 'eligible_after' => now()->addDay()]);
    $student->forceFill(['portfolio_slug' => 'browser-selected-work'])->save();
    $portfolioItem = PortfolioItem::query()->create(['user_id' => $student->id, 'title' => 'عمل المتصفح المختار', 'is_public' => true]);
    $portfolioItem->mediaFiles()->create(['file_path' => 'portfolio/browser-fixture.jpg', 'file_type' => 'image', 'content_sha256' => str_repeat('a', 64)]);
    $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس المتصفح', 'is_coming_soon' => false, 'is_catalog_visible' => true, 'authoring_version' => 1]);
    $project = Project::query()->create(['requirements_text' => 'نفذ تطبيقًا عمليًا', 'ai_prompt' => 'قيّم النتيجة', 'passing_score' => 50]);
    $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة الأولى', 'order' => 1]);
    CourseSection::query()->create(['course_id' => $course->id, 'module_id' => $module->id, 'title' => 'مشروع المتصفح',
        'section_type' => 'project', 'sectionable_type' => Project::class, 'sectionable_id' => $project->id, 'order' => 1]);
    ProjectSubmission::query()->create(['public_id' => (string) Str::uuid(), 'user_id' => $student->id, 'project_id' => $project->id,
        'idempotency_key' => 'browser-project', 'submission_text' => 'ناتج المشروع من المتصفح', 'effort_status' => 'valid', 'review_status' => 'pending', 'submitted_at' => now()]);
    $reportProject = Project::query()->create(['requirements_text' => 'نفذ حملة إعلانية', 'passing_score' => 50]);
    $reportSection = CourseSection::query()->create(['course_id' => $course->id, 'module_id' => $module->id, 'title' => 'مشروع التقرير',
        'section_type' => 'project', 'sectionable_type' => Project::class, 'sectionable_id' => $reportProject->id, 'order' => 2]);
    $enrollment = CourseEnrollment::query()->forceCreate(['user_id' => $student->id, 'course_id' => $course->id, 'is_active' => true, 'enrolled_at' => now()]);
    $terms = ['project_feedback_level' => 'report', 'project_feedback_token_budget' => 4000,
        'project_feedback_budget_usd' => '.2', 'project_feedback_reserve_usd' => '.02', 'max_output_tokens' => 320];
    $reportSubmission = ProjectSubmission::query()->create(['public_id' => (string) Str::uuid(), 'user_id' => $student->id, 'project_id' => $reportProject->id,
        'idempotency_key' => 'browser-report', 'submission_text' => 'تطبيق الحملة الإعلانية', 'effort_status' => 'valid', 'review_status' => 'passed',
        'review_source' => 'ai', 'score' => 85, 'submitted_at' => now(), 'reviewed_at' => now(),
        'evaluation_snapshot' => ProjectSubmissionEvaluationSnapshot::capture($reportProject, $reportSection, $enrollment, $terms)]);
    $reportThread = ProjectFeedbackThread::query()->create(['public_id' => (string) Str::uuid(), 'submission_id' => $reportSubmission->id,
        'user_id' => $student->id, 'course_id' => $course->id, 'project_id' => $reportProject->id, 'enrollment_id' => $enrollment->id,
        'feedback_level' => 'report', 'can_reply' => false, 'status' => 'ready']);
    ProjectFeedbackMessage::query()->create(['public_id' => (string) Str::uuid(), 'thread_id' => $reportThread->id, 'role' => 'assistant', 'status' => 'completed',
        'client_request_id' => 'report:'.$reportSubmission->public_id, 'body' => "تقرير المتصفح عن المشروع\n".str_repeat('التكوين واضح ويمكن تحسين تباين العنوان وترتيب المعلومات. ', 8)]);
    Storage::disk('local')->put('dashboard-review-fixture/evidence.txt', 'Private project browser evidence');
    AiInputAttachment::query()->create(['public_id' => (string) Str::uuid(), 'user_id' => $student->id, 'course_id' => $course->id,
        'client_upload_id' => (string) Str::uuid(), 'purpose' => 'project_submission', 'owner_type' => 'project_submission', 'owner_id' => $reportSubmission->id,
        'storage_disk' => 'local', 'storage_path' => 'dashboard-review-fixture/evidence.txt', 'original_file_name' => 'نتيجة المشروع.txt',
        'mime_type' => 'text/plain', 'size_bytes' => 32, 'sha256' => hash('sha256', 'Private project browser evidence'), 'status' => 'ready']);
    DB::table('student_section_progress')->insert(['user_id' => $student->id, 'course_section_id' => $reportSection->id,
        'is_completed' => true, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    CourseCode::query()->forceCreate(['code' => 'BROWSER-GRANT', 'name' => 'منحة المتصفح', 'course_id' => $course->id,
        'type' => 'course', 'max_uses' => 10, 'used_count' => 0, 'is_active' => true, 'is_grant' => true]);
    CourseCode::query()->forceCreate(['code' => 'BROWSER-INACTIVE', 'name' => 'منحة المتصفح', 'course_id' => $course->id,
        'type' => 'course', 'max_uses' => 10, 'used_count' => 0, 'is_active' => false, 'is_grant' => true]);
    $historyCode = CourseCode::query()->create(['code' => 'BROWSER-HISTORY', 'name' => 'سجل المنحة', 'course_id' => $course->id,
        'type' => 'course', 'max_uses' => 10, 'used_count' => 1, 'is_active' => true, 'is_grant' => true]);
    $historyCode->usages()->create(['user_id' => $student->id, 'used_at' => now(), 'ip_address' => '127.0.0.1', 'user_agent' => 'Browser fixture']);
    NotificationCampaign::query()->create(['delivery_key' => 'browser-notification-scheduled', 'notification_type' => 'service_notice', 'audience' => 'all',
        'title_ar' => 'حملة المتصفح المجدولة', 'message_ar' => 'نص الإشعار الكامل في تجربة المتصفح',
        'action_label_ar' => 'ابدأ الكورس', 'link' => 'rokn://course/'.$course->id, 'course_id' => $course->id,
        'image_url' => 'http://127.0.0.1:8793/assets/app-artwork/v1/coin-stack.png',
        'status' => NotificationCampaign::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay(), 'user_ids' => [], 'exclude_user_ids' => []]);
    $template = AdminNotification::query()->create(['surface' => 'announcement', 'title_ar' => 'إعلان المتصفح المصور', 'description_ar' => 'نص الإعلان المحلي',
        'is_active' => true, 'priority' => 15, 'cooldown_hours' => 24]);
    Storage::disk('public')->put('notification-fixture/coins.png', file_get_contents($root.'/public/assets/app-artwork/v1/coin-stack.png'));
    $template->allPhotos()->create(['type' => 'featured', 'path' => 'notification-fixture/coins.png']);
    FeedbackReport::query()->firstOrCreate(['message' => 'رسالة دعم المتصفح'], [
        'public_id' => (string) Str::ulid(), 'user_id' => $student->id, 'category' => 'playback',
        'status' => 'new', 'priority' => 'high', 'version' => 1, 'app_version' => '1.0.68',
        'first_response_due_at' => now()->addDay(),
    ]);
    $deviceSession = ApiToken::query()->firstOrNew(['session_id' => 'f6a60374-3c52-4354-8e66-381e6333ceac']);
    $deviceSession->forceFill([
        'user_id' => $student->id, 'token' => hash('sha256', 'disposable-dashboard-session-fixture'),
        'device_id' => 'c757c164-c739-43df-9585-b5be35dd6999', 'platform' => 'android', 'device_class' => 'phone',
        'app_version' => '1.0.68', 'app_build' => '68', 'auth_provider' => 'google',
        'issued_at' => now(), 'last_used_at' => now(), 'expired_at' => now()->addDay(), 'revoked_at' => null,
    ])->save();
    AdminAuditLog::query()->firstOrCreate(['route_name' => 'admin.browser.audit'], [
        'request_id' => 'be70a508-12ea-4cde-bbbf-b394f429019a',
        'actor_id' => User::query()->where('email', 'admin@dashboard.example.test')->value('id'),
        'actor_role' => 'admin', 'http_method' => 'PATCH', 'path' => 'dashboard/browser-audit',
        'route_parameters' => ['user' => $student->id], 'request_fields' => ['active'],
        'response_status' => 302, 'occurred_at' => now(),
        'changes' => [['subject' => 'User', 'id' => (string) $student->id, 'event' => 'updated', 'old' => ['active' => true], 'attributes' => ['active' => false]]],
    ]);
    foreach ([false, true] as $deletion) {
        $message = Contact::query()->firstOrNew(['email' => $deletion ? 'deletion@dashboard.example.test' : 'contact@dashboard.example.test']);
        $message->forceFill([
            'name' => $deletion ? 'طلب حذف متصفح' : 'رسالة متصفح',
            'phone' => '201055550003', 'message' => $deletion ? '[ACCOUNT_DELETION_REQUEST] أريد حذف حسابي' : 'أريد الاستفسار عن الكورسات',
            'read' => false, 'request_type' => $deletion ? Contact::TYPE_ACCOUNT_DELETION : null,
            'resolution_status' => $deletion ? Contact::RESOLUTION_PENDING : null,
            'resolved_at' => null, 'resolved_by' => null, 'resolved_user_id' => null, 'resolution_metadata' => null,
        ])->save();
    }
    $package = Package::query()->firstOrCreate(['name_en' => 'Browser fixture coins'], ['name_ar' => 'حزمة المتصفح', 'price' => '11.11', 'coins' => 900]);
    Order::query()->firstOrCreate(['order_ref' => 'BROWSER-PAID-900'], [
        'user_id' => $student->id, 'package_id' => $package->id, 'package_coins' => 900,
        'payment_method' => Order::PAYMENT_METHOD_GOOGLE_PLAY, 'status' => Order::STATUS_APPROVED,
        'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now(),
        'amount' => '11.11', 'final_amount' => '11.11', 'gateway_gross_amount' => '11.11',
        'gateway_currency' => 'EGP', 'gateway_settlement_status' => 'test_purchase', 'total_coins' => 900,
    ]);
    $statement = Order::query()->create(['order_ref' => 'BROWSER-STATEMENT',
        'user_id' => $student->id, 'package_id' => $package->id, 'package_coins' => 900,
        'payment_method' => Order::PAYMENT_METHOD_KASHIER, 'status' => Order::STATUS_APPROVED,
        'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now(),
        'amount' => '100.00', 'final_amount' => '100.00', 'gateway_gross_amount' => '100.00',
        'gateway_currency' => 'EGP', 'gateway_settlement_status' => 'captured', 'total_coins' => 900,
        'gateway_fee_amount' => null, 'gateway_net_amount' => null, 'gateway_settled_at' => null,
    ]);
    PaymentReconciliationFinding::query()->create([
        'order_id' => $statement->id, 'order_ref' => $statement->order_ref, 'provider' => 'kashier',
        'fingerprint' => hash('sha256', 'browser-payment-finding'), 'kind' => 'provider_local_status_mismatch',
        'state' => 'open', 'attempts' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        'local_status' => 'pending', 'provider_status' => 'CAPTURED',
        'evidence' => ['provider_status' => 'CAPTURED', 'detail' => '<script>window.paymentEvidenceExecuted=true</script>'],
    ]);
    echo "Disposable dashboard browser database prepared\n";
} catch (Throwable $exception) {
    report($exception);
    fwrite(STDERR, 'Dashboard browser preparation failed: '.$exception::class.PHP_EOL);
    exit(1);
}
