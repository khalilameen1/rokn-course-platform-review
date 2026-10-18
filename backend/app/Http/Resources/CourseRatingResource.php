<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CourseRatingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     */
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'user' => $this->when(
                $this->relationLoaded('user') && $this->user !== null,
                fn () => [
                    'id' => (int) $this->user->id,
                    'name' => (string) $this->user->name,
                    'image' => $this->user->profile_image_url ?: null,
                ]
            ),
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
