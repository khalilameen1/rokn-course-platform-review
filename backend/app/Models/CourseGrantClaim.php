<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CourseGrantClaim extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REASSIGNED = 'reassigned';

    protected $fillable = [
        'user_id',
        'normalized_email_hash',
        'email_hint',
        'course_code_id',
        'course_code_usage_id',
        'course_id',
        'status',
        'claimed_at',
        'reassigned_at',
        'reassigned_by',
        'support_note',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'reassigned_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function courseCode()
    {
        return $this->belongsTo(CourseCode::class);
    }

    public function usage()
    {
        return $this->belongsTo(CourseCodeUsage::class, 'course_code_usage_id');
    }

    public static function emailHash(?string $email): string
    {
        return hash('sha256', mb_strtolower(trim((string) $email)));
    }

    public static function emailHint(?string $email): string
    {
        $email = mb_strtolower(trim((string) $email));
        if (! str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2).'***@'.$domain;
    }
}
