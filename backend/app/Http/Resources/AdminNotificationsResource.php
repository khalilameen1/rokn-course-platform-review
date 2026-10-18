<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminNotificationsResource extends JsonResource
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
            'title_ar' => (string) $this->title_ar,
            'title_en' => (string) $this->title_en,
            'description_ar' => (string) $this->description_ar,
            'description_en' => (string) $this->description_en,
            'link' => (string) $this->link,
            'image' => $this->public_image_url,
            'image_url' => $this->public_image_url,
            'system_key' => $this->system_key,
            'surface' => (string) $this->surface,
            'action_label_ar' => (string) $this->action_label_ar,
            'action_label_en' => (string) $this->action_label_en,
            'secondary_action_label_ar' => (string) $this->secondary_action_label_ar,
            'secondary_action_label_en' => (string) $this->secondary_action_label_en,
            'is_dismissible' => (bool) $this->is_dismissible,
            'priority' => (int) $this->priority,
            'cooldown_hours' => (int) $this->cooldown_hours,
        ];
    }
}
