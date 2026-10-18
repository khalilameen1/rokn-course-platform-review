<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
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
            'id' => (int) $this->id,
            'name_ar' => (string) $this->name_ar,
            'name_en' => (string) $this->name_en,
            'description_ar' => $this->description_ar ? (string) $this->description_ar : null,
            'description_en' => $this->description_en ? (string) $this->description_en : null,
            'type' => $this->type ? (string) $this->type : null,
            'image' => $this->image ?: null,
        ];
    }
}
