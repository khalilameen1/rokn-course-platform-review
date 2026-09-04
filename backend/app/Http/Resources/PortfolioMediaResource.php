<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\PortfolioMediaReadinessService;

class PortfolioMediaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'file_type' => $this->file_type,
            'sort_order' => $this->sort_order,
            'caption' => $this->caption,
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->duration_seconds,
            ...app(PortfolioMediaReadinessService::class)->presentation($this->resource),
        ];
    }
}
