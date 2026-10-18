<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductAddonDetailResource extends JsonResource
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
            'title_ar' => (string) $this->title_ar,
            'title_en' => (string) $this->title_en,
            'price' => (float) $this->price ? (string) $this->price : null,

        ];
    }
}
