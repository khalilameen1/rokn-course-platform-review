<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'store_id' => $this->store_id ? (string) $this->store_id : null,
            'list_id' => $this->list_id ? (string) $this->list_id : null,
            'price' => $this->price ? (string) $this->price : null,
            'tax' => $this->tax ? (string) $this->tax : null,
            'image' => $this->image ?: null,
            'date' => $this->created_at->format('h:i A'),
            'addons' => ProductAddonResource::collection($this->addons),
        ];
    }
}
