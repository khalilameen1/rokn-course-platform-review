<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Generated certificate storage
    |--------------------------------------------------------------------------
    | Use a shared disk (for example S3) when more than one application
    | instance serves traffic. Artifacts are served through the revocation-aware
    | public credential route and must not have a durable public object URL.
    */
    'disk' => env('CERTIFICATE_DISK', env('FILESYSTEM_PUBLIC_DISK', 'public')),
    /*
    |--------------------------------------------------------------------------
    | Certificate template image
    |--------------------------------------------------------------------------
    | Path to the background PNG/JPG that serves as the certificate canvas.
    | All dynamic text and QR code are drawn on top of this image.
    */
    'template_path' => env('CERTIFICATE_TEMPLATE_PATH', null) ?: public_path('images/certificate_template_v2.png'),

    /*
    |--------------------------------------------------------------------------
    | Font paths
    |--------------------------------------------------------------------------
    | Cairo variable font (599 KB) covers all weights including bold and has
    | complete Arabic + Latin glyph sets.
    */
    'font_regular' => env('CERTIFICATE_FONT_PATH', resource_path('fonts/Cairo.ttf')),

    /*
    |--------------------------------------------------------------------------
    | Date format
    |--------------------------------------------------------------------------
    */
    'date_format' => 'j F Y',

    /*
    |--------------------------------------------------------------------------
    | Achievement text
    |--------------------------------------------------------------------------
    | Every course selects one approved wording key. The resolved key and text
    | are copied to the certificate row when it is issued, so later editorial
    | changes never rewrite an existing credential.
    */
    'default_text_template_key' => 'completion',
    'text_templates' => [
        'completion' => [
            'label' => 'إتمام الكورس',
            'description' => 'مناسبة لمعظم الكورسات',
            'text' => 'تقديرًا لإتمام متطلبات كورس',
        ],
        'knowledge' => [
            'label' => 'المعرفة',
            'description' => 'مناسبة للكورسات المعرفية والنظرية',
            'text' => 'تقديرًا لإتمام المسار المعرفي لكورس',
        ],
        'applied' => [
            'label' => 'التطبيق العملي',
            'description' => 'مناسبة للكورسات التي تجمع التعلم بالتطبيق',
            'text' => 'تقديرًا لإتمام المتطلبات التطبيقية لكورس',
        ],
        'skills' => [
            'label' => 'تدريب مهاري',
            'description' => 'مناسبة للكورسات التي تبني مهارة بالممارسة',
            'text' => 'تقديرًا لإتمام التدريب المهاري في كورس',
        ],
        'projects' => [
            'label' => 'المشروعات',
            'description' => 'مناسبة للكورسات التي تعتمد على مشروعات العبور',
            'text' => 'تقديرًا لإنجاز مشروعات كورس',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Text overlay positions
    |--------------------------------------------------------------------------
    | Coordinates are expressed as decimal fractions of the template image
    | dimensions (0.0 – 1.0). This makes them resolution-independent.
    | Font sizes are absolute pixel values.
    |
    | Default values are tuned for the 1200 × 900 certificate template.
    */
    'text_positions' => [

        // RTL editorial statement: the learner is the visual centre.
        'name' => [
            'x'     => 0.90,
            'y'     => 0.385,
            'size'  => 48,
            'min_size' => 28,
            'max_width' => 0.60,
            'align' => 'right',
            'color' => '#09172C',
        ],

        // Course title closes the central statement, close to its wording.
        'course' => [
            'x'     => 0.90,
            'y'     => 0.560,
            'size'  => 30,
            'min_size' => 18,
            'max_width' => 0.60,
            'align' => 'right',
            'color' => '#09172C',
        ],

        // Certificate ID belongs to the dedicated verification rail.
        'cert_id' => [
            'x'     => 0.125,
            'y'     => 0.510,
            // A UUID is the externally verifiable credential identifier.
            'size'  => 9,
            'align' => 'center',
            'color' => '#314056',
        ],

        // Immutable wording and course title read as one intentional sentence.
        'achievement' => [
            'x'     => 0.90,
            'y'     => 0.495,
            'size'  => 17,
            'min_size' => 13,
            'max_width' => 0.60,
            'align' => 'right',
            'color' => '#657083',
        ],

        // Date aligns with the signature and verification group without rules.
        'date' => [
            'x'     => 0.90,
            'y'     => 0.815,
            'size'  => 14,
            'align' => 'right',
            'color' => '#09172C',
        ],

        // QR stands on its own; no decorative container competes with it.
        'qr_code' => [
            'x'    => 0.125,
            'y'    => 0.345,
            'size' => 128,
        ],
    ],
];
