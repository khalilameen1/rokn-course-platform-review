<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->name_ar,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'type' => $this->type,
            'level' => $this->level,
            'description' => $this->description_ar,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'country' => $this->country,
            'is_opened' => $this->is_active,
            'is_active' => $this->is_active,
            'image' => null, // Default image - should be configured per platform
            'courses_count' => $this->whenCounted('courses'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
