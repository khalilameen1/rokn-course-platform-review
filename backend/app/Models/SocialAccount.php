<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'provider_name',
        'avatar_url',
        'last_verified_at',
        'apple_refresh_token',
        'apple_client_id',
    ];

    protected $casts = [
        'last_verified_at' => 'datetime',
        'apple_refresh_token' => 'encrypted',
    ];

    protected $hidden = ['apple_refresh_token'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
