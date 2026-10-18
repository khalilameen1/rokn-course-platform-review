<?php

use App\Http\Controllers\API\AdminNotificationsController;
use App\Http\Controllers\API\AiConsentController;
use App\Http\Controllers\API\AiContentReportController;
use App\Http\Controllers\API\AppVersionController;
use App\Http\Controllers\API\BunnyStreamWebhookController;
use App\Http\Controllers\API\CertificateController;
use App\Http\Controllers\API\ClassificationController;
use App\Http\Controllers\API\ClientEventController;
use App\Http\Controllers\API\CoinEarningMethodController;
use App\Http\Controllers\API\CourseChatController;
use App\Http\Controllers\API\CourseChatUpgradeController;
use App\Http\Controllers\API\CourseCheckoutController;
use App\Http\Controllers\API\CourseCodeController;
use App\Http\Controllers\API\CourseController;
use App\Http\Controllers\API\CoursePdfController;
use App\Http\Controllers\API\CoursePurchaseController;
use App\Http\Controllers\API\CourseRatingController;
use App\Http\Controllers\API\CourseSearchController;
use App\Http\Controllers\API\EngagementController;
use App\Http\Controllers\API\FeedbackController;
use App\Http\Controllers\API\GradeController;
use App\Http\Controllers\API\HomeController;
use App\Http\Controllers\API\LearningDashboardController;
use App\Http\Controllers\API\LearningRewardController;
use App\Http\Controllers\API\OperationalHealthController;
use App\Http\Controllers\API\PackageController;
use App\Http\Controllers\API\PathController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\PlaybackController;
use App\Http\Controllers\API\PlaybackMetricsController;
use App\Http\Controllers\API\PortfolioController;
use App\Http\Controllers\API\PortfolioMediaController;
use App\Http\Controllers\API\PortfolioProfileController;
use App\Http\Controllers\API\ProductEventController;
use App\Http\Controllers\API\ProductFeatureController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\ProjectController;
use App\Http\Controllers\API\PublicContentController;
use App\Http\Controllers\API\PublicPortfolioController;
use App\Http\Controllers\API\SavedSectionController;
use App\Http\Controllers\API\SignController;
use App\Http\Controllers\API\SocialOAuthController;
use App\Http\Controllers\API\StorePurchaseController;
use App\Http\Controllers\API\StoreServerNotificationController;
use App\Http\Controllers\API\StreakController;
use App\Http\Controllers\API\StudentNotificationController;
use App\Http\Controllers\API\TasksController;
use App\Http\Controllers\API\UserSessionController;
use App\Http\Controllers\API\VisitorController;
use App\Http\Controllers\API\WalletController;
use App\Http\Controllers\API\WatchHistoryController;
use App\Http\Controllers\API\WhatsAppConnectionController;
use Illuminate\Support\Facades\Route;

Route::get('health/live', [OperationalHealthController::class, 'live']);
Route::get('health/ready', [OperationalHealthController::class, 'ready']);
Route::get('health/launch-ready', [OperationalHealthController::class, 'launchReady']);
Route::post('store-notifications/google', [StoreServerNotificationController::class, 'google'])
    ->middleware(['recovery.write', 'throttle:store-notification']);
Route::post('store-notifications/apple', [StoreServerNotificationController::class, 'apple'])
    ->middleware(['recovery.write', 'throttle:store-notification']);
Route::post('integrations/bunny/stream', BunnyStreamWebhookController::class)
    ->middleware('throttle:240,1');
Route::get('project-input-attachments/{attachment}/download', [ProjectController::class, 'downloadInputAttachment'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('api.project-input-attachments.download');
Route::get('feedback/{publicId}/attachments/{attachment}', [FeedbackController::class, 'attachment'])
    ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')
    ->whereNumber('attachment')
    ->middleware(['signed', 'throttle:30,1'])
    ->name('api.feedback.attachment');

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

$registerCourseApiRoutes = function () {
    Route::post('whatsapp/webhook', [WhatsAppConnectionController::class, 'webhook'])
        ->middleware('throttle:whatsapp-webhook');
    Route::get('product-features', [ProductFeatureController::class, 'index'])
        ->middleware('throttle:60,1');
    Route::post('client-events', [ClientEventController::class, 'store'])
        ->middleware('throttle:client-events');
    Route::post('product-events', [ProductEventController::class, 'store'])
        ->middleware('throttle:product-events');
    Route::group(['middleware' => ['WebsiteVisitorCount']], function () {
        Route::get('settings', [HomeController::class, 'settings']);
        Route::get('content/pages/{page}', [PublicContentController::class, 'show'])
            ->whereIn('page', ['about', 'privacy', 'terms', 'returns', 'contact']);
        Route::post('contact', [TasksController::class, 'contact'])
            ->middleware('throttle:5,1');
        Route::get('economy-config', [LearningRewardController::class, 'configuration']);
        Route::get('public/portfolios/{slug}', [PublicPortfolioController::class, 'show']);

        // App Version Check
        Route::post('app/check-version', [AppVersionController::class, 'checkVersion']);

        /* ====== Sign ======= */
        Route::get('auth-methods', [SignController::class, 'authMethods'])->name('api.auth-methods');
        Route::post('social-login', [SignController::class, 'socialLogin'])->middleware('throttle:auth-api')->name('api.social-login');
        Route::get('social-auth/{socialProvider}/start', [SocialOAuthController::class, 'start'])
            ->middleware('throttle:auth-api')
            ->name('api.social.start');
        Route::get('social-auth/{socialProvider}/callback', [SocialOAuthController::class, 'callback'])
            ->middleware('throttle:auth-api')
            ->name('api.social.callback');
        Route::post('social-auth/complete', [SocialOAuthController::class, 'complete'])
            ->middleware('throttle:auth-api')
            ->name('api.social.complete');
        Route::get('engagement/messages/{systemKey}', [AdminNotificationsController::class, 'message'])
            ->whereIn('systemKey', ['guest_registration_prompt', 'welcome_bonus_received']);

        Route::middleware('auth:api')->group(function () {
            Route::get('ai-consent', [AiConsentController::class, 'show']);
            Route::put('ai-consent', [AiConsentController::class, 'update'])
                ->middleware('throttle:10,1');
            Route::post('ai-content-reports', [AiContentReportController::class, 'store'])
                ->middleware('throttle:10,1');
            /* ----Logout------ */
            Route::post('logout', [SignController::class, 'logout']);
            Route::post('delete-account', [SignController::class, 'deleteAccount'])->name('delete');
            Route::post('user/device-token', [SignController::class, 'updateDeviceToken']);
            Route::delete('user/device-token', [SignController::class, 'deleteDeviceToken']);
            Route::get('user/sessions', [UserSessionController::class, 'index']);
            Route::delete('user/sessions', [UserSessionController::class, 'destroyOthers'])
                ->middleware('throttle:6,1');
            Route::delete('user/sessions/{sessionId}', [UserSessionController::class, 'destroy'])
                ->whereUuid('sessionId')
                ->middleware('throttle:10,1');

            // Student Notifications
            Route::get('notifications', [StudentNotificationController::class, 'getAll']);
            Route::get('notifications/{id}', [StudentNotificationController::class, 'show'])
                ->whereNumber('id');
            Route::post('notifications/{id}/mark-read', [StudentNotificationController::class, 'markAsRead']);
            Route::post('notifications/mark-all-read', [StudentNotificationController::class, 'markAllAsRead']);

            // Profile
            Route::get('user/profile', [ProfileController::class, 'index']);
            Route::get('user/paths', [PathController::class, 'userPaths']);
            Route::put('user/profile', [ProfileController::class, 'update']);
            Route::post('user/profile', [ProfileController::class, 'update']);
            Route::post('user/interests', [ProfileController::class, 'updateInterests']);
            Route::get('user/watch-history', [WatchHistoryController::class, 'index']);
            Route::post('user/watch-history', [WatchHistoryController::class, 'store']);
            Route::post('lessons/{lesson}/playback-manifest', [PlaybackController::class, 'manifest'])
                ->middleware(['product.feature:playback', 'throttle:60,1']);
            Route::get('internal/playback/metrics', [PlaybackMetricsController::class, 'index'])
                ->middleware(['admin.only', 'throttle:30,1']);
            Route::delete('user/watch-history', [ProfileController::class, 'clearWatchHistory']);
            // Project Routes
            Route::get('projects/{project}', [ProjectController::class, 'show']);
            Route::post('projects/{project}/submissions', [ProjectController::class, 'submit'])
                ->middleware(['product.feature:project_uploads', 'ai.consent', 'throttle:8,1']);
            Route::get('projects/{project}/submissions/lookup', [ProjectController::class, 'lookupSubmission'])
                ->whereNumber('project');
            Route::get('project-submissions/{submission}', [ProjectController::class, 'submissionStatus']);
            Route::post('project-submissions/{submission}/review/retry', [ProjectController::class, 'retryEvaluation'])
                ->middleware(['product.feature:project_uploads', 'ai.consent', 'recovery.write', 'throttle:3,1']);
            Route::post('project-submissions/{submission}/report/retry', [ProjectController::class, 'retryInitialReport'])
                ->middleware(['product.feature:ai_chat', 'ai.consent', 'recovery.write', 'throttle:3,1']);
            Route::get('project-feedback-threads/{thread}', [ProjectController::class, 'feedbackThread']);
            Route::post('project-feedback-threads/{thread}/messages', [ProjectController::class, 'sendFeedbackMessage'])
                ->middleware(['ai.consent', 'throttle:20,1']);
            Route::post('project-feedback-threads/{thread}/attachments', [ProjectController::class, 'uploadFeedbackAttachment'])
                ->middleware(['ai.consent', 'throttle:20,1']);
            // Certificates
            Route::get('certificates', [CertificateController::class, 'index']);
            Route::get('certificates/{courseId}', [CertificateController::class, 'show'])
                ->whereNumber('courseId');
            // Explicit idempotent issue/recovery action. Completion only
            // establishes eligibility; the learner confirms the printed
            // name before this route reserves the immutable credential.
            Route::post('certificates/{courseId}/issue', [CertificateController::class, 'issue'])
                ->whereNumber('courseId')
                ->middleware('throttle:6,1');

            // Streaks
            Route::get('streaks', [StreakController::class, 'index']);

            // Portfolio
            Route::get('portfolio', [PortfolioController::class, 'index']);
            Route::get('portfolio/upload-access', [PortfolioController::class, 'uploadAccess']);
            Route::get('portfolio/eligible-projects', [PortfolioController::class, 'eligibleProjects']);
            Route::get('portfolio-profile', [PortfolioProfileController::class, 'show']);
            Route::put('portfolio-profile', [PortfolioProfileController::class, 'update']);
            Route::post('portfolio', [PortfolioController::class, 'store']);
            Route::get('portfolio/{id}', [PortfolioController::class, 'show']);
            Route::post('portfolio/{id}', [PortfolioController::class, 'update']); // Using POST for file update
            Route::post('portfolio/{id}/finalize', [PortfolioController::class, 'finalize']);
            Route::delete('portfolio/{id}', [PortfolioController::class, 'destroy']);

            // Portfolio Media
            Route::post('portfolio/{id}/media', [PortfolioMediaController::class, 'append']);
            Route::post('portfolio/{id}/media/video-uploads', [PortfolioMediaController::class, 'issueVideoUpload'])
                ->middleware('throttle:10,1');
            Route::post('portfolio/{id}/media/video-uploads/renew', [PortfolioMediaController::class, 'renewVideoUpload'])
                ->middleware('throttle:60,1');
            Route::post('portfolio/{id}/media/video-uploads/claim', [PortfolioMediaController::class, 'claimVideoUpload'])
                ->middleware('throttle:20,1');
            Route::delete('portfolio/{id}/media/{mediaId}', [PortfolioMediaController::class, 'destroy']);

            // Coin Earning Methods
            Route::get('coin-earning-methods', [CoinEarningMethodController::class, 'index']);
            Route::post('coin-earning-methods/{method}/start', [CoinEarningMethodController::class, 'start']);
            Route::post('claim-coins', [CoinEarningMethodController::class, 'claim']);
            Route::get('engagement/next', [EngagementController::class, 'next']);
            Route::get('whatsapp-connection', [WhatsAppConnectionController::class, 'show']);
            Route::put('whatsapp-connection/consent', [WhatsAppConnectionController::class, 'consent'])->middleware('throttle:10,1');

            // Wallet ledger
            Route::get('wallet', [WalletController::class, 'show']);
            Route::get('wallet/transactions', [WalletController::class, 'transactions']);
            Route::get('learning/courses', [LearningDashboardController::class, 'courses']);
            Route::post('rewards/daily', [LearningRewardController::class, 'daily']);

            // Course Codes routes
            Route::post('course-codes/redeem', [CourseCodeController::class, 'redeem'])
                ->middleware('throttle:10,1');
            Route::post('course-codes/check', [CourseCodeController::class, 'check'])
                ->middleware('throttle:20,1');
            Route::get('course-codes/my-codes', [CourseCodeController::class, 'myCodes']);

            // Course Authorization routes
            Route::get('course-checkouts', [CourseCheckoutController::class, 'latest'])
                ->middleware(['product.feature:checkout', 'throttle:60,1']);
            Route::post('course-checkouts', [CourseCheckoutController::class, 'create'])
                ->middleware(['product.feature:checkout', 'throttle:30,1']);
            Route::get('course-checkouts/{checkout}', [CourseCheckoutController::class, 'show'])
                ->whereUuid('checkout')->middleware(['product.feature:checkout', 'throttle:60,1']);
            Route::post('course-checkouts/{checkout}/authorize', [CourseCheckoutController::class, 'authorizeCheckout'])
                ->whereUuid('checkout')->middleware(['product.feature:checkout', 'throttle:12,1']);
            Route::post('course-checkouts/{checkout}/resume', [CourseCheckoutController::class, 'resume'])
                ->whereUuid('checkout')->middleware(['product.feature:checkout', 'throttle:30,1']);
            Route::post('course-checkouts/{checkout}/cancel', [CourseCheckoutController::class, 'cancel'])
                ->whereUuid('checkout')->middleware(['product.feature:checkout', 'throttle:20,1']);
            Route::post('courses/purchase-quote', [CoursePurchaseController::class, 'quote'])
                ->middleware('throttle:20,1');
            Route::post('courses/authorize', [CoursePurchaseController::class, 'authorizeCourse'])
                ->middleware(['product.feature:checkout', 'throttle:6,1']);
            Route::get('course-chat/messages', [CourseChatController::class, 'history'])
                ->middleware(['product.feature:ai_chat', 'throttle:30,1']);
            Route::get('course-chat/turns/{clientRequestId}', [CourseChatController::class, 'status'])
                ->whereUuid('clientRequestId')
                ->middleware(['product.feature:ai_chat', 'throttle:60,1']);
            Route::delete('course-chat/turns/{clientRequestId}', [CourseChatController::class, 'cancel'])
                ->whereUuid('clientRequestId')
                ->middleware(['product.feature:ai_chat', 'throttle:20,1']);
            Route::post('courses/{course}/chat', [CourseChatController::class, 'sendForCourse'])
                ->middleware(['product.feature:ai_chat', 'ai.consent', 'throttle:12,1']);
            Route::post('courses/{course}/chat/attachments', [CourseChatController::class, 'uploadAttachment'])
                ->middleware(['product.feature:ai_chat', 'ai.consent', 'throttle:20,1']);
            Route::get('ai-input-attachments/{attachment}', [ProjectController::class, 'showInputAttachment'])
                ->middleware('throttle:60,1');
            // Upgrade offer quote only; upgrades are bought via course-checkouts (mode=upgrade).
            Route::get('courses/{course}/full-track-upgrade', [CourseChatUpgradeController::class, 'quote']);

            Route::get('courses/{courseId}/progress', [CourseController::class, 'getCourseProgress']);
            Route::post('courses/{courseId}/sections/{sectionId}/complete', [CourseController::class, 'markSectionComplete']);

            // Metadata stays authenticated. Files use short-lived signed
            // downloads and the device's system viewer.
            Route::get('courses/{courseId}/pdfs', [CoursePdfController::class, 'index']);
            Route::get('courses/{courseId}/pdfs/{pdfId}', [CoursePdfController::class, 'show']);
            // Course Rating
            Route::post('courses/{courseId}/rate', [CourseRatingController::class, 'store'])
                ->middleware('throttle:12,1');
            Route::delete('courses/{courseId}/rate', [CourseRatingController::class, 'destroy'])
                ->middleware('throttle:12,1');

            // Saved Sections (Lesson Bookmarks)
            Route::get('saved-folders', [SavedSectionController::class, 'getFolders']);
            Route::get('saved-lessons', [SavedSectionController::class, 'getSavedLessons']);
            Route::get('saved-lessons/state', [SavedSectionController::class, 'getSavedLessonState']);
            Route::post('saved-folders', [SavedSectionController::class, 'createFolder']);
            Route::get('saved-lessons/{lessonId}/folders', [SavedSectionController::class, 'getLessonFolders']);
            Route::get('saved-folders/{id}/lessons', [SavedSectionController::class, 'getFolderLessons']);
            Route::get('saved-folders/{id}', [SavedSectionController::class, 'getFolder']);
            Route::delete('saved-folders/{id}', [SavedSectionController::class, 'deleteFolder']);
            Route::post('saved-folders/{id}/lessons', [SavedSectionController::class, 'saveLesson']);
            Route::delete('saved-folders/{id}/lessons/{lessonId}', [SavedSectionController::class, 'removeLesson']);
            Route::delete('saved-lessons/{lessonId}', [SavedSectionController::class, 'removeLessonEverywhere']);

            // Kashier Package Payment (authenticated — mobile app uses these)
            Route::post('payment/initiate', [PaymentController::class, 'initiate'])
                ->middleware(['product.feature:checkout', 'throttle:payment-write']);
            Route::get('payment/status/{orderRef}', [PaymentController::class, 'status'])
                ->middleware('throttle:payment-read')
                ->name('api.payment.status');
            Route::post('payment/reconcile/{orderRef}', [PaymentController::class, 'reconcile'])
                ->middleware(['recovery.write', 'throttle:payment-reconcile'])
                ->name('api.payment.reconcile');
            Route::post('payment/abandon/{orderRef}', [PaymentController::class, 'abandon'])
                ->middleware(['recovery.write', 'throttle:payment-reconcile'])
                ->name('api.payment.abandon');
            Route::get('store-billing/context', [StorePurchaseController::class, 'context'])
                ->middleware(['product.feature:checkout', 'throttle:payment-read']);
            Route::post('store-purchases/verify', [StorePurchaseController::class, 'verify'])
                ->middleware(['product.feature:checkout', 'throttle:payment-write']);

        });

        Route::get('courses/{course}/pdfs/{pdf}/download', [CoursePdfController::class, 'download'])
            ->whereNumber('course')
            ->whereNumber('pdf')
            ->middleware(['signed', 'throttle:30,1'])
            ->name('api.course-pdfs.download');

        // Visitor Statistics Routes
        Route::get('visitors/stats', [VisitorController::class, 'getStats'])
            ->middleware(['auth:api', 'admin']);
        Route::get('visitors/recent', [VisitorController::class, 'getRecentVisitors'])
            ->middleware(['auth:api', 'admin']);

        // Grades routes
        Route::apiResource('grades', GradeController::class)
            ->only(['index', 'show']);
        Route::get('grades/{grade}/courses', [GradeController::class, 'courses']);

        Route::get('feedback', [FeedbackController::class, 'index'])
            ->middleware(['auth.optional', 'throttle:feedback']);
        Route::post('feedback', [FeedbackController::class, 'store'])
            ->middleware(['auth.optional', 'recovery.write', 'throttle:feedback']);
        Route::get('feedback/{publicId}', [FeedbackController::class, 'show'])
            ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['auth.optional', 'throttle:feedback']);
        Route::post('feedback/{publicId}/messages', [FeedbackController::class, 'reply'])
            ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['auth.optional', 'recovery.write', 'throttle:feedback']);
        Route::post('feedback/{publicId}/claim', [FeedbackController::class, 'claim'])
            ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['auth:api', 'recovery.write', 'throttle:feedback']);

        // Compact, ranked catalogue search for the app search overlay.
        Route::get('search/courses', CourseSearchController::class)
            ->middleware('throttle:catalog-search');

        // Course Listing and Details routes
        Route::get('courses/list', [CourseController::class, 'listCourses']);
        Route::get('courses/{courseId}/details', [CourseController::class, 'viewCourseDetails'])
            ->middleware('auth.optional');

        // Packages
        Route::get('packages', [PackageController::class, 'index']);
        Route::get('packages/{id}', [PackageController::class, 'show']);
        // Classifications
        Route::get('classifications', [ClassificationController::class, 'index']);
        // Paths
        Route::apiResource('paths', PathController::class, ['only' => ['index', 'show']]);

    });
};

Route::prefix('v1')->group($registerCourseApiRoutes);
