<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AboutResource extends JsonResource
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
            'about' => (string) $this->about,
            'privacy' => (string) $this->privacy,
            'policy' => (string) $this->policy,
            'max_deliver_price' => (string) $this->max_deliver_price,
            'min_deliver_price' => (string) $this->min_deliver_price,
            'google_maps_key' => (string) $this->google_maps_key,
            'vat_tax' => (string) $this->vat_tax,
        ];
    }
}
