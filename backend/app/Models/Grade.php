<?php

namespace App\Models;

use App\Support\RoknLocale;
use App\Traits\InvalidatesCourseCatalogue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Grade extends Model
{
    use HasFactory, InvalidatesCourseCatalogue, SoftDeletes;

    protected $fillable = [
        'type', 'name_ar', 'name_en', 'description_ar', 'description_en', 'country',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Computed attributes for backward compatibility
    public function getNameAttribute()
    {
        return RoknLocale::isArabic()
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar);
    }

    public function getDescriptionAttribute()
    {
        return RoknLocale::isArabic()
            ? ($this->description_ar ?: $this->description_en)
            : ($this->description_en ?: $this->description_ar);
    }

    // Set name (updates both ar and en)
    public function setNameAttribute($value)
    {
        $this->attributes['name_en'] = $value;
        $this->attributes['name_ar'] = $value;
    }

    // Set description (updates both ar and en)
    public function setDescriptionAttribute($value)
    {
        $this->attributes['description_en'] = $value;
        $this->attributes['description_ar'] = $value;
    }

    // Computed level based on type and name
    public function getLevelAttribute()
    {
        if (preg_match('/(\d+)/', $this->name, $matches)) {
            return (int) $matches[1];
        }

        return 1; // Default level
    }

    // Computed is_active attribute
    public function getIsActiveAttribute()
    {
        return true; // Default to active
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function scopeActive($query)
    {
        return $query; // All grades are considered active for now
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('type')->orderBy('name_en');
    }
}
