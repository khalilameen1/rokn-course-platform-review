<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseCodeUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_code_id',
        'user_id',
        'used_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    /**
     * Get the course code that was used
     *
     * @return BelongsTo<CourseCode, $this>
     */
    public function courseCode(): BelongsTo
    {
        return $this->belongsTo(CourseCode::class);
    }

    /**
     * Get the user who used the code
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
