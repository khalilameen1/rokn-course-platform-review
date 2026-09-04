<?php

namespace App\Models;
use App\Traits\HasPhoto;
use App\Traits\HasTranslate;
use Illuminate\Database\Eloquent\Model;

/** Legacy taxonomy retained for the still-published category endpoint. */
class Category extends Model
{
	use HasPhoto,HasTranslate;
    //
    protected $fillable = ['name_ar', 'name_en','type', 'description_ar', 'description_en', 'authoring_request_id'];
}
 
