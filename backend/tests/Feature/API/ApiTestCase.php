<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Abstract base test case for API endpoint feature tests.
 * Sets up an isolated in-memory SQLite schema and base seed fixtures cleanly
 * without modifying or running historical migrations.
 */
abstract class ApiTestCase extends TestCase
{
    protected User $user;
    protected int $gradeId;
    protected int $courseId;
    protected int $moduleId;
    protected int $sectionId;
    protected int $pathId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchema();
        $this->setUpData();

        // Disable middlewares that depend on tenant subdomain state or visitor tracking blocks
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSchema();
        parent::tearDown();
    }

    private function setUpSchema(): void
    {
        Schema::create('social_oauth_attempts', function (Blueprint $table): void {
            $table->id();
            $table->char('state_hash', 64)->unique();
            $table->char('completion_hash', 64)->nullable()->unique();
            $table->string('provider', 24);
            $table->string('return_to', 255);
            $table->string('code_challenge', 128)->nullable();
            $table->char('nonce_hash', 64)->nullable();
            $table->text('encrypted_token')->nullable();
            $table->text('encrypted_completion_code')->nullable();
            $table->text('encrypted_session_response')->nullable();
            $table->timestamp('state_expires_at');
            $table->timestamp('state_consumed_at')->nullable();
            $table->timestamp('completion_expires_at')->nullable();
            $table->timestamp('completion_processing_at')->nullable();
            $table->uuid('completion_claim_id')->nullable()->index();
            $table->timestamp('completion_consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name_ar')->nullable();
            $table->string('site_name_en')->nullable();
            $table->boolean('enforce_course_section_order')->default(false);
            $table->unsignedInteger('welcome_bonus_coins')->default(20);
            $table->unsignedInteger('reward_balance_cap')->default(1200);
            $table->unsignedInteger('max_reward_contribution_per_course')->default(1200);
            $table->unsignedInteger('daily_reward_coins')->default(15);
            $table->unsignedInteger('daily_reward_rolling_30_day_cap')->default(150);
            $table->unsignedSmallInteger('streak_reward_days')->default(7);
            $table->unsignedInteger('streak_reward_coins')->default(100);
            $table->unsignedInteger('streak_reward_rolling_30_day_cap')->default(400);
            $table->unsignedInteger('study_reward_coins')->default(10);
            $table->unsignedSmallInteger('study_reward_minutes')->default(5);
            $table->unsignedInteger('study_reward_daily_cap')->default(20);
            $table->unsignedInteger('study_reward_rolling_30_day_cap')->default(200);
            $table->unsignedInteger('first_project_reward_coins')->default(150);
            $table->unsignedInteger('course_completion_reward_coins')->default(200);
            $table->unsignedInteger('course_completion_rolling_30_day_cap')->default(200);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('first_name')->nullable();
            $table->string('second_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone')->nullable()->unique();
            $table->string('parent_phone')->nullable();
            $table->string('parent_job')->nullable();
            $table->string('password')->nullable();
            $table->enum('role', ['admin', 'client', 'provider', 'merchant'])->default('client');
            $table->enum('gender', ['male', 'female', 'other'])->default('male');
            $table->date('birthday')->nullable();
            $table->integer('rate')->default(0);
            $table->float('balance')->default(0);
            $table->integer('wallet_coins')->default(0);
            $table->unsignedInteger('wallet_purchased_coins')->default(0);
            $table->unsignedInteger('wallet_reward_coins')->default(0);
            $table->string('api_token', 100)->unique()->nullable();
            $table->string('device_os')->nullable();
            $table->string('locked_device_id')->nullable();
            $table->string('access_token')->nullable();
            $table->string('social_provider')->nullable();
            $table->string('social_id')->nullable();
            $table->string('profile_image')->nullable();
            $table->unsignedBigInteger('profile_revision')->default(0);
            $table->string('job_title')->nullable();
            $table->text('bio')->nullable();
            $table->text('bio_ar')->nullable();
            $table->text('bio_en')->nullable();
            $table->string('portfolio_slug')->nullable()->unique();
            $table->boolean('portfolio_is_public')->default(false);
            $table->string('portfolio_headline')->nullable();
            $table->string('portfolio_location')->nullable();
            $table->json('portfolio_skills')->nullable();
            $table->json('portfolio_links')->nullable();
            $table->string('type')->nullable();
            $table->string('governorate')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_online')->default(false);
            $table->boolean('provider_request')->default(false);
            $table->boolean('notifications_status')->default(false);
            $table->boolean('watch_history_enabled')->default(true);
            $table->boolean('marketing_notifications_enabled')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_notice_acknowledged_at')->nullable();
            $table->string('legal_notice_version', 32)->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->string('photoable_type');
            $table->unsignedBigInteger('photoable_id');
            $table->string('type')->default('gallery');
            $table->string('url')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable();
            $table->string('image')->nullable();
            $table->string('search_keywords_ar')->nullable();
            $table->string('search_keywords_en')->nullable();
            $table->string('search_title_normalized', 512)->nullable();
            $table->text('search_terms_normalized')->nullable();
            $table->decimal('price', 10, 2)->default(100.00);
            $table->boolean('active')->default(true);
            $table->boolean('is_main_course')->default(true);
            $table->boolean('is_coming_soon')->default(false);
            $table->boolean('is_catalog_visible')->default(false);
            $table->unsignedBigInteger('authoring_version')->default(1);
            $table->unsignedBigInteger('last_published_authoring_version')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->integer('home_sort_order')->default(0);
            $table->string('course_type')->default('online');
            $table->float('rate')->default(5.0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('course_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->integer('rating');
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('course_teacher', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('teacher_id');
            $table->timestamps();
        });

        Schema::create('classification_course', function (Blueprint $table) {
            $table->unsignedBigInteger('classification_id');
            $table->unsignedBigInteger('course_id');
        });

        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('title')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('title')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->string('section_type')->default('lesson');
            $table->string('sectionable_type')->nullable();
            $table->unsignedBigInteger('sectionable_id')->nullable();
            $table->integer('order')->default(0);
            $table->integer('sort_order')->default(1);
            $table->boolean('is_free')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('title');
            $table->string('file_path');
            $table->string('storage_disk', 64)->default('public');
            $table->string('file_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->unsignedBigInteger('access_plan_order_id')->nullable();
            $table->json('access_plan_snapshot')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedInteger('package_coins')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('access_granted_at')->nullable();
            $table->float('progress')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_curriculum_revision')->nullable();
            $table->timestamp('curriculum_completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_ref')->nullable()->unique();
            $table->string('transaction_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->json('access_plan_snapshot')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('course_code_id')->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('payment_method', 50)->default('online');
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('payment_screenshot')->nullable();
            $table->json('payment_gateway_response')->nullable();
            $table->string('checkout_request_key')->nullable();
            $table->decimal('amount', 10, 2)->default(100.00);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('final_amount', 10, 2)->default(100.00);
            $table->string('status')->default('approved');
            $table->string('financial_status')->default('settled');
            $table->unsignedInteger('total_coins')->default(0);
            $table->unsignedInteger('paid_coins')->default(0);
            $table->unsignedInteger('reward_coins')->default(0);
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->unsignedInteger('recovered_coins')->default(0);
            $table->unsignedInteger('unrecovered_coins')->default(0);
            $table->boolean('is_premium_user')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('bill_number')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('payment_status')->default('pending');
            $table->string('payment_method')->default('online');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('delivery_key', 64)->nullable();
            $table->uuid('client_request_id')->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('source_project_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('slug')->nullable();
            $table->string('role')->nullable();
            $table->json('tools')->nullable();
            $table->text('external_url')->nullable();
            $table->date('completed_at')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('portfolio_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portfolio_item_id');
            $table->string('file_type')->default('image');
            $table->string('caption')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('file_path')->nullable();
            $table->uuid('client_request_id')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('original_name')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->text('requirements_text')->nullable();
            $table->text('ai_prompt')->nullable();
            $table->integer('passing_score')->default(50);
            $table->boolean('is_graduation_project')->default(false);
            $table->timestamps();
        });

        Schema::create('project_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->string('idempotency_key', 100);
            $table->text('submission_text')->nullable();
            $table->string('submission_file')->nullable();
            $table->string('original_file_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('submission_metadata')->nullable();
            $table->string('effort_status', 30)->default('unknown');
            $table->string('review_status', 30)->default('pending');
            $table->string('review_source', 40)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('auto_pass_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'project_id', 'idempotency_key']);
        });

        Schema::create('course_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type')->default('course');
            $table->string('target_content_name')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_grant')->default(false);
            $table->json('allowed_email_domains')->nullable();
            $table->boolean('is_used')->default(false);
            $table->integer('used_count')->default(0);
            $table->integer('max_uses')->default(1);
            $table->timestamp('start_date')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('course_code_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_code_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('course_pdfs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('title')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            $table->string('file_path')->nullable();
            $table->string('storage_disk', 64)->nullable();
            $table->string('original_filename')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->nullable()->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('holder_name')->nullable();
            $table->string('course_name')->nullable();
            $table->string('certificate_text_template_key', 32)->nullable();
            $table->string('certificate_text')->nullable();
            $table->string('image_path')->default('pending');
            $table->uuid('generation_lease_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('verification_level', 24)->default('completion');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedTinyInteger('recovery_attempts')->default(0);
            $table->timestamp('recovery_next_attempt_at')->nullable();
            $table->timestamp('recovery_failed_at')->nullable();
            $table->string('recovery_failure_code', 64)->nullable();
            $table->timestamp('artifact_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('course_grant_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->char('normalized_email_hash', 64)->unique();
            $table->string('email_hint')->nullable();
            $table->unsignedBigInteger('course_code_id');
            $table->unsignedBigInteger('course_code_usage_id')->nullable();
            $table->unsignedBigInteger('course_id');
            $table->string('status')->default('active');
            $table->timestamp('claimed_at');
            $table->timestamp('reassigned_at')->nullable();
            $table->unsignedBigInteger('reassigned_by')->nullable();
            $table->text('support_note')->nullable();
            $table->timestamps();
        });

        Schema::create('paths', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('classifications', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('classification_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('classification_id');
            $table->timestamps();
        });

        Schema::create('student_notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('delivery_key', 64)->nullable();
            $table->string('notification_type')->default('info');
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->text('message_ar')->nullable();
            $table->text('message_en')->nullable();
            $table->string('link')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('action_label_ar', 80)->nullable();
            $table->string('action_label_en', 80)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('push_attempted_at')->nullable();
            $table->unsignedSmallInteger('push_attempts')->default(0);
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamp('push_failed_at')->nullable();
            $table->string('push_failure_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'delivery_key']);
        });

        Schema::create('admin_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('system_key', 80)->nullable()->unique();
            $table->string('surface', 32)->default('announcement');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->string('description_ar')->nullable();
            $table->string('description_en')->nullable();
            $table->string('action_label_ar')->nullable();
            $table->string('action_label_en')->nullable();
            $table->string('secondary_action_label_ar')->nullable();
            $table->string('secondary_action_label_en')->nullable();
            $table->string('link')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_dismissible')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedSmallInteger('cooldown_hours')->default(72);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('coin_earning_methods', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar')->default('مهمة');
            $table->string('title_en')->default('Task');
            $table->string('action_key')->nullable()->unique();
            $table->string('campaign_key', 80)->nullable()->unique();
            $table->integer('coins_amount')->default(20);
            $table->boolean('is_repeatable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('active')->default(true);
            $table->text('action_url')->nullable();
            $table->boolean('requires_external_visit')->default(false);
            $table->unsignedSmallInteger('verification_delay_seconds')->default(3);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('total_claim_limit')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_coin_earnings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('coin_earning_method_id')->nullable();
            $table->integer('amount');
            $table->timestamps();
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->string('token', 80)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expired_at');
            $table->uuid('session_id')->nullable()->unique();
            $table->uuid('device_id')->nullable();
            $table->string('platform', 16)->nullable()->index();
            $table->string('device_class', 12)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('app_build', 16)->nullable();
            $table->string('auth_provider', 24)->nullable();
            $table->string('auth_provider_user_id', 191)->nullable();
            $table->timestamp('last_used_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('provider_email')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
        });

        Schema::create('deleted_social_reward_tombstones', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->char('identity_hmac', 64);
            $table->json('consumed_reward_keys');
            $table->timestamps();
            $table->unique(['provider', 'identity_hmac']);
        });

        Schema::create('user_coin_task_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('coin_earning_method_id');
            $table->string('status', 20)->default('started');
            $table->timestamp('started_at');
            $table->timestamp('claim_available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'coin_earning_method_id']);
        });

        Schema::create('user_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('device_token');
            $table->string('device_type')->nullable();
            $table->string('device_os')->nullable();
            $table->uuid('device_id')->nullable();
            $table->timestamps();
        });

        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('code');
            $table->string('type')->default('verification');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('saved_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('normalized_name')->nullable();
            $table->uuid('client_request_id')->nullable();
            $table->timestamps();
            $table->unique(
                ['user_id', 'normalized_name'],
                'saved_folders_user_normalized_name_unique'
            );
            $table->unique(
                ['user_id', 'client_request_id'],
                'saved_folders_user_request_unique'
            );
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('list_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->string('title')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_opened')->default(true);
            $table->string('video_link')->nullable();
            $table->string('video_source_type')->default('youtube');
            $table->string('bunny_video_id')->nullable();
            $table->integer('duration_minutes')->default(10);
            $table->string('image')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('file_link1')->nullable();
            $table->string('file_link2')->nullable();
            $table->integer('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('lesson_media_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lesson_id')->unique();
            $table->string('provider', 32)->default('bunny');
            $table->string('provider_media_id')->nullable();
            $table->string('status', 24)->default('unknown');
            $table->string('protocol', 16)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('available_qualities')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamps();
        });

        Schema::create('playback_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedBigInteger('course_section_id')->nullable();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('started_playing_at')->nullable();
            $table->unsignedInteger('startup_latency_ms')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('metrics_rolled_up_at')->nullable();
            $table->string('event_type', 24)->default('play');
            $table->string('end_reason', 32)->nullable();
            $table->string('source_protocol', 16)->nullable();
            $table->string('effective_quality', 16)->nullable();
            $table->unsignedInteger('effective_bitrate_kbps')->nullable();
            $table->string('source_host', 190)->nullable();
            $table->string('client_name', 24)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('os_family', 12)->default('other');
            $table->string('os_version', 32)->nullable();
            $table->string('connection_type', 12)->default('unknown');
            $table->json('client_capabilities')->nullable();
            $table->string('playback_reason', 48)->nullable();
            $table->timestamp('source_expires_at')->nullable();
            $table->decimal('playback_rate', 4, 2)->default(1);
            $table->unsignedSmallInteger('recovery_count')->default(0);
            $table->unsignedSmallInteger('buffer_count')->default(0);
            $table->unsignedInteger('buffer_duration_ms')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamps();
        });

        Schema::create('profile_update_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->uuid('client_request_id');
            $table->char('request_fingerprint', 64);
            $table->unsignedBigInteger('profile_revision');
            $table->timestamps();
            $table->unique(['user_id', 'client_request_id']);
        });

        Schema::create('account_file_deletions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('disk', 64);
            $table->string('path_hash', 64);
            $table->text('path')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error', 190)->nullable();
            $table->timestamps();
            $table->unique(['disk', 'path_hash']);
        });

        Schema::create('lesson_watch_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedBigInteger('course_section_id');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('verified_seconds')->default(0);
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'lesson_id']);
        });

        Schema::create('user_daily_learning_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('activity_date');
            $table->unsignedInteger('qualified_seconds')->default(0);
            $table->json('reward_contract')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'activity_date']);
        });

        Schema::create('user_reward_checkins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('checkin_date');
            $table->json('daily_rule_snapshot')->nullable();
            $table->json('streak_rule_snapshot')->nullable();
            $table->timestamp('rules_snapshotted_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'checkin_date']);
        });

        Schema::create('reward_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->unsignedInteger('coins_amount');
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedInteger('daily_cap')->nullable();
            $table->unsignedInteger('rolling_30_day_cap')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('direction', 10);
            $table->string('category', 40);
            $table->string('bucket', 24)->default('legacy_reward');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('paid_amount')->default(0);
            $table->unsignedInteger('reward_amount')->default(0);
            $table->integer('balance_after');
            $table->unsignedInteger('paid_balance_after')->default(0);
            $table->unsignedInteger('reward_balance_after')->default(0);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 140);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('financial_entitlement_holds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('course_order_id');
            $table->unsignedBigInteger('source_order_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->timestamp('enrollment_deactivated_at')->nullable();
            $table->timestamp('plan_reverted_at')->nullable();
            $table->unsignedBigInteger('certificate_id')->nullable();
            $table->timestamp('certificate_revoked_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('status', 24)->default('active');
            $table->string('entitlement_scope', 16)->default('course');
            $table->string('reason')->nullable();
            $table->string('resolution', 24)->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('held_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('course_authoring_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('canonical_course_id');
            $table->unsignedBigInteger('revision_course_id');
            $table->unsignedBigInteger('base_authoring_version');
            $table->unsignedBigInteger('published_authoring_version')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('active_slot', 80)->nullable()->unique();
            $table->uuid('clone_key')->unique();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retain_until')->nullable();
            $table->timestamps();
        });

        Schema::create('course_authoring_revision_entities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_authoring_revision_id');
            $table->string('entity_type', 120);
            $table->unsignedBigInteger('source_entity_id');
            $table->unsignedBigInteger('revision_entity_id');
            $table->boolean('survives_publish')->default(false);
            $table->boolean('carries_learner_state')->default(false);
            $table->unsignedBigInteger('learner_root_entity_id')->nullable();
        });

        Schema::create('watching_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('lesson_id');
            $table->string('lesson_name');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('course_section_id')->nullable();
            $table->string('course_name');
            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->uuid('playback_session_id')->nullable();
            $table->timestamp('playback_session_started_at')->nullable();
            $table->unsignedInteger('last_playback_sequence')->nullable();
            $table->timestamp('watched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'lesson_id']);
        });

        Schema::create('notification_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_notification_id');
            $table->unsignedBigInteger('user_device_token_id');
            $table->string('token_fingerprint', 64);
            $table->string('device_os', 20)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['student_notification_id', 'user_device_token_id']);
        });

        Schema::create('saved_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('saved_folder_id');
            $table->unsignedBigInteger('lesson_id');
            $table->timestamps();
        });

        Schema::create('saved_folder_lessons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('saved_folder_id');
            $table->unsignedBigInteger('lesson_id');
            $table->timestamps();
            $table->unique(['saved_folder_id', 'lesson_id']);
        });

        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_type')->default('text');
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('account_details');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('course_access_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('code', 32);
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->unsignedInteger('price_coins');
            $table->unsignedInteger('minimum_paid_coins')->default(0);
            $table->boolean('chat_enabled')->default(false);
            $table->unsignedInteger('chat_message_limit')->default(0);
            $table->unsignedBigInteger('chat_token_budget')->default(0);
            $table->decimal('ai_budget_usd', 12, 6)->default(0);
            $table->decimal('request_reserve_usd', 12, 6)->default(0);
            $table->unsignedBigInteger('project_feedback_token_budget')->default(0);
            $table->decimal('project_feedback_budget_usd', 12, 6)->default(0);
            $table->decimal('project_feedback_reserve_usd', 12, 6)->default(0);
            $table->unsignedInteger('project_followup_message_limit')->default(0);
            $table->unsignedBigInteger('project_followup_token_budget')->default(0);
            $table->decimal('project_followup_budget_usd', 12, 6)->default(0);
            $table->decimal('project_followup_reserve_usd', 12, 6)->default(0);
            $table->unsignedInteger('max_output_tokens')->default(320);
            $table->string('model_override')->nullable();
            $table->string('project_feedback_level', 24)->default('pass_only');
            $table->boolean('project_output_enabled')->default(false);
            $table->boolean('certificate_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->timestamps();
            $table->unique(['course_id', 'code']);
        });

        Schema::create('ai_entitlement_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->string('feature', 40);
            $table->unsignedInteger('used_requests')->default(0);
            $table->unsignedInteger('reserved_requests')->default(0);
            $table->unsignedInteger('unanswered_provider_requests')->default(0);
            $table->timestamp('unanswered_provider_last_at')->nullable();
            $table->timestamp('provider_exposure_paused_until')->nullable();
            $table->unsignedBigInteger('used_tokens')->default(0);
            $table->unsignedBigInteger('reserved_tokens')->default(0);
            $table->decimal('used_cost_usd', 12, 6)->default(0);
            $table->decimal('reserved_cost_usd', 12, 6)->default(0);
            $table->timestamps();
            $table->unique(['enrollment_id', 'feature']);
        });

        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->string('feature', 40);
            $table->string('model')->nullable();
            $table->string('status', 20)->default('reserved');
            $table->timestamp('reservation_expires_at')->nullable();
            $table->unsignedInteger('reserved_tokens')->default(0);
            $table->decimal('reserved_cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('fx_rate_to_egp', 12, 4)->nullable();
            $table->decimal('cost_egp', 14, 6)->nullable();
            $table->string('provider_request_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('student_section_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_section_id');
            $table->boolean('is_completed')->default(false);
            $table->string('status')->default('completed');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/2026_09_01_000071_create_social_identity_guards_table.php'))->up();
        (require database_path('migrations/2026_09_01_000068_add_portfolio_lifecycle_state.php'))->up();
        (require database_path('migrations/2026_09_01_000070_add_public_id_to_portfolio_media.php'))->up();
        (require database_path('migrations/2026_09_01_000073_create_portfolio_video_uploads_table.php'))->up();
        (require database_path('migrations/2026_09_01_000078_create_internal_signals_table.php'))->up();

    }

    private function tearDownSchema(): void
    {
        $tables = [
            'internal_signals', 'social_identity_guards', 'social_oauth_attempts', 'course_grant_claims', 'course_code_usages', 'student_section_progress', 'account_file_deletions', 'api_tokens', 'photos', 'verification_codes', 'user_device_tokens', 'deleted_social_reward_tombstones', 'social_accounts', 'user_coin_task_attempts', 'user_coin_earnings', 'coin_earning_methods',
            'notification_push_deliveries', 'admin_notifications', 'financial_entitlement_holds',
            'ai_usage_events', 'ai_entitlement_usages',
            'course_authoring_revision_entities', 'course_authoring_revisions',
            'watching_logs',
            'payment_methods', 'categories', 'portfolios', 'portfolio_video_uploads', 'portfolio_media', 'portfolio_items', 'saved_folder_lessons', 'saved_sections', 'wallet_transactions', 'reward_rules', 'user_reward_checkins', 'user_daily_learning_activities', 'playback_sessions', 'lesson_media_states', 'lesson_watch_evidence', 'lessons', 'saved_folders',
            'student_notifications', 'classification_user', 'classifications', 'paths', 'certificates',
            'course_pdfs', 'project_submissions', 'course_codes', 'bills', 'orders',
            'course_enrollments', 'course_access_plans', 'attachments', 'course_sections', 'course_modules', 'projects', 'course_ratings', 'course_teacher', 'classification_course', 'courses', 'grades', 'profile_update_receipts', 'users', 'settings'
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function setUpData(): void
    {
        DB::table('settings')->insert([
            'site_name_ar' => 'ركن',
            'site_name_en' => 'Rokn',
            'enforce_course_section_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('reward_rules')->insert([
            ['event_key' => 'welcome_bonus', 'title_ar' => 'هدية أول تسجيل', 'coins_amount' => 20, 'interval_count' => 1, 'daily_cap' => null, 'rolling_30_day_cap' => null, 'is_active' => 1, 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['event_key' => 'daily_checkin', 'title_ar' => 'فتح يومي', 'coins_amount' => 15, 'interval_count' => 1, 'daily_cap' => null, 'rolling_30_day_cap' => 150, 'is_active' => 1, 'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['event_key' => 'streak_milestone', 'title_ar' => 'استمرارية', 'coins_amount' => 100, 'interval_count' => 7, 'daily_cap' => null, 'rolling_30_day_cap' => 400, 'is_active' => 1, 'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['event_key' => 'study_session', 'title_ar' => 'دراسة', 'coins_amount' => 10, 'interval_count' => 5, 'daily_cap' => 20, 'rolling_30_day_cap' => 200, 'is_active' => 1, 'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],
            ['event_key' => 'first_project_passed', 'title_ar' => 'أول مشروع', 'coins_amount' => 150, 'interval_count' => 1, 'daily_cap' => null, 'rolling_30_day_cap' => 150, 'is_active' => 1, 'sort_order' => 50, 'created_at' => now(), 'updated_at' => now()],
            ['event_key' => 'course_completed', 'title_ar' => 'إنهاء كورس', 'coins_amount' => 200, 'interval_count' => 1, 'daily_cap' => null, 'rolling_30_day_cap' => 200, 'is_active' => 1, 'sort_order' => 60, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('coin_earning_methods')->insert([
            'action_key' => 'register',
            'coins_amount' => 20,
            'is_repeatable' => 0,
            'is_active' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->gradeId = (int) DB::table('grades')->insertGetId([
            'name_ar' => 'الصف الأول',
            'name_en' => 'Grade 1',
            'sort_order' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->courseId = (int) DB::table('courses')->insertGetId([
            'name_ar' => 'دورة تجريبية',
            'name_en' => 'Test Course',
            'description_ar' => 'وصف دورة تجريبية',
            'description_en' => 'Test course description',
            'image' => 'courses/test-course.jpg',
            'search_title_normalized' => 'دوره تجريبيه test course',
            'search_terms_normalized' => 'دوره تجريبيه test course وصف دوره تجريبيه test course description',
            'grade_id' => $this->gradeId,
            'price' => 100.00,
            'active' => 1,
            'is_main_course' => 1,
            'is_coming_soon' => 0,
            'is_catalog_visible' => 1,
            'course_type' => 'online',
            'rate' => 5.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->moduleId = (int) DB::table('course_modules')->insertGetId([
            'course_id' => $this->courseId,
            'title_ar' => 'الوحدة الأولى',
            'title_en' => 'Module 1',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->sectionId = (int) DB::table('course_sections')->insertGetId([
            'course_id' => $this->courseId,
            'module_id' => $this->moduleId,
            'title_ar' => 'قسم 1',
            'title_en' => 'Section 1',
            'section_type' => 'lesson',
            'sectionable_type' => \App\Models\Lesson::class,
            'sectionable_id' => 10,
            'order' => 1,
            'sort_order' => 1,
            'is_free' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->pathId = (int) DB::table('paths')->insertGetId([
            'title_ar' => 'مسار تجريبي',
            'title_en' => 'Test Path',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = new User();
        $this->user->name = 'API Test User';
        $this->user->phone = '01234567890';
        $this->user->email = 'test@rokn.com';
        $this->user->password = bcrypt('password123');
        $this->user->active = true;
        $this->user->wallet_coins = 1000;
        $this->user->wallet_reward_coins = 1000;
        $this->user->watch_history_enabled = true;
        $this->user->save();

        DB::table('wallet_transactions')->insert([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'direction' => 'credit',
            'category' => 'opening_balance',
            'bucket' => 'reward',
            'amount' => 1000,
            'paid_amount' => 0,
            'reward_amount' => 1000,
            'balance_after' => 1000,
            'paid_balance_after' => 0,
            'reward_balance_after' => 1000,
            'source_type' => null,
            'source_id' => null,
            'idempotency_key' => "wallet-opening:user:{$this->user->id}",
            'metadata' => json_encode(['fixture' => 'api_test_case'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $instructorId = (int) DB::table('users')->insertGetId([
            'name' => 'API Test Instructor',
            'email' => 'instructor@rokn.test',
            'role' => 'admin',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('courses')->where('id', $this->courseId)->update([
            'teacher_id' => $instructorId,
        ]);
        DB::table('course_teacher')->insert([
            'course_id' => $this->courseId,
            'teacher_id' => $instructorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Base fixtures needed across various controllers so they return valid responses instead of 404
        DB::table('course_codes')->insert([
            'code' => 'TESTCODE',
            'type' => 'course',
            'course_id' => $this->courseId,
            'is_active' => 1,
            'is_used' => 0,
            'used_count' => 0,
            'max_uses' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_notifications')->insert([
            'id' => 1,
            'user_id' => $this->user->id,
            'title_ar' => 'اشعار 1',
            'title_en' => 'Notification 1',
            'message_ar' => 'محتوى الاشعار',
            'message_en' => 'Notification body',
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('classifications')->insert([
            'id' => 1,
            'name_ar' => 'برمجة',
            'name_en' => 'Programming',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('classification_course')->insert([
            'classification_id' => 1,
            'course_id' => $this->courseId,
        ]);

        DB::table('course_access_plans')->insert(
            [
                'course_id' => $this->courseId,
                'code' => 'basic',
                'name_ar' => 'التعلّم',
                'name_en' => 'Learning',
                'price_coins' => 100,
                'minimum_paid_coins' => 0,
                'chat_enabled' => 0,
                'certificate_enabled' => 1,
                'is_active' => 1,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('course_access_plans')->insert(
            [
                'course_id' => $this->courseId,
                'code' => 'guided',
                'name_ar' => 'التعلّم بإرشاد',
                'name_en' => 'Guided learning',
                'price_coins' => 200,
                'minimum_paid_coins' => 100,
                'chat_enabled' => 1,
                'chat_message_limit' => 25,
                'chat_token_budget' => 12000,
                'ai_budget_usd' => .45,
                'request_reserve_usd' => .015,
                'project_feedback_level' => 'report',
                'project_feedback_token_budget' => 6000,
                'project_feedback_budget_usd' => .20,
                'project_feedback_reserve_usd' => .04,
                'certificate_enabled' => 1,
                'is_active' => 1,
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('portfolio_items')->insert([
            'id' => 1,
            'user_id' => $this->user->id,
            'title' => 'Sample Portfolio Item',
            'description' => 'A sample portfolio entry for testing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_folders')->insert([
            'id' => 1,
            'user_id' => $this->user->id,
            'name' => 'Folder 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => 10,
            'list_id' => $this->courseId,
            'course_id' => $this->courseId,
            'section_id' => $this->sectionId,
            'title' => 'Lesson 10',
            'title_ar' => 'الدرس 10',
            'title_en' => 'Lesson 10',
            'description' => 'Lesson 10 description',
            'duration_minutes' => 15,
            'is_opened' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_sections')->insert([
            'id' => 1,
            'saved_folder_id' => 1,
            'lesson_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_folder_lessons')->insert([
            'id' => 1,
            'saved_folder_id' => 1,
            'lesson_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    }
}
