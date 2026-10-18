<?php

namespace App\Models;

use App\Traits\InvalidatesCourseCatalogue;
use App\Traits\ResolvesLocalizedAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Path extends Model
{
    use HasFactory, InvalidatesCourseCatalogue, ResolvesLocalizedAttributes;

    protected $fillable = [
        'title_ar',
        'title_en',
    ];

    public function getTitleAttribute()
    {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (! array_key_exists('title_ar', $this->attributes) && ! array_key_exists('title_en', $this->attributes)) {
            return null;
        }

        return $this->localizedValue('title_ar', 'title_en');
    }

    /**
     * Get the courses for this path.
     */
    public function courses()
    {
        return $this->hasMany(Course::class);
    }

    /**
     * Get the interests (classifications) for this path.
     */
    public function interests()
    {
        return $this->belongsToMany(Classification::class, 'classification_path');
    }
}
