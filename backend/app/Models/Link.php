<?php

namespace App\Models;

use App\Traits\ResolvesLocalizedAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Link extends Model
{
    use HasFactory, ResolvesLocalizedAttributes, SoftDeletes;

    protected $fillable = [
        'list_id', 'type', 'link', 'title_ar', 'title_en',
        'description_ar', 'description_en',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class, 'list_id', 'id');
    }

    public function getTitleAttribute()
    {
        return $this->localizedValue('title_ar', 'title_en');
    }

    public function getDescriptionAttribute()
    {
        return $this->localizedValue('description_ar', 'description_en');
    }

    public function courseSection()
    {
        return $this->morphOne(CourseSection::class, 'sectionable');
    }
}
