<?php

// One catalogue owns both the dashboard choices and the runtime whitelist.
// Adding a delivery type therefore cannot expose a format that submission
// validation or the mobile picker does not understand.
$submissionTypes = [
    'text' => [
        'label' => 'كتابة داخل التطبيق',
        'mime_types' => [],
    ],
    'images' => [
        'label' => 'صور',
        'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
    'pdf' => [
        'label' => 'PDF',
        'mime_types' => ['application/pdf'],
    ],
    'word' => [
        'label' => 'Word',
        'mime_types' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ],
    'presentation' => [
        'label' => 'PowerPoint',
        'mime_types' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
    ],
    'text_file' => [
        'label' => 'ملف نصي',
        'mime_types' => ['text/plain'],
    ],
];

return [
    'evaluation_daily_attempt_limit' => (int) env('PROJECT_EVALUATION_DAILY_ATTEMPT_LIMIT', 60),
    'evaluation_max_output_tokens' => 384,
    'evaluation_max_input_characters' => 60000,
    'evaluation_reserve_usd' => '0.050000',
    // Keep learner submissions private while allowing every web/worker node to
    // read the same file. Production should point this at a shared private
    // disk (for example S3); local remains a safe single-node default.
    'submission_disk' => env('PROJECT_SUBMISSION_DISK', 'local'),
    // Leave enough of the platform's 20-second request deadline to return a
    // retryable JSON response after a slow multi-file object-storage attempt.
    'submission_request_budget_seconds' => max(
        5,
        min(17, (int) env('PROJECT_SUBMISSION_REQUEST_BUDGET_SECONDS', 16))
    ),
    'minimum_text_length' => (int) env('PROJECT_MINIMUM_TEXT_LENGTH', 10),
    'minimum_file_bytes' => (int) env('PROJECT_MINIMUM_FILE_BYTES', 512),
    'maximum_file_kilobytes' => (int) env('PROJECT_MAXIMUM_FILE_KILOBYTES', 25600),
    'image_inspection_max_bytes' => (int) env('PROJECT_IMAGE_INSPECTION_MAX_BYTES', 8388608),
    'image_inspection_max_pixels' => (int) env('PROJECT_IMAGE_INSPECTION_MAX_PIXELS', 12000000),
    'submission_types' => $submissionTypes,
    'allowed_mime_types' => array_values(array_unique(array_merge(
        [],
        ...array_column($submissionTypes, 'mime_types')
    ))),
    'dark_image_threshold' => (int) env('PROJECT_DARK_IMAGE_THRESHOLD', 12),
    'dark_image_ratio' => (float) env('PROJECT_DARK_IMAGE_RATIO', 0.97),
    'white_image_threshold' => (int) env('PROJECT_WHITE_IMAGE_THRESHOLD', 248),
    'white_image_ratio' => (float) env('PROJECT_WHITE_IMAGE_RATIO', 0.985),
    'solid_image_channel_range' => (int) env(
        'PROJECT_SOLID_IMAGE_CHANNEL_RANGE',
        env('PROJECT_SOLID_IMAGE_LUMINANCE_RANGE', 3)
    ),
];
