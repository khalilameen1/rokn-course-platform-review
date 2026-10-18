<?php

namespace App\Http\Resources;

use App\Support\RoknLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
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
            'message' => RoknLocale::isArabic()
                ? (string) ($this->message_ar ?: $this->message_en)
                : (string) ($this->message_en ?: $this->message_ar),
            'order' => $this->order ? new OrdersResource($this->order) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

        ];
    }
}
