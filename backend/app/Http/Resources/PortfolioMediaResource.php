<?php

namespace App\Http\Resources;

use App\Services\PortfolioMediaReadinessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortfolioMediaResource extends JsonResource
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
