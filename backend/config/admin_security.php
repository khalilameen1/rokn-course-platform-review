<?php

declare(strict_types=1);

return [
    'issuer' => env('ADMIN_MFA_ISSUER', 'Rokn'),
    'session_ttl_minutes' => max(5, (int) env('ADMIN_MFA_SESSION_TTL_MINUTES', 720)),
    'setup_ttl_minutes' => max(5, (int) env('ADMIN_MFA_SETUP_TTL_MINUTES', 15)),
    // Time steps accepted either side of now, as Fortify's two-factor-authentication.window.
    'totp_window' => max(0, (int) env('ADMIN_MFA_TOTP_WINDOW', 1)),
    'recovery_code_count' => 10,
];
