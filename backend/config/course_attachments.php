<?php

return [
    // System download managers refresh an expired capability through the API.
    // Keep copied links short-lived and re-check entitlement on every request.
    'signed_url_minutes' => (int) env('COURSE_ATTACHMENT_SIGNED_URL_MINUTES', 30),
    'prompt' => [
        'default_frequency' => 'once_per_course',
        'at_seconds' => 20,
        'frequencies' => [
            'once_per_course' => 'مرة واحدة في الكورس',
        ],
        'title' => 'مرفقات تساعدك في التطبيق',
        'body' => 'يحتوي الكورس على ملفات تساعدك في التطبيق',
        'button_text' => 'تحميل المرفقات',
    ],
];
