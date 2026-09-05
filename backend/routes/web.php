<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/.well-known/assetlinks.json', [\App\Http\Controllers\AppAssociationController::class, 'android'])
    ->name('app-association.android');
Route::get('/.well-known/apple-app-site-association', [\App\Http\Controllers\AppAssociationController::class, 'apple'])
    ->name('app-association.apple');
Route::get('/apple-app-site-association', [\App\Http\Controllers\AppAssociationController::class, 'apple'])
    ->name('app-association.apple.root');

Route::get('/', [\App\Http\Controllers\LandingPageController::class, 'index'])->name('landing');
Route::get('/profile', \App\Http\Controllers\AppLinkFallbackController::class)
    ->name('app-link.profile-fallback');
Route::get('/wallet', \App\Http\Controllers\AppLinkFallbackController::class)
    ->name('app-link.wallet-fallback');
Route::get('/support/{case}', \App\Http\Controllers\AppLinkFallbackController::class)
    ->where('case', '[0-9A-HJKMNP-TV-Z]{26}')
    ->name('app-link.support-fallback');
foreach (['course', 'courses'] as $coursePrefix) {
    Route::get("/{$coursePrefix}/{course}", \App\Http\Controllers\AppLinkFallbackController::class)
        ->whereNumber('course');
    Route::get("/{$coursePrefix}/{course}/watch/{reel?}", \App\Http\Controllers\AppLinkFallbackController::class)
        ->whereNumber('course')
        ->whereNumber('reel');
    Route::get("/{$coursePrefix}/{course}/lesson/{lesson}", \App\Http\Controllers\AppLinkFallbackController::class)
        ->whereNumber('course')
        ->whereNumber('lesson');
}
Route::get('/about', [\App\Http\Controllers\StaticPageController::class, 'about'])->name('about');
Route::get('/contact', [\App\Http\Controllers\StaticPageController::class, 'contact'])->name('contact');
Route::get('/privacy-policy', [\App\Http\Controllers\StaticPageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [\App\Http\Controllers\StaticPageController::class, 'terms'])->name('terms');
Route::get('/returns-policy', [\App\Http\Controllers\StaticPageController::class, 'returnsPolicy'])->name('returns-policy');
Route::get('/account-deletion', [\App\Http\Controllers\AccountDeletionRequestController::class, 'show'])
    ->name('account-deletion.show');
Route::post('/account-deletion', [\App\Http\Controllers\AccountDeletionRequestController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('account-deletion.store');

Route::get('/payment-evidence/{order}', [\App\Http\Controllers\PaymentEvidenceController::class, 'show'])
    ->whereNumber('order')
    ->middleware(['signed', 'throttle:60,1'])
    ->name('payment.evidence');

Route::get('/c/{publicId}', [\App\Http\Controllers\PublicCertificateController::class, 'show'])
    ->whereUuid('publicId')
    ->middleware('throttle:60,1')
    ->name('certificate.public');
Route::get('/c/{publicId}/artifact', [\App\Http\Controllers\PublicCertificateController::class, 'artifact'])
    ->whereUuid('publicId')
    ->middleware('throttle:60,1')
    ->name('certificate.artifact');
Route::get('/c/{publicId}/download', [\App\Http\Controllers\PublicCertificateController::class, 'download'])
    ->whereUuid('publicId')
    ->middleware('throttle:30,1')
    ->name('certificate.download');
Route::get('/@{slug}/media/{mediaId}', [\App\Http\Controllers\PublicPortfolioMediaController::class, 'portfolio'])
    ->where('slug', '[a-z0-9-]+')
    ->whereUuid('mediaId')
    ->middleware('throttle:240,1')
    ->name('portfolio.media');
Route::get('/@{slug}', [\App\Http\Controllers\PublicPortfolioController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:60,1')
    ->name('portfolio.public');
Route::group([
    'prefix' => 'dashboard/mfa',
    'namespace' => 'Admin',
    'as' => 'admin.mfa.',
    'middleware' => ['admin'],
], function () {
    Route::get('setup', 'MfaController@setup')->name('setup');
    Route::post('setup', 'MfaController@confirmSetup')
        ->middleware('throttle:admin-mfa')->name('setup.confirm');
    Route::get('challenge', 'MfaController@challenge')->name('challenge');
    Route::post('challenge', 'MfaController@verifyChallenge')
        ->middleware('throttle:admin-mfa')->name('challenge.verify');
    Route::get('backup-codes', 'MfaController@backupCodes')
        ->middleware('admin.mfa')->name('backup-codes');
});

Route::group(['prefix' => 'dashboard', 'namespace' => 'Admin', 'as' => 'admin.', 'middleware' => ['admin', 'admin.mfa', 'admin.audit']], function () {
    Route::get('/', 'HomeController@index')->name('dashboard');
    Route::get('product-operations', 'ProductOperationsController@index')
        ->middleware('admin.only')->name('product-operations.index');
    Route::get('product-analytics', 'ProductAnalyticsController@index')
        ->middleware('admin.only')->name('product-analytics.index');
    Route::post('product-operations/features/{feature}', 'ProductOperationsController@updateFeature')
        ->whereIn('feature', ['checkout', 'playback', 'project_uploads', 'ai_chat'])
        ->middleware('admin.only')->name('product-operations.features.update');
    Route::post('product-operations/outbox/{outboxEvent}/retry', 'ProductOperationsController@retryOutbox')
        ->whereNumber('outboxEvent')->middleware('admin.only')->name('product-operations.outbox.retry');
    Route::post('product-operations/outbox/{outboxEvent}/skip', 'ProductOperationsController@skipOutbox')
        ->whereNumber('outboxEvent')->middleware('admin.only')->name('product-operations.outbox.skip');
    Route::post('product-operations/failed-jobs/{failedJob}/acknowledge', 'ProductOperationsController@acknowledgeFailedJob')
        ->whereNumber('failedJob')->middleware('admin.only')->name('product-operations.failed-jobs.acknowledge');
    Route::get('product-operations/playback', 'PlaybackOperationsController@index')
        ->middleware('admin.only')->name('playback-operations.index');
    Route::post('product-operations/playback/{playbackSession}/terminate-stale', 'PlaybackOperationsController@terminateStale')
        ->whereUuid('playbackSession')->middleware('admin.only')->name('playback-operations.terminate-stale');
    Route::post('media-health/{lesson}/probe', 'MediaHealthController@probe')
        ->middleware('admin.only')->name('media-health.probe');
    Route::get('user-sessions', 'UserSessionController@index')
        ->middleware('admin.only')->name('user-sessions.index');
    Route::delete('user-sessions/{sessionId}', 'UserSessionController@destroy')
        ->whereUuid('sessionId')->middleware('admin.only')->name('user-sessions.destroy');
    Route::get('feedback/export', 'FeedbackController@export')->middleware('admin.only')->name('feedback.export');
    Route::get('feedback', 'FeedbackController@index')->middleware('admin.only')->name('feedback.index');
    Route::get('feedback/{feedback}', 'FeedbackController@show')->middleware('admin.only')->name('feedback.show');
    Route::patch('feedback/{feedback}', 'FeedbackController@update')->middleware('admin.only')->name('feedback.update');
    Route::post('feedback/{feedback}/messages', 'FeedbackController@message')->middleware(['admin.only', 'admin.audit'])->name('feedback.message');
    Route::post('feedback/{feedback}/compensate', 'FeedbackController@compensate')->middleware(['admin.only', 'admin.audit'])->name('feedback.compensate');
    Route::get('feedback/{feedback}/attachments/{attachment}', 'FeedbackController@attachment')->middleware('admin.only')->name('feedback.attachment');
    Route::delete('feedback/{feedback}', 'FeedbackController@destroy')->middleware('admin.only')->name('feedback.destroy');

    // Urgent Tasks routes
    Route::get('urgent-tasks', 'UrgentTasksController@index')->middleware('admin.only')->name('urgent-tasks.index');
    Route::get('urgent-tasks/pending-orders', 'UrgentTasksController@pendingOrders')->middleware('admin.only')->name('urgent-tasks.pending-orders');
    Route::get('urgent-tasks/inactive-students', 'UrgentTasksController@inactiveStudents')->middleware('admin.only')->name('urgent-tasks.inactive-students');
    Route::post('urgent-tasks/orders/{order}/approve', 'UrgentTasksController@approveOrder')->middleware('admin.only')->name('urgent-tasks.approve-order');
    Route::post('urgent-tasks/orders/{order}/reject', 'UrgentTasksController@rejectOrder')->middleware('admin.only')->name('urgent-tasks.reject-order');
    Route::post('urgent-tasks/students/{user}/activate', 'UrgentTasksController@activateStudent')->middleware('admin.only')->name('urgent-tasks.activate-student');

    // Moderators own course authoring and commercial plan configuration.
    // Deleting a commercial shell remains administrator-only.
    Route::get('courses/{course}/commercial-report.csv', 'CourseController@exportCommercialReport')
        ->middleware('admin.only')->name('courses.commercial-report.export');
    Route::post('courses/{courseId}/restore', 'CourseController@restore')
        ->whereNumber('courseId')->middleware('admin.only')->name('courses.restore');
    Route::resource('courses', 'CourseController')->only(['create', 'store']);
    Route::post('courses/{course}/authoring-draft', 'CourseController@startDraft')
        ->name('courses.draft.start');
    Route::resource('courses', 'CourseController')
        ->only(['edit', 'update'])->middleware('course.draft');
    Route::resource('courses', 'CourseController')
        ->only(['destroy'])
        ->middleware('admin.only');
    Route::resource('courses', 'CourseController')->only(['index', 'show']);
    // Preview is a read. A published course without an active working copy
    // must not acquire a cloned draft merely because a moderator opened it.
    Route::get('courses/{course}/student-preview', 'CourseController@studentPreview')
        ->name('courses.student-preview');
    Route::post('courses/{course}/media-health/probe', 'MediaHealthController@probeCourse')
        ->middleware('course.draft')->name('courses.media-health.probe');

    // Course Sections routes
    Route::post('courses/{course}/sections/reorder', 'CourseSectionController@reorder')->middleware('course.draft')->name('courses.sections.reorder');
    Route::post('courses/{course}/sections/video-uploads', 'CourseSectionVideoUploadController@store')
        // One authenticated moderator can prepare a short course in a single
        // sitting. This still caps remote allocations while allowing the
        // common 15-reel authoring batch to proceed without an artificial stop.
        ->middleware(['course.draft', 'throttle:30,1'])->name('courses.sections.video-uploads.store');
    Route::post('courses/{course}/sections/video-uploads/renew', 'CourseSectionVideoUploadController@renew')
        ->middleware(['course.draft', 'throttle:60,1'])->name('courses.sections.video-uploads.renew');
    Route::get('courses/{course}/sections/create-intents/{intent}', 'CourseSectionController@createIntentReceipt')
        ->middleware('course.draft')->name('courses.sections.create-intents.show');
    Route::resource('courses.sections', 'CourseSectionController')->except(['index', 'show'])->middleware('course.draft');

    // Course Modules routes
    Route::post('courses/{course}/modules/reorder', 'CourseModuleController@reorder')->middleware('course.draft')->name('courses.modules.reorder');
    Route::resource('courses.modules', 'CourseModuleController')->except(['index', 'show'])->middleware('course.draft');
    // Course PDFs routes
    Route::post('courses/{course}/pdfs/reorder', 'CoursePdfController@reorder')->middleware('course.draft')->name('courses.pdfs.reorder');
    Route::post('courses/{course}/pdfs/{pdf}/toggle-status', 'CoursePdfController@toggleStatus')->middleware('course.draft')->name('courses.pdfs.toggle-status');
    Route::get('courses/{course}/pdfs/{pdf}/preview', 'CoursePdfController@preview')->name('courses.pdfs.preview');
    Route::resource('courses.pdfs', 'CoursePdfController')->except(['show'])->middleware('course.draft');

    // App Versions routes
    Route::resource('app-versions', 'AppVersionController')->except(['show'])->middleware('admin.only');
    Route::post('app-versions/{id}/toggle-active', 'AppVersionController@toggleActive')->middleware('admin.only')->name('app-versions.toggle-active');

    // Design Settings
    Route::resource('design-settings', 'DesignSettingController')
        ->only(['index', 'store'])
        ->middleware('admin.only');

    Route::post('contacts/{contact}/processing', 'ContactsController@markProcessing')
        ->middleware('admin.only')->name('contacts.processing');
    Route::post('contacts/{contact}/read', 'ContactsController@markRead')
        ->middleware('admin.only')->name('contacts.read');
    Route::post('contacts/{contact}/close-deletion-request', 'ContactsController@closeDeletionRequest')->middleware('admin.only')
        ->name('contacts.close-deletion-request');
    Route::post('contacts/{contact}/execute-account-deletion', 'ContactsController@executeAccountDeletion')
        ->middleware(['admin.only', 'throttle:6,1'])
        ->name('contacts.execute-account-deletion');
    Route::resource('contacts', 'ContactsController', ['only' => ['index', 'show']])->middleware('admin.only');
    Route::delete('contacts/{contact}', 'ContactsController@destroy')->middleware('admin.only')->name('contacts.destroy');

    Route::resource('admin_notifications', 'AdminNotificationsController')
        ->except(['show'])
        ->middleware('admin.only');
    Route::resource('categories', 'CategoryController')
        ->only(['create', 'store', 'edit', 'update', 'destroy'])
        ->middleware('admin.only');
    Route::resource('categories', 'CategoryController')->only(['index'])->middleware('admin.only');
    Route::resource('grades', 'GradeController')
        ->only(['create', 'store', 'edit', 'update', 'destroy'])
        ->middleware('admin.only');
    Route::resource('grades', 'GradeController')->only(['index'])->middleware('admin.only');
    Route::get('grades/{grade}/courses', 'GradeController@courses')->middleware('admin.only')->name('grades.courses');
    Route::resource('coupons', 'CouponController')->except(['show'])->middleware('admin.only');

    // Course Codes Management
    Route::get('course-codes/get-lessons', 'CourseCodeController@getLessons')->middleware('admin.only')->name('course-codes.get-lessons');
    Route::get('course-codes/export-pdf', 'CourseCodeController@exportToPdf')->middleware('admin.only')->name('course-codes.export-pdf');
    Route::get('course-codes/export', 'CourseCodeController@export')->middleware('admin.only')->name('course-codes.export');
    Route::post('course-codes/bulk-action', 'CourseCodeController@bulkAction')->middleware(['admin.only', 'throttle:admin-bulk'])->name('course-codes.bulk-action');
    Route::resource('course-codes', 'CourseCodeController')->middleware('admin.only');


    Route::name('admin_data')->get('admin_data', 'SettingsController@adminData');
    Route::name('update_admin_data')->post('update_admin_data', 'SettingsController@updateAdminData');
    
    // Teacher routes
    Route::resource('teachers', 'TeacherController')->except(['destroy']);
    Route::resource('teachers', 'TeacherController')->only(['destroy'])->middleware('admin.only');
    Route::patch('teachers/{teacher}/status', 'TeacherController@deactive')->name('teachers.deactive');
    Route::resource('moderators', 'ModeratorController')
        ->only(['index', 'create', 'store', 'edit', 'update'])
        ->middleware('admin.only');

    // User notes routes
    Route::name('users.notes.store')->post('users/{user}/notes', 'UsersController@storeNote')->middleware('admin.only');
    Route::name('users.notes.delete')->delete('notes/{note}', 'UsersController@deleteNote')->middleware('admin.only');

    // Account deletion is irreversible and must only be executed from the
    // verified deletion-request workflow in ContactsController. Exposing the
    // resource destroy action would bypass its identity checks and resolution
    // evidence even though the dashboard does not render a delete button.
    Route::resource('users', 'UsersController')->except(['destroy'])->middleware('admin.only');
    Route::resource('packages', 'PackageController')->middleware('admin.only');
    // Home rows are editorial content. Moderators can arrange and rename
    // them, while physical taxonomy deletion remains owner-only.
    Route::resource('classifications', 'ClassificationController')
        ->only(['index', 'create', 'store', 'edit', 'update']);
    Route::resource('classifications', 'ClassificationController')
        ->only(['destroy'])
        ->middleware('admin.only');
    Route::resource('paths', 'PathController')
        ->only(['create', 'store', 'edit', 'update', 'destroy'])
        ->middleware('admin.only');
    Route::resource('paths', 'PathController')->only(['index'])->middleware('admin.only');
    // Moderators may use the shared level catalogue while authoring courses,
    // but changing the platform-wide learner progression ladder is an
    // administrator decision. Keep the reference list readable without
    // granting create, rename, reorder or delete access.
    Route::resource('levels', 'LevelController')
        ->only(['create', 'store', 'edit', 'update', 'destroy'])
        ->middleware('admin.only');
    Route::resource('levels', 'LevelController')->only(['index'])->middleware('admin.only');
    Route::resource('coin-earning-methods', 'CoinEarningMethodController')
        ->except(['show'])
        ->middleware('admin.only');
    Route::post('coin-earning-methods-settings', 'CoinEarningMethodController@updateSettings')->middleware('admin.only')->name('coin-earning-methods.update-settings');
    Route::post('reward-rules', 'CoinEarningMethodController@storeRewardRule')->middleware('admin.only')->name('reward-rules.store');
    Route::put('reward-rules/{rewardRule}', 'CoinEarningMethodController@updateRewardRule')->middleware('admin.only')->name('reward-rules.update');
    Route::delete('reward-rules/{rewardRule}', 'CoinEarningMethodController@destroyRewardRule')->middleware('admin.only')->name('reward-rules.destroy');
    Route::name('users.deactive')->patch('users/{user}/status', 'UsersController@deactive')->middleware('admin.only');
    Route::name('users.reset-device')->post('users/{user}/reset-device', 'UsersController@resetDevice')->middleware('admin.only');

    /* ====== Student Progress =======*/
    Route::name('student-progress.index')->get('student-progress', 'StudentProgressController@index')->middleware('admin.only');
    Route::name('student-progress.show')->get('student-progress/{user}', 'StudentProgressController@show')->middleware('admin.only');
    Route::name('student-progress.statistics')->get('student-progress-statistics', 'StudentProgressController@statistics')->middleware('admin.only');
    Route::name('student-progress.compare')->post('student-progress/compare', 'StudentProgressController@compare')->middleware('admin.only');

    /* ====== Project Submissions =======*/
    Route::get('project-submissions', 'ProjectSubmissionController@index')->name('project-submissions.index');
    Route::get('project-submissions/{projectSubmission}', 'ProjectSubmissionController@show')->name('project-submissions.show');
    Route::get('project-submissions/{projectSubmission}/download', 'ProjectSubmissionController@download')->name('project-submissions.download');
    Route::get('project-submissions/{projectSubmission}/attachments/{attachment}/download', 'ProjectSubmissionController@downloadAttachment')->name('project-submissions.attachments.download');
    Route::post('project-submissions/{projectSubmission}/pass', 'ProjectSubmissionController@pass')->name('project-submissions.pass');
    Route::post('project-submissions/{projectSubmission}/reject', 'ProjectSubmissionController@reject')->name('project-submissions.reject');

    /* ====== Orders =======*/
    Route::resource('orders', 'OrdersController', ['only' => ['index', 'show']])->middleware('admin.only');
    Route::post('orders/{order}/financial-resolution', 'OrdersController@resolveFinancialReview')->middleware('admin.only')->name('orders.resolve-financial-review');
    Route::post('orders/{order}/course-compensation', 'OrdersController@compensateCourse')->middleware('admin.only')->name('orders.compensate-course');
    Route::post('orders/{order}/settlement', 'OrdersController@recordSettlement')->middleware('admin.only')->name('orders.record-settlement');
    /* ====== Payment Reconciliation Review =======*/
    Route::get('payment-reconciliation/findings', 'PaymentReconciliationFindingController@index')
        ->middleware('admin.only')->name('payment-reconciliation-findings.index');
    Route::patch('payment-reconciliation/findings/{paymentReconciliationFinding}/resolve', 'PaymentReconciliationFindingController@resolve')
        ->whereNumber('paymentReconciliationFinding')->middleware('admin.only')
        ->name('payment-reconciliation-findings.resolve');
    Route::patch('payment-reconciliation/findings/{paymentReconciliationFinding}/ignore', 'PaymentReconciliationFindingController@ignore')
        ->whereNumber('paymentReconciliationFinding')->middleware('admin.only')
        ->name('payment-reconciliation-findings.ignore');
    Route::patch('payment-reconciliation/findings/{paymentReconciliationFinding}/reopen', 'PaymentReconciliationFindingController@reopen')
        ->whereNumber('paymentReconciliationFinding')->middleware('admin.only')
        ->name('payment-reconciliation-findings.reopen');

    Route::get('operating-costs-report', 'OperatingCostPoolController@report')
        ->middleware('admin.only')->name('operating-costs.report');
    Route::get('operating-costs-report.csv', 'OperatingCostPoolController@exportReport')
        ->middleware('admin.only')->name('operating-costs.report.export');
    Route::resource('operating-costs', 'OperatingCostPoolController')
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['operating-costs' => 'operatingCost'])
        ->middleware('admin.only');
    Route::post('operating-costs-exchange-rate', 'OperatingCostPoolController@updateExchangeRate')
        ->middleware('admin.only')->name('operating-costs.exchange-rate');

    /* ====== Settings =======*/
    Route::get('/settings', 'SettingsController@index')->middleware('admin.only')->name('settings');
    Route::post('/settings', 'SettingsController@update')->middleware('admin.only')->name('settings.update');
    Route::post('/settings/test-bunny', 'SettingsController@testBunnyConnection')->middleware('admin.only')->name('settings.test-bunny');
    Route::post('/settings/bunny-cleanup/{candidate}/approve', 'SettingsController@approveBunnyCleanup')
        ->middleware('admin.only')->name('settings.bunny-cleanup.approve');
    Route::post('/settings/bunny-cleanup/approve-batch', 'SettingsController@approveBunnyCleanupBatch')
        ->middleware(['admin.only', 'throttle:admin-bulk'])->name('settings.bunny-cleanup.approve-batch');
    /* ====== About =======*/
    Route::name('privacy')->get('privacy', 'AboutsController@privacy')->middleware('admin.only');
    Route::name('policy')->get('policy', 'AboutsController@policy')->middleware('admin.only');
    Route::name('about')->get('about', 'AboutsController@about')->middleware('admin.only');
    Route::name('abouts.update')->patch('abouts/edit', 'AboutsController@update')->middleware('admin.only');
    /* ====== Send Notification =======*/
    Route::resource('notifications', 'NotificationsController', ['only' => ['index', 'create']])->middleware('admin.only');
    Route::post('notifications', 'NotificationsController@store')
        ->middleware(['admin.only', 'throttle:admin-bulk'])
        ->name('notifications.store');
    Route::post('notifications/{notificationCampaign}/retry', 'NotificationsController@retry')
        ->whereNumber('notificationCampaign')
        ->middleware(['admin.only', 'throttle:admin-bulk'])
        ->name('notifications.retry');

});
// Learners authenticate through the mobile social-auth contract. The web
// guard exists only for administrator and moderator accounts provisioned by
// an administrator, so public password registration must never be routable.
Auth::routes(['register' => false, 'verify' => false]);

Route::get('/home', [\App\Http\Controllers\HomeController::class, 'index'])->name('home');

/*
|--------------------------------------------------------------------------
| Kashier Payment Web Routes
|--------------------------------------------------------------------------
| These are web routes (not API) so Kashier can redirect the browser here
| and we can render an HTML result page for the mobile WebView.
| The webhook route is excluded from CSRF in VerifyCsrfToken.php.
*/
Route::get('/payment/callback', [\App\Http\Controllers\API\PaymentController::class, 'callback'])
    ->middleware(['recovery.write', 'throttle:kashier-callback'])
    ->name('payment.callback');

Route::post('/payment/webhook', [\App\Http\Controllers\API\PaymentController::class, 'webhook'])
    ->middleware(['recovery.write', 'throttle:kashier-webhook'])
    ->name('payment.webhook');

