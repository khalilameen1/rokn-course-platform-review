<?php

$s3Disk = static function (string $environmentPrefix = '', string $root = ''): array {
    $value = static fn (string $name, mixed $default = null): mixed =>
        env($environmentPrefix.$name, $default);

    return [
        'driver' => 's3',
        'key' => $value('AWS_ACCESS_KEY_ID'),
        'secret' => $value('AWS_SECRET_ACCESS_KEY'),
        'region' => $value('AWS_DEFAULT_REGION'),
        'bucket' => $value('AWS_BUCKET'),
        'url' => $value('AWS_URL'),
        'endpoint' => $value('AWS_ENDPOINT'),
        'use_path_style_endpoint' => filter_var(
            $value('AWS_USE_PATH_STYLE_ENDPOINT', false),
            FILTER_VALIDATE_BOOL
        ),
        'root' => trim($root, '/'),
        'throw' => true,
    ];
};

$publicDisk = strtolower(trim((string) env('PUBLIC_STORAGE_DRIVER', 'local'))) === 's3'
    ? $s3Disk('PUBLIC_', (string) env('PUBLIC_STORAGE_PREFIX', 'public'))
    : [
        'driver' => 'local',
        'root' => env('SHARED_PUBLIC_STORAGE_PATH', storage_path('app/public')),
        'url' => env('APP_URL').'/storage',
        'visibility' => 'public',
        'throw' => true,
    ];

$feedbackDisk = strtolower(trim((string) env('FEEDBACK_STORAGE_DRIVER', 'local'))) === 's3'
    ? $s3Disk('', (string) env('FEEDBACK_STORAGE_PREFIX', 'feedback'))
    : [
        'driver' => 'local',
        'root' => env(
            'FEEDBACK_STORAGE_PATH',
            rtrim((string) env('SHARED_STORAGE_PATH', storage_path('app')), '/\\') . DIRECTORY_SEPARATOR . 'feedback'
        ),
        'visibility' => 'private',
        'shared' => filter_var(env('FEEDBACK_SHARED_STORAGE', false), FILTER_VALIDATE_BOOL),
        'throw' => true,
    ];

$quarantineDisk = strtolower(trim((string) env('SECURITY_QUARANTINE_STORAGE_DRIVER', 'local'))) === 's3'
    ? $s3Disk('', (string) env('SECURITY_QUARANTINE_STORAGE_PREFIX', 'security-quarantine'))
    : [
        'driver' => 'local',
        'root' => env('SECURITY_QUARANTINE_PATH', storage_path('app/security-quarantine')),
        'visibility' => 'private',
        'throw' => true,
    ];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Default Cloud Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Many applications store files both locally and in the cloud. For this
    | reason, you may specify a default "cloud" driver here. This driver
    | will be bound as the Cloud disk implementation in the container.
    |
    */

    'cloud' => env('FILESYSTEM_CLOUD', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => env('SHARED_STORAGE_PATH', storage_path('app')),
        ],

        'public' => $publicDisk,

        // Retained only until the deletion ledger drains files from the
        // retired module/section attachment model.
        'module-attachments' => [
            'driver' => 'local',
            'root' => env(
                'MODULE_ATTACHMENT_STORAGE_PATH',
                rtrim((string) env('SHARED_STORAGE_PATH', storage_path('app')), '/\\') . DIRECTORY_SEPARATOR . 'module-attachments'
            ),
            'visibility' => 'private',
        ],

        'course-pdfs' => [
            'driver' => 'local',
            'root' => env(
                'COURSE_PDF_STORAGE_PATH',
                rtrim((string) env('SHARED_STORAGE_PATH', storage_path('app')), '/\\') . DIRECTORY_SEPARATOR . 'course-pdfs'
            ),
            'visibility' => 'private',
        ],

        'security-quarantine' => $quarantineDisk,

        'feedback' => $feedbackDisk,

        's3' => $s3Disk(),

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
