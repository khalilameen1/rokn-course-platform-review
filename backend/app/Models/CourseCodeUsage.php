<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
     */
    public function courseCode()
    {
        return $this->belongsTo(CourseCode::class);
    }

    /**
     * Get the user who used the code
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
