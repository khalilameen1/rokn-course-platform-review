<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $delivery_cost = (float) ($this->acceptedRequest->count()) ?
                 $this->acceptedRequest->first()->price : 0;
        $total_order_cost = (float) $this->total;
        $discount = (float) ($this->coupon_id) ? $this->validCopoun->balance : null;
        $products = ($this->type == 'product') ? OrderDetailsResource::collection($this->details) : [];
        $grand_total = (float) $delivery_cost + (float) $total_order_cost - (float) $discount;

        return [
            'order_id' => (int) $this->id,
            'delivery_cost' => $delivery_cost,
            'total_order_cost' => $total_order_cost,
            'discount' => $discount,
            'grand_total' => $grand_total,
            'image' => ($this->image) ? $this->image : null,
            'products' => $products,

        ];
    }
}
