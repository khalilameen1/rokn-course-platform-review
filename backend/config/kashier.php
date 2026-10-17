<?php

return [
    // Never silently fall back to the test gateway. The selected environment
    // and its credentials must be explicit on every deployed instance.
    'mode' => env('KASHIER_MODE'),
    
    'live' => [
        'base_url' => 'https://checkout.kashier.io',
        'api_key' => env('KASHIER_LIVE_API_KEY', ''),
        'secret_key' => env('KASHIER_LIVE_SECRET_KEY', ''),
        'mid' => env('KASHIER_LIVE_MID', ''),
    ],
    
    'test' => [
        'base_url' => 'https://checkout.kashier.io',
        'api_key' => env('KASHIER_TEST_API_KEY', ''),
        'secret_key' => env('KASHIER_TEST_SECRET_KEY', ''),
        'mid' => env('KASHIER_TEST_MID', ''),
    ],

    // Smallest cash amount (EGP) a course checkout may charge through Kashier
    // to fund a coin shortfall; the shortfall is rounded up to whole coins at
    // the same rate and the surplus stays in the wallet as paid coins.
    // Disabled (0) by default: mobile/src/services/api/courseCheckout.ts still
    // rejects an exact_shortfall package whose coins differ from the deficit.
    // Owner decision (2026-10-17): 20 EGP in production (.env examples); the
    // code default stays 0 so fixtures keep exact-shortfall arithmetic.
    'minimum_course_funding_amount' => (float) env('KASHIER_MINIMUM_COURSE_FUNDING_EGP', 0),
];
