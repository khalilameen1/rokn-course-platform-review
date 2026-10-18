<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderRequestsResource extends JsonResource
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
            'price' => (float) $this->price,
            'distance' => (float) $this->distance,
            'rate' => $this->provider->rate,
            'status' => $this->status->name,
            'provider' => new UsersResource($this->provider),
            'order' => new OrdersResource($this->order),
        ];
    }
}
