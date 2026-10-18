<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PathResource extends JsonResource
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
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'title' => $this->title,
            'interests' => $this->interests->map(function ($interest) {
                return [
                    'id' => $interest->id,
                    'name_ar' => $interest->name_ar,
                    'name_en' => $interest->name_en,
                ];
            }),
            'levels' => $this->whenLoaded('availableLevels', function () {
                return $this->availableLevels
                    ->values()
                    ->map(fn ($level) => [
                        'id' => (int) $level->id,
                        'name_ar' => (string) $level->name_ar,
                        'name_en' => (string) $level->name_en,
                        'badge_image_url' => $level->badge_image_url,
                        'order' => (int) $level->order,
                    ]);
            }),
            'courses' => BaseCourseResource::collection($this->whenLoaded('courses')),
            'courses_count' => $this->courses_count ?? $this->courses->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
