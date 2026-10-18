<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PDF Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the LaravelPdf package.
    | It uses mPDF under the hood for PDF generation.
    |
    */

    'mode' => 'utf-8',
    'format' => 'A4',
    'default_font_size' => 12,
    'default_font' => 'cairo',
    'margin_left' => 15,
    'margin_right' => 15,
    'margin_top' => 15,
    'margin_bottom' => 15,
    'margin_header' => 5,
    'margin_footer' => 10,
    'orientation' => 'P',
    'tempDir' => storage_path('app/temp'),

    // Font configuration for Arabic support - use Cairo font from storage
    'font_path' => storage_path('fonts/'),
    'font_data' => [
        'cairo' => [
            'R' => 'Cairo.ttf',
            'B' => 'CairoBold.ttf',
            'useOTL' => 0xFF,    // required for complicated langs like Persian, Arabic and Chinese
            'useKashida' => 75,   // Disable Kashida to avoid compatibility issues

        ],
    ],

    // Document metadata
    'author' => null,
    'creator' => null,
    'subject' => 'تصدير أكواد الدورات',
    'keywords' => 'أكواد, دورات, تعليم',
    'display_mode' => 'fullpage',

    // PDF/A compliance (optional)
    'pdf_a' => false,
    'pdf_a_auto' => false,

    // ICC profile (optional)
    'icc_profile_path' => null,
];
