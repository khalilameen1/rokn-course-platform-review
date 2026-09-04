<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UsersResource extends JsonResource
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
            'id' => (integer)$this->id,
            'name' => (string)$this->name,
            'phone' => (string)$this->phone,
            'wallet_coins' => (float)$this->wallet_coins,
            'wallet_purchased_coins' => (int) $this->wallet_purchased_coins,
            'wallet_reward_coins' => (int) $this->wallet_reward_coins,
            'image' => $this->profile_image_url,
            'profile_image' => $this->profile_image_url,
            'job_title' => $this->job_title,
            'profile_deeplink' => $this->profile_deeplink,
        ];
    }
}
