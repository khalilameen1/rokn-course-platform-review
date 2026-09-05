<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ProductionPreflight;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionPreflightTest extends TestCase
{
    public function test_schema_preflight_rejects_orders_without_coupon_code(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->decimal('gateway_gross_amount', 12, 2)->nullable();
            $table->decimal('gateway_fee_amount', 12, 2)->nullable();
            $table->decimal('gateway_net_amount', 12, 2)->nullable();
        });

        try {
            $method = new \ReflectionMethod(
                ProductionPreflight::class,
                'requiredProductSchemaFailures'
            );
            $failures = $method->invoke(app(ProductionPreflight::class));

            self::assertContains(
                'The orders schema is stale. Missing columns: coupon_code. Run all forward migrations before release.',
                $failures
            );
        } finally {
            Schema::dropIfExists('orders');
        }
    }

    public function test_preflight_fails_closed_for_placeholder_runtime_configuration(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'app.url' => 'https://api.example.com',
            'app.app_domain' => 'example.com',
        ]);

        self::assertSame(1, Artisan::call('rokn:preflight'));
        $output = Artisan::output();
        self::assertStringContainsString('APP_KEY', $output);
        self::assertStringContainsString('APP_URL', $output);
    }

    public function test_preflight_rejects_local_public_missing_and_unattested_course_pdf_storage(): void
    {
        foreach (['', 'local', 'public', 'not-configured'] as $disk) {
            config(['course_pdfs.disk' => $disk]);
            self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
            self::assertStringContainsString('COURSE_PDF_DISK', Artisan::output());
        }

        config([
            'course_pdfs.disk' => 'course-pdfs',
            'course_pdfs.shared_storage' => false,
        ]);
        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        self::assertStringContainsString('COURSE_PDF_SHARED_STORAGE=true', Artisan::output());
    }

    public function test_preflight_rejects_ephemeral_feedback_storage(): void
    {
        config(['filesystems.disks.feedback.shared' => false]);

        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        self::assertStringContainsString('feedback disk must use private durable', Artisan::output());
    }

    public function test_preflight_requires_payment_evidence_on_private_shared_object_storage(): void
    {
        foreach (['', 'local', 'public'] as $disk) {
            config(['payment_evidence.disk' => $disk]);
            self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
            self::assertStringContainsString('PAYMENT_EVIDENCE_DISK', Artisan::output());
        }

        config([
            'payment_evidence.disk' => 'unsafe-evidence',
            'filesystems.disks.unsafe-evidence' => [
                'driver' => 's3',
                'visibility' => 'public',
            ],
        ]);
        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        self::assertStringContainsString('PAYMENT_EVIDENCE_DISK', Artisan::output());
    }

    public function test_preflight_accepts_private_object_storage_for_feedback_and_recovery_evidence(): void
    {
        config([
            'filesystems.disks.feedback' => [
                'driver' => 's3',
            ],
            'operations.recovery_evidence_disk' => 's3',
            'operations.backup_evidence_path' => 'recovery/latest-backup.json',
            'operations.recovery_evidence_path' => 'recovery/latest.json',
        ]);

        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        $output = Artisan::output();
        self::assertStringNotContainsString('feedback disk must use private durable', $output);
        self::assertStringNotContainsString('Recovery evidence must use a configured private shared disk', $output);
    }

    public function test_preflight_rejects_a_shared_public_private_bucket_and_missing_public_url(): void
    {
        config([
            'filesystems.disks.public' => [
                'driver' => 's3',
                'key' => 'public-key',
                'secret' => 'public-secret',
                'region' => 'auto',
                'endpoint' => 'https://r2.example.test',
                'bucket' => 'rokn-shared',
                'url' => '',
            ],
            'filesystems.disks.s3' => [
                'driver' => 's3',
                'bucket' => 'rokn-shared',
                'url' => 'https://private.example.test',
            ],
        ]);

        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('complete PUBLIC_AWS_* credentials', $output);
        self::assertStringContainsString('PUBLIC_AWS_BUCKET must be separate', $output);

        config(['filesystems.disks.public.url' => 'https://private.example.test']);
        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        self::assertStringContainsString('PUBLIC_AWS_URL must not reuse', Artisan::output());
    }

    public function test_preflight_rejects_missing_or_malformed_app_association_identity(): void
    {
        config([
            'app_links.android_package' => '',
            'app_links.android_sha256_fingerprints' => [],
            'app_links.apple_app_ids' => [],
        ]);

        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('APP_LINK_ANDROID_PACKAGE', $output);
        self::assertStringContainsString('APP_LINK_ANDROID_SHA256_FINGERPRINTS', $output);
        self::assertStringContainsString('APP_LINK_APPLE_APP_IDS', $output);

        config([
            'app_links.android_package' => 'not-a-package',
            'app_links.android_sha256_fingerprints' => ['AA:BB'],
            'app_links.apple_app_ids' => ['ABCDE12345.*'],
        ]);

        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('APP_LINK_ANDROID_PACKAGE', $output);
        self::assertStringContainsString('APP_LINK_ANDROID_SHA256_FINGERPRINTS', $output);
        self::assertStringContainsString('APP_LINK_APPLE_APP_IDS', $output);
    }

    public function test_preflight_does_not_require_apple_association_before_apple_is_declared(): void
    {
        config([
            'mobile_contract.launch_channels' => ['direct'],
            'social_auth.providers' => ['google', 'tiktok'],
            'app_links.apple_app_ids' => [],
        ]);

        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        self::assertStringNotContainsString('APP_LINK_APPLE_APP_IDS', Artisan::output());
    }

    public function test_preflight_requires_apple_association_for_an_app_store_release_even_without_apple_login(): void
    {
        config([
            'mobile_contract.launch_channels' => ['appstore'],
            'social_auth.providers' => ['google', 'tiktok'],
            'app_links.apple_app_ids' => [],
        ]);

        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('APP_LINK_APPLE_APP_IDS', $output);
        self::assertStringNotContainsString('APP_LINK_ANDROID_PACKAGE', $output);
        self::assertStringNotContainsString('APP_LINK_ANDROID_SHA256_FINGERPRINTS', $output);
    }

    public function test_app_link_connectivity_accepts_the_real_android_contract_without_an_internal_release_header(): void
    {
        $fingerprint = implode(':', array_fill(0, 32, 'AB'));
        config([
            'app_links.android_package' => 'com.rokn',
            'app_links.android_sha256_fingerprints' => [$fingerprint],
        ]);
        $statement = [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => 'com.rokn',
                'sha256_cert_fingerprints' => [$fingerprint],
            ],
        ]];
        $invalidStatement = $statement;
        $invalidStatement[0]['target']['package_name'] = 'com.attacker';
        Http::fakeSequence()
            ->push($statement, 200)
            ->push($invalidStatement, 200);
        $response = Http::get('https://rokn.test/.well-known/assetlinks.json');

        $method = new \ReflectionMethod(ProductionPreflight::class, 'validAndroidAssociation');
        self::assertTrue($method->invoke(app(ProductionPreflight::class), $response, 'release-id'));

        $response = Http::get('https://rokn.test/.well-known/assetlinks.json');
        self::assertFalse($method->invoke(app(ProductionPreflight::class), $response, 'release-id'));
    }

    public function test_preflight_fails_closed_for_unsafe_social_auth_and_host_configuration(): void
    {
        config([
            'app.url' => 'https://api.rokn.academy',
            'trusted_hosts.hosts' => [],
            'social_auth.public_api_url' => '',
            'social_auth.return_urls' => ['rokn://auth', 'https://attacker.invalid/callback'],
            'services.facebook.graph_version' => 'v19.0',
            'services.apple.client_id' => null,
        ]);

        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('APP_TRUSTED_HOSTS', $output);
        self::assertStringContainsString('SOCIAL_AUTH_PUBLIC_API_URL', $output);
        self::assertStringContainsString('SOCIAL_AUTH_RETURN_URLS', $output);
        self::assertStringContainsString('FACEBOOK_GRAPH_VERSION', $output);
        self::assertStringContainsString('APPLE_CLIENT_ID', $output);

        foreach ([
            'http://api.rokn.academy/api/v1',
            'https://localhost/api/v1',
            'https://api.example.com/api/v1',
            'https://api.rokn.academy/oauth',
            'https://user:secret@api.rokn.academy/api/v1',
            'https://api.rokn.academy/api/v1?redirect=evil',
        ] as $unsafeUrl) {
            config(['social_auth.public_api_url' => $unsafeUrl]);
            self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
            self::assertStringContainsString('SOCIAL_AUTH_PUBLIC_API_URL', Artisan::output());
        }

        config([
            'trusted_hosts.hosts' => ['api.rokn.academy'],
            'social_auth.public_api_url' => 'https://oauth.rokn.academy/api/v1',
        ]);
        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        self::assertStringContainsString(
            'APP_TRUSTED_HOSTS must include the SOCIAL_AUTH_PUBLIC_API_URL host',
            Artisan::output()
        );

        config([
            'trusted_hosts.hosts' => ['*.rokn.academy'],
            'social_auth.public_api_url' => 'https://api.rokn.academy/api/v1',
        ]);
        self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
        self::assertStringContainsString('APP_TRUSTED_HOSTS', Artisan::output());
    }

    public function test_preflight_accepts_a_complete_production_contract_without_network_probe(): void
    {
        $credentials = tempnam(sys_get_temp_dir(), 'rokn-firebase-');
        self::assertIsString($credentials);
        $androidFingerprint = implode(':', array_fill(0, 32, 'AB'));

        try {
            file_put_contents($credentials, json_encode([
                'project_id' => 'rokn-production',
                'client_email' => 'firebase-admin@rokn-production.iam.gserviceaccount.com',
                'private_key' => "-----BEGIN"." PRIVATE KEY-----\nfixture\n-----END"." PRIVATE KEY-----\n",
            ], JSON_THROW_ON_ERROR));
            config([
                'app.env' => 'production',
                'app.debug' => false,
                'app.key' => 'base64:' . base64_encode(random_bytes(32)),
                'app.url' => 'https://api.rokn.academy',
                'app.app_domain' => 'rokn.academy',
                'public_links.base_url' => 'https://rokn.academy',
                'app.timezone' => 'UTC',
                'app.business_timezone' => 'Africa/Cairo',
                'trusted_hosts.hosts' => ['api.rokn.academy', 'rokn.academy'],
                'l5-swagger.routes.middleware.api' => ['admin.only', 'admin.mfa'],
                'l5-swagger.routes.middleware.docs' => ['admin.only', 'admin.mfa'],
                'operations.recovery_encryption_key_id' => 'production-key-v1',
                'operations.recovery_evidence_signing_key' => str_repeat('r', 48),
                'app_links.android_package' => 'com.rokn',
                'app_links.android_sha256_fingerprints' => [$androidFingerprint],
                'app_links.apple_app_ids' => ['ABCDE12345.com.rokn.app'],
                'social_auth.public_api_url' => 'https://api.rokn.academy/api/v1',
                'social_auth.return_urls' => ['rokn://auth'],
                'database.default' => 'mysql',
                'database.redis.default.host' => 'redis.internal',
                'cache.default' => 'redis',
                'queue.default' => 'redis',
                'session.driver' => 'redis',
                'session.secure' => true,
                'trusted_proxies.proxies' => ['10.20.30.0/24'],
                'bunny.stream_api_key' => 'configured',
                'bunny.library_id' => 'configured',
                'bunny.cdn_hostname' => 'vz-rokn.b-cdn.net',
                'bunny.token_auth_key' => 'configured',
                'bunny.storage_zone' => 'production-assets',
                'bunny.storage_password' => 'configured',
                'bunny.storage_cdn_hostname' => 'rokn-assets.b-cdn.net',
                'bunny.storage_token_auth_key' => 'configured',
                'kashier.mode' => 'live',
                'kashier.live.api_key' => 'configured',
                'kashier.live.secret_key' => 'configured',
                'kashier.live.mid' => 'configured',
                'whatsapp.whatspie.api_url' => 'https://api.whatspie.com/messages',
                'whatsapp.whatspie.api_key' => 'configured',
                'whatsapp.whatspie.device' => '201017023541',
                'whatsapp.linking.bot_phone' => '201017023541',
                'whatsapp.linking.webhook_secret' => str_repeat('w', 48),
                'services.facebook.client_id' => 'configured',
                'services.facebook.client_secret' => 'configured',
                'services.facebook.graph_version' => 'v26.0',
                'services.google.client_id' => 'configured',
                'services.google.client_secret' => 'configured',
                'services.tiktok.client_key' => 'configured',
                'services.tiktok.client_secret' => 'configured',
                'services.apple.client_id' => 'com.rokn',
                'openrouter.api_key' => 'configured',
                'openrouter.default_model' => 'configured',
                'openrouter.project_model' => 'configured',
                'openrouter.allowed_models' => ['configured'],
                'openrouter.fallback_models' => [],
                'openrouter.provider_sort' => 'latency',
                'openrouter.web_search_enabled' => true,
                'openrouter.web_search_max_results' => 3,
                'openrouter.web_search_max_total_results' => 5,
                'openrouter.global_daily_request_limit' => 1,
                'openrouter.global_daily_token_budget' => 1,
                'openrouter.global_monthly_token_budget' => 1,
                'course_plans.economics_configured' => true,
                'course_plans.net_usd_per_paid_coin' => 0.001,
                'course_plans.ai_cost_safety_multiplier' => 1.5,
                'projects.submission_disk' => 's3',
                'certificate.disk' => 's3',
                'course_pdfs.disk' => 's3',
                'payment_evidence.disk' => 's3',
                'filesystems.disks.public' => [
                    'driver' => 'local',
                    'root' => storage_path('framework/testing/public'),
                    'url' => 'https://rokn.academy/storage',
                    'visibility' => 'public',
                    'throw' => true,
                ],
                'filesystems.disks.feedback' => [
                    'driver' => 'local',
                    'root' => storage_path('framework/testing/feedback'),
                    'visibility' => 'private',
                    'shared' => true,
                ],
                'mail.from.address' => 'support@production.test',
                'firebase.credentials.file' => $credentials,
            ]);

            $status = Artisan::call('rokn:preflight', ['--configuration-only' => true]);
            $output = Artisan::output();
            self::assertSame(0, $status, $output);
            self::assertStringContainsString('passed', $output);

            config([
                'firebase.credentials.file' => storage_path('missing-firebase-service-account.json'),
                'firebase.credentials.base64' => base64_encode(json_encode([
                    'project_id' => 'rokn-production',
                    'client_email' => 'firebase-admin@rokn-production.iam.gserviceaccount.com',
                    'private_key' => "-----BEGIN"." PRIVATE KEY-----\nfixture\n-----END"." PRIVATE KEY-----\n",
                ], JSON_THROW_ON_ERROR)),
            ]);
            self::assertSame(0, Artisan::call('rokn:preflight', ['--configuration-only' => true]));

            config(['firebase.credentials.base64' => 'not-valid-base64']);
            self::assertSame(1, Artisan::call('rokn:preflight', ['--configuration-only' => true]));
            self::assertStringContainsString('FIREBASE_CREDENTIALS_BASE64', Artisan::output());
        } finally {
            if (is_string($credentials) && is_file($credentials)) {
                unlink($credentials);
            }
        }
    }

    public function test_preflight_blocks_legacy_public_assets_and_svg_profiles(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('profile_image')->nullable();
        });
        Schema::create('portfolio_media', function (Blueprint $table): void {
            $table->id();
            $table->string('file_path')->nullable();
        });
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->string('thumbnail_path')->nullable();
        });
        Schema::create('course_pdfs', function (Blueprint $table): void {
            $table->id();
            $table->string('file_path');
            $table->string('storage_disk')->nullable();
            $table->softDeletes();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_screenshot')->nullable();
        });

        try {
            DB::table('users')->insert([
                'profile_image' => 'profile-images/legacy-avatar.SVG',
            ]);
            DB::table('portfolio_media')->insert([
                ['file_path' => 'portfolio/old-collision.jpg'],
                ['file_path' => 'portfolio/old-collision.jpg'],
            ]);
            DB::table('lessons')->insert([
                ['thumbnail_path' => 'lessons/thumbnails/old-collision.jpg'],
                ['thumbnail_path' => 'lessons/thumbnails/old-collision.jpg'],
            ]);
            DB::table('course_pdfs')->insert([
                'file_path' => 'course-pdfs/legacy.pdf',
                'storage_disk' => 'local',
            ]);
            DB::table('orders')->insert([
                'payment_screenshot' => '/storage/payment-evidence/legacy-receipt.png',
            ]);
            DB::table('orders')->insert([
                'payment_screenshot' => 'receipts/non-canonical.png',
            ]);

            self::assertSame(1, Artisan::call('rokn:preflight'));
            $output = Artisan::output();
            self::assertStringContainsString('public SVG profile image', $output);
            self::assertStringContainsString('security:quarantine-profile-svg --execute', $output);
            self::assertStringContainsString('duplicate Bunny portfolio image', $output);
            self::assertStringContainsString('duplicate Bunny lesson thumbnail', $output);
            self::assertStringContainsString('course PDF', $output);
            self::assertStringContainsString('course-pdfs:migrate-storage --execute', $output);
            self::assertStringContainsString('legacy public payment evidence', $output);
            self::assertStringContainsString('PAYMENT_EVIDENCE_DISK', $output);
            self::assertStringContainsString('invalid payment evidence path', $output);
        } finally {
            Schema::dropIfExists('orders');
            Schema::dropIfExists('course_pdfs');
            Schema::dropIfExists('lessons');
            Schema::dropIfExists('portfolio_media');
            Schema::dropIfExists('users');
        }
    }
}
