<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<CourseCode, $this> */
    public function courseCode(): BelongsTo
    {
        return $this->belongsTo(CourseCode::class);
    }

    /** @return BelongsTo<CourseCodeUsage, $this> */
    public function usage(): BelongsTo
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
