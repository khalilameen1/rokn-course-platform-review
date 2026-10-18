<?php

namespace App\Traits;

use App\Support\RoknLocale;

trait HasTranslate
{
    public function getNameAttribute()
    {
        if (! RoknLocale::isArabic()) {
            return $this->name_en;
        } else {
            return $this->name_ar;
        }
    }

    public function getDescriptionAttribute()
    {
        if (! RoknLocale::isArabic()) {
            return $this->description_en;
        } else {
            return $this->description_ar;
        }
    }

    public function getTitleAttribute()
    {
        if (! RoknLocale::isArabic()) {
            return $this->title_en;
        } else {
            return $this->title_ar;
        }
    }

    public function getContentAttribute()
    {
        if (! RoknLocale::isArabic()) {
            return $this->content_en;
        } else {
            return $this->content_ar;
        }
    }

    public function getLocationAttribute()
    {
        if (! RoknLocale::isArabic()) {
            return $this->location_en;
        } else {
            return $this->location_ar;
        }
    }
}
