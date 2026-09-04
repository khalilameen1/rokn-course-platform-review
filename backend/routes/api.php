<?php

use Illuminate\Support\Facades\Route;

Route::get('health/live', [\App\Http\Controllers\API\OperationalHealthController::class, 'live']);
Route::get('health/ready', [\App\Http\Controllers\API\OperationalHealthController::class, 'ready']);
Route::get('health/launch-ready', [\App\Http\Controllers\API\OperationalHealthController::class, 'launchReady']);
Route::post('store-notifications/google', [\App\Http\Controllers\API\StoreServerNotificationController::class, 'google'])
    ->middleware(['recovery.write', 'throttle:store-notification']);
Route::post('store-notifications/apple', [\App\Http\Controllers\API\StoreServerNotificationController::class, 'apple'])
    ->middleware(['recovery.write', 'throttle:store-notification']);
Route::post('integrations/bunny/stream', \App\Http\Controllers\API\BunnyStreamWebhookController::class)
    ->middleware('throttle:240,1');
Route::get('project-input-attachments/{attachment}/download', [\App\Http\Controllers\API\ProjectController::class, 'downloadInputAttachment'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('api.project-input-attachments.download');
Route::get('feedback/{publicId}/attachments/{attachment}', [\App\Http\Controllers\API\FeedbackController::class, 'attachment'])
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
        Route::post('whatsapp/webhook', [\App\Http\Controllers\API\WhatsAppConnectionController::class, 'webhook'])
            ->middleware('throttle:whatsapp-webhook');
        Route::get('product-features', [\App\Http\Controllers\API\ProductFeatureController::class, 'index'])
            ->middleware('throttle:60,1');
        Route::post('client-events', [\App\Http\Controllers\API\ClientEventController::class, 'store'])
            ->middleware('throttle:client-events');
        Route::post('product-events', [\App\Http\Controllers\API\ProductEventController::class, 'store'])
            ->middleware('throttle:product-events');
        Route::group(['middleware' => ['WebsiteVisitorCount']], function () {
            Route::get('settings', [\App\Http\Controllers\API\HomeController::class,'settings']);
            Route::get('content/pages/{page}', [\App\Http\Controllers\API\PublicContentController::class, 'show'])
                ->whereIn('page', ['about', 'privacy', 'terms', 'returns', 'contact']);
            Route::post('contact', [\App\Http\Controllers\API\TasksController::class, 'contact'])
                ->middleware('throttle:5,1');
            Route::get('economy-config', [\App\Http\Controllers\API\LearningRewardController::class, 'configuration']);
            Route::get('public/portfolios/{slug}', [\App\Http\Controllers\API\PublicPortfolioController::class, 'show']);

            // App Version Check
            Route::post('app/check-version', [\App\Http\Controllers\API\AppVersionController::class, 'checkVersion']);

            /* ====== Sign =======*/
            Route::get('auth-methods', [\App\Http\Controllers\API\SignController::class,'authMethods'])->name('api.auth-methods');
            Route::post('social-login', [\App\Http\Controllers\API\SignController::class,'socialLogin'])->middleware('throttle:auth-api')->name('api.social-login');
            Route::get('social-auth/{socialProvider}/start', [\App\Http\Controllers\API\SocialOAuthController::class, 'start'])
                ->middleware('throttle:auth-api')
                ->name('api.social.start');
            Route::get('social-auth/{socialProvider}/callback', [\App\Http\Controllers\API\SocialOAuthController::class, 'callback'])
                ->middleware('throttle:auth-api')
                ->name('api.social.callback');
            Route::post('social-auth/complete', [\App\Http\Controllers\API\SocialOAuthController::class, 'complete'])
                ->middleware('throttle:auth-api')
                ->name('api.social.complete');
            Route::get('engagement/messages/{systemKey}', [\App\Http\Controllers\API\AdminNotificationsController::class, 'message'])
                ->whereIn('systemKey', ['guest_registration_prompt', 'welcome_bonus_received']);

            Route::middleware('auth:api')->group(function () {
                /*----Logout------*/
                Route::post('logout', [\App\Http\Controllers\API\SignController::class,'logout']);
                Route::post('delete-account', [\App\Http\Controllers\API\SignController::class,'deleteAccount'])->name('delete');
                Route::post('user/device-token', [\App\Http\Controllers\API\SignController::class, 'updateDeviceToken']);
                Route::delete('user/device-token', [\App\Http\Controllers\API\SignController::class, 'deleteDeviceToken']);
                Route::get('user/sessions', [\App\Http\Controllers\API\UserSessionController::class, 'index']);
                Route::delete('user/sessions', [\App\Http\Controllers\API\UserSessionController::class, 'destroyOthers'])
                    ->middleware('throttle:6,1');
                Route::delete('user/sessions/{sessionId}', [\App\Http\Controllers\API\UserSessionController::class, 'destroy'])
                    ->whereUuid('sessionId')
                    ->middleware('throttle:10,1');

                // Student Notifications
                Route::get('notifications', [\App\Http\Controllers\API\StudentNotificationController::class, 'getAll']);
                Route::get('notifications/{id}', [\App\Http\Controllers\API\StudentNotificationController::class, 'show'])
                    ->whereNumber('id');
                Route::post('notifications/{id}/mark-read', [\App\Http\Controllers\API\StudentNotificationController::class, 'markAsRead']);
                Route::post('notifications/mark-all-read', [\App\Http\Controllers\API\StudentNotificationController::class, 'markAllAsRead']);

                // Profile
                Route::get('user/profile', [\App\Http\Controllers\API\ProfileController::class,'index']);
                Route::get('user/paths', [\App\Http\Controllers\API\PathController::class, 'userPaths']);
                Route::put('user/profile', [\App\Http\Controllers\API\ProfileController::class,'update']);
                Route::post('user/profile', [\App\Http\Controllers\API\ProfileController::class,'update']);
                Route::post('user/interests', [\App\Http\Controllers\API\ProfileController::class,'updateInterests']);
                Route::get('user/watch-history', [\App\Http\Controllers\API\WatchHistoryController::class, 'index']);
                Route::post('user/watch-history', [\App\Http\Controllers\API\WatchHistoryController::class, 'store']);
                Route::post('lessons/{lesson}/playback-manifest', [\App\Http\Controllers\API\PlaybackController::class, 'manifest'])
                    ->middleware(['product.feature:playback', 'throttle:60,1']);
                Route::get('internal/playback/metrics', [\App\Http\Controllers\API\PlaybackMetricsController::class, 'index'])
                    ->middleware(['admin.only', 'throttle:30,1']);
                Route::delete('user/watch-history', [\App\Http\Controllers\API\ProfileController::class, 'clearWatchHistory']);
                // Project Routes
                Route::get('projects/{project}', [\App\Http\Controllers\API\ProjectController::class, 'show']);
                Route::post('projects/{project}/submissions', [\App\Http\Controllers\API\ProjectController::class, 'submit'])
                    ->middleware(['product.feature:project_uploads', 'throttle:8,1']);
                Route::get('project-submissions/{submission}', [\App\Http\Controllers\API\ProjectController::class, 'submissionStatus']);
                Route::post('project-submissions/{submission}/report/retry', [\App\Http\Controllers\API\ProjectController::class, 'retryInitialReport'])
                    ->middleware(['product.feature:ai_chat', 'recovery.write', 'throttle:3,1']);
                Route::get('project-feedback-threads/{thread}', [\App\Http\Controllers\API\ProjectController::class, 'feedbackThread']);
                Route::post('project-feedback-threads/{thread}/messages', [\App\Http\Controllers\API\ProjectController::class, 'sendFeedbackMessage'])
                    ->middleware('throttle:20,1');
                Route::post('project-feedback-threads/{thread}/attachments', [\App\Http\Controllers\API\ProjectController::class, 'uploadFeedbackAttachment'])
                    ->middleware('throttle:20,1');
                // Certificates
                Route::get('certificates', [\App\Http\Controllers\API\CertificateController::class, 'index']);
                Route::get('certificates/{courseId}', [\App\Http\Controllers\API\CertificateController::class, 'show'])
                    ->whereNumber('courseId');
                // Explicit idempotent issue/recovery action. Completion only
                // establishes eligibility; the learner confirms the printed
                // name before this route reserves the immutable credential.
                Route::post('certificates/{courseId}/issue', [\App\Http\Controllers\API\CertificateController::class, 'issue'])
                    ->whereNumber('courseId')
                    ->middleware('throttle:6,1');

                // Streaks
                Route::get('streaks', [\App\Http\Controllers\API\StreakController::class, 'index']);

                // Portfolio
                Route::get('portfolio', [\App\Http\Controllers\API\PortfolioController::class, 'index']);
                Route::get('portfolio/eligible-projects', [\App\Http\Controllers\API\PortfolioController::class, 'eligibleProjects']);
                Route::get('portfolio-profile', [\App\Http\Controllers\API\PortfolioProfileController::class, 'show']);
                Route::put('portfolio-profile', [\App\Http\Controllers\API\PortfolioProfileController::class, 'update']);
                Route::post('portfolio', [\App\Http\Controllers\API\PortfolioController::class, 'store']);
                Route::get('portfolio/{id}', [\App\Http\Controllers\API\PortfolioController::class, 'show']);
                Route::post('portfolio/{id}', [\App\Http\Controllers\API\PortfolioController::class, 'update']); // Using POST for file update
                Route::post('portfolio/{id}/finalize', [\App\Http\Controllers\API\PortfolioController::class, 'finalize']);
                Route::delete('portfolio/{id}', [\App\Http\Controllers\API\PortfolioController::class, 'destroy']);

                // Portfolio Media
                Route::post('portfolio/{id}/media', [\App\Http\Controllers\API\PortfolioMediaController::class, 'append']);
                Route::post('portfolio/{id}/media/video-uploads', [\App\Http\Controllers\API\PortfolioMediaController::class, 'issueVideoUpload'])
                    ->middleware('throttle:10,1');
                Route::post('portfolio/{id}/media/video-uploads/renew', [\App\Http\Controllers\API\PortfolioMediaController::class, 'renewVideoUpload'])
                    ->middleware('throttle:60,1');
                Route::post('portfolio/{id}/media/video-uploads/claim', [\App\Http\Controllers\API\PortfolioMediaController::class, 'claimVideoUpload'])
                    ->middleware('throttle:20,1');
                Route::delete('portfolio/{id}/media/{mediaId}', [\App\Http\Controllers\API\PortfolioMediaController::class, 'destroy']);

                // Coin Earning Methods
                Route::get('coin-earning-methods', [\App\Http\Controllers\API\CoinEarningMethodController::class, 'index']);
                Route::post('coin-earning-methods/{method}/start', [\App\Http\Controllers\API\CoinEarningMethodController::class, 'start']);
                Route::post('claim-coins', [\App\Http\Controllers\API\CoinEarningMethodController::class, 'claim']);
                Route::get('engagement/next', [\App\Http\Controllers\API\EngagementController::class, 'next']);
                Route::get('whatsapp-connection', [\App\Http\Controllers\API\WhatsAppConnectionController::class, 'show']);
                Route::put('whatsapp-connection/consent', [\App\Http\Controllers\API\WhatsAppConnectionController::class, 'consent'])->middleware('throttle:10,1');

                // Wallet ledger
                Route::get('wallet', [\App\Http\Controllers\API\WalletController::class, 'show']);
                Route::get('wallet/transactions', [\App\Http\Controllers\API\WalletController::class, 'transactions']);
                Route::get('learning/courses', [\App\Http\Controllers\API\LearningDashboardController::class, 'courses']);
                Route::post('rewards/daily', [\App\Http\Controllers\API\LearningRewardController::class, 'daily']);

                // Course Codes routes
                Route::post('course-codes/redeem', [\App\Http\Controllers\API\CourseCodeController::class,'redeem'])
                    ->middleware('throttle:10,1');
                Route::post('course-codes/check', [\App\Http\Controllers\API\CourseCodeController::class,'check'])
                    ->middleware('throttle:20,1');
                Route::get('course-codes/my-codes', [\App\Http\Controllers\API\CourseCodeController::class,'myCodes']);

                // Course Authorization routes
                Route::post('courses/purchase-quote', [\App\Http\Controllers\API\CoursePurchaseController::class,'quote'])
                    ->middleware('throttle:20,1');
                Route::post('courses/authorize', [\App\Http\Controllers\API\CoursePurchaseController::class,'authorizeCourse'])
                    ->middleware(['product.feature:checkout', 'throttle:6,1']);
                Route::get('course-chat/messages', [\App\Http\Controllers\API\CourseChatController::class, 'history'])
                    ->middleware(['product.feature:ai_chat', 'throttle:30,1']);
                Route::get('course-chat/turns/{clientRequestId}', [\App\Http\Controllers\API\CourseChatController::class, 'status'])
                    ->whereUuid('clientRequestId')
                    ->middleware(['product.feature:ai_chat', 'throttle:60,1']);
                Route::delete('course-chat/turns/{clientRequestId}', [\App\Http\Controllers\API\CourseChatController::class, 'cancel'])
                    ->whereUuid('clientRequestId')
                    ->middleware(['product.feature:ai_chat', 'throttle:20,1']);
                Route::post('courses/{course}/chat', [\App\Http\Controllers\API\CourseChatController::class, 'sendForCourse'])
                    ->middleware(['product.feature:ai_chat', 'throttle:12,1']);
                Route::post('courses/{course}/chat/attachments', [\App\Http\Controllers\API\CourseChatController::class, 'uploadAttachment'])
                    ->middleware(['product.feature:ai_chat', 'throttle:20,1']);
                Route::get('ai-input-attachments/{attachment}', [\App\Http\Controllers\API\ProjectController::class, 'showInputAttachment'])
                    ->middleware('throttle:60,1');
                Route::get('courses/{course}/chat-upgrade', [\App\Http\Controllers\API\CourseChatUpgradeController::class, 'quote']);
                Route::post('courses/{course}/chat-upgrade', [\App\Http\Controllers\API\CourseChatUpgradeController::class, 'purchase'])
                    ->middleware(['product.feature:checkout', 'throttle:6,1']);
                Route::get('courses/{course}/full-track-upgrade', [\App\Http\Controllers\API\CourseChatUpgradeController::class, 'quote']);
                Route::post('courses/{course}/full-track-upgrade', [\App\Http\Controllers\API\CourseChatUpgradeController::class, 'purchase'])
                    ->middleware(['product.feature:checkout', 'throttle:6,1']);


                Route::get('courses/{courseId}/progress', [\App\Http\Controllers\API\CourseController::class,'getCourseProgress']);
                Route::post('courses/{courseId}/sections/{sectionId}/complete', [\App\Http\Controllers\API\CourseController::class,'markSectionComplete']);

                // Metadata stays authenticated. Files use short-lived signed
                // downloads and the device's system viewer.
                Route::get('courses/{courseId}/pdfs', [\App\Http\Controllers\API\CoursePdfController::class,'index']);
                Route::get('courses/{courseId}/pdfs/{pdfId}', [\App\Http\Controllers\API\CoursePdfController::class,'show']);
                // Course Rating
                Route::post('courses/{courseId}/rate', [\App\Http\Controllers\API\CourseRatingController::class, 'store'])
                    ->middleware('throttle:12,1');
                Route::delete('courses/{courseId}/rate', [\App\Http\Controllers\API\CourseRatingController::class, 'destroy'])
                    ->middleware('throttle:12,1');

                // Saved Sections (Lesson Bookmarks)
                Route::get('saved-folders', [\App\Http\Controllers\API\SavedSectionController::class, 'getFolders']);
                Route::get('saved-lessons', [\App\Http\Controllers\API\SavedSectionController::class, 'getSavedLessons']);
                Route::get('saved-lessons/state', [\App\Http\Controllers\API\SavedSectionController::class, 'getSavedLessonState']);
                Route::post('saved-folders', [\App\Http\Controllers\API\SavedSectionController::class, 'createFolder']);
                Route::get('saved-lessons/{lessonId}/folders', [\App\Http\Controllers\API\SavedSectionController::class, 'getLessonFolders']);
                Route::get('saved-folders/{id}/lessons', [\App\Http\Controllers\API\SavedSectionController::class, 'getFolderLessons']);
                Route::get('saved-folders/{id}', [\App\Http\Controllers\API\SavedSectionController::class, 'getFolder']);
                Route::delete('saved-folders/{id}', [\App\Http\Controllers\API\SavedSectionController::class, 'deleteFolder']);
                Route::post('saved-folders/{id}/lessons', [\App\Http\Controllers\API\SavedSectionController::class, 'saveLesson']);
                Route::delete('saved-folders/{id}/lessons/{lessonId}', [\App\Http\Controllers\API\SavedSectionController::class, 'removeLesson']);
                Route::delete('saved-lessons/{lessonId}', [\App\Http\Controllers\API\SavedSectionController::class, 'removeLessonEverywhere']);

                // Kashier Package Payment (authenticated — mobile app uses these)
                Route::post('payment/initiate', [\App\Http\Controllers\API\PaymentController::class, 'initiate'])
                    ->middleware(['product.feature:checkout', 'throttle:payment-write']);
                Route::get('payment/status/{orderRef}', [\App\Http\Controllers\API\PaymentController::class, 'status'])
                    ->middleware('throttle:payment-read')
                    ->name('api.payment.status');
                Route::post('payment/reconcile/{orderRef}', [\App\Http\Controllers\API\PaymentController::class, 'reconcile'])
                    ->middleware(['recovery.write', 'throttle:payment-reconcile'])
                    ->name('api.payment.reconcile');
                Route::post('payment/abandon/{orderRef}', [\App\Http\Controllers\API\PaymentController::class, 'abandon'])
                    ->middleware(['recovery.write', 'throttle:payment-reconcile'])
                    ->name('api.payment.abandon');
                Route::get('store-billing/context', [\App\Http\Controllers\API\StorePurchaseController::class, 'context'])
                    ->middleware(['product.feature:checkout', 'throttle:payment-read']);
                Route::post('store-purchases/verify', [\App\Http\Controllers\API\StorePurchaseController::class, 'verify'])
                    ->middleware(['product.feature:checkout', 'throttle:payment-write']);

            });

            Route::get('courses/{course}/pdfs/{pdf}/download', [\App\Http\Controllers\API\CoursePdfController::class, 'download'])
                ->whereNumber('course')
                ->whereNumber('pdf')
                ->middleware(['signed', 'throttle:30,1'])
                ->name('api.course-pdfs.download');

            // Visitor Statistics Routes
            Route::get('visitors/stats', [\App\Http\Controllers\API\VisitorController::class, 'getStats'])
                ->middleware(['auth:api', 'admin']);
            Route::get('visitors/recent', [\App\Http\Controllers\API\VisitorController::class, 'getRecentVisitors'])
                ->middleware(['auth:api', 'admin']);

            // Grades routes
            Route::apiResource('grades', \App\Http\Controllers\API\GradeController::class)
                ->only(['index', 'show']);
            Route::get('grades/{grade}/courses', [\App\Http\Controllers\API\GradeController::class,'courses']);

             Route::get('feedback', [\App\Http\Controllers\API\FeedbackController::class, 'index'])
                 ->middleware(['auth.optional', 'throttle:feedback']);
             Route::post('feedback', [\App\Http\Controllers\API\FeedbackController::class, 'store'])
                 ->middleware(['auth.optional', 'recovery.write', 'throttle:feedback']);
             Route::get('feedback/{publicId}', [\App\Http\Controllers\API\FeedbackController::class, 'show'])
                 ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['auth.optional', 'throttle:feedback']);
             Route::post('feedback/{publicId}/messages', [\App\Http\Controllers\API\FeedbackController::class, 'reply'])
                 ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['auth.optional', 'recovery.write', 'throttle:feedback']);
             Route::post('feedback/{publicId}/claim', [\App\Http\Controllers\API\FeedbackController::class, 'claim'])
                 ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['auth:api', 'recovery.write', 'throttle:feedback']);

             // Compact, ranked catalogue search for the app search overlay.
             Route::get('search/courses', \App\Http\Controllers\API\CourseSearchController::class)
                 ->middleware('throttle:catalog-search');

             // Course Listing and Details routes
             Route::get('courses/list', [\App\Http\Controllers\API\CourseController::class,'listCourses']);
             Route::get('courses/{courseId}/details', [\App\Http\Controllers\API\CourseController::class,'viewCourseDetails'])
                 ->middleware('auth.optional');

             // Packages
             Route::get('packages', [\App\Http\Controllers\API\PackageController::class, 'index']);
             Route::get('packages/{id}', [\App\Http\Controllers\API\PackageController::class, 'show']);
             // Classifications
             Route::get('classifications', [\App\Http\Controllers\API\ClassificationController::class, 'index']);
             // Paths
             Route::apiResource('paths', \App\Http\Controllers\API\PathController::class, ['only' => ['index', 'show']]);

        });
};

Route::prefix('v1')->group($registerCourseApiRoutes);
