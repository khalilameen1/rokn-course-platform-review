<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
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
            'balance' => $this->balance ? (string) $this->balance : null,
            'expiry_date' => $this->expiry_date ? (string) $this->expiry_date : null,
            'active' => $this->active ? (string) $this->active : 0,

        ];
    }
}
