<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialGroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *'title', 'type','description','image', 'created_at','updated_at'
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'course_id' => $this->list_id,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'link' => $this->link,
        ];
    }
}
