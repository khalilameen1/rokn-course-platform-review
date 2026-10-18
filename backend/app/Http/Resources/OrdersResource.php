<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdersResource extends JsonResource
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
            'user' => $this->client ? new UsersResource($this->client) : null,
            'provider' => $this->provider ? new UsersResource($this->provider) : null,
            'type' => $this->type,
            'store_id' => (int) $this->store_id,
            'store_name' => (string) $this->store_name,
            'price' => (float) $this->price,
            'paid' => (bool) $this->paid,
            'tax' => (float) $this->tax,
            'sub_total' => (float) $this->sub_total,
            'order_note' => (string) $this->order_note,
            'client_lat' => (float) $this->client_lat,
            'client_lng' => (float) $this->client_lng,
            'delivering_lat' => (float) $this->delivering_lat,
            'delivering_lng' => (float) $this->delivering_lng,
            'status' => $this->status ? $this->status->name : '',
            'status_slug' => $this->status ? $this->status->slug : '',
            'delivery_time' => new DeliveryTimeResource($this->deliveryTime),
            'coupon_id' => $this->coupon_id ? $this->coupon_id : '',
            'coupon_code' => $this->coupon_code ? $this->coupon_code : '',
            'image' => $this->image ? $this->image : '',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'payment_type' => $this->payment_type,
            'service' => new ServicesResource($this->service),

            'details' => ($this->type == 'product') ? OrderDetailsResource::collection($this->details) : [],
            'addons' => ($this->type == 'product') ? OrderAddonsResource::collection($this->addons) : [],
            'request' => ($this->acceptedRequest) ?
                 new OrderRequestResource($this->acceptedRequest->first())
                 : null,
            'bill' => new BillResource($this),
        ];
    }
}
