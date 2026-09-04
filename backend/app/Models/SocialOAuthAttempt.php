<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SocialOAuthAttempt extends Model
{
    protected $table = 'social_oauth_attempts';

    protected $fillable = [
        'state_hash',
        'completion_hash',
        'provider',
        'return_to',
        'code_challenge',
        'nonce_hash',
        'encrypted_token',
        'encrypted_completion_code',
        'encrypted_session_response',
        'state_expires_at',
        'state_consumed_at',
        'completion_expires_at',
        'completion_processing_at',
        'completion_claim_id',
        'completion_consumed_at',
    ];

    protected $hidden = [
        'state_hash',
        'completion_hash',
        'nonce_hash',
        'encrypted_token',
        'encrypted_completion_code',
        'encrypted_session_response',
        'completion_claim_id',
    ];

    protected $casts = [
        'state_expires_at' => 'datetime',
        'state_consumed_at' => 'datetime',
        'completion_expires_at' => 'datetime',
        'completion_processing_at' => 'datetime',
        'completion_consumed_at' => 'datetime',
    ];
}
