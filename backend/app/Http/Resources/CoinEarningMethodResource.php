<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CoinEarningMethodResource extends JsonResource
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
            'title_ar' => $this->learnerTitleAr(),
            'title_en' => $this->learnerTitleEn(),
            'coins_amount' => $this->coins_amount,
            'action_key' => $this->action_key,
            'action_url' => $this->resolvedActionUrl(),
            'requires_external_visit' => (bool) $this->requires_external_visit,
            'is_consumed' => (bool) ($this->is_consumed ?? false),
            'task_state' => $this->task_state ?? (($this->is_consumed ?? false) ? 'claimed' : 'available'),
        ];
    }
}
