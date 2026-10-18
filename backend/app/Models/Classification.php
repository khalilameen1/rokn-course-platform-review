<?php

namespace App\Models;

use App\Traits\InvalidatesCourseCatalogue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Classification extends Model
{
    use HasFactory, InvalidatesCourseCatalogue;

    protected $fillable = ['name_ar', 'name_en', 'show_on_home', 'home_order'];

    protected $casts = [
        'show_on_home' => 'boolean',
        'home_order' => 'integer',
    ];

    /** @return BelongsToMany<Course, $this> */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'classification_course');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classification_user');
    }
}
