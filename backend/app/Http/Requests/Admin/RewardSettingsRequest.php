<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\SocialAuthProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RewardSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Both columns are MySQL TEXT. A 12k-character ceiling remains
            // below 65,535 bytes even when every character uses four UTF-8
            // bytes, so oversized Arabic copy is rejected before the write.
            'how_to_use_coins_ar' => 'nullable|string|max:12000',
            'how_to_use_coins_en' => 'nullable|string|max:12000',
            'rewards_help_ar' => 'nullable|string|max:600',
            'rewards_help_en' => 'nullable|string|max:600',
            'reward_balance_cap' => 'required|integer|min:0|max:1000000',
            'max_reward_contribution_per_course' => 'required|integer|min:0|max:1000000',
            'max_course_promotion_percent' => 'sometimes|required|integer|min:0|max:20',
            'recommended_social_provider' => [
                'required',
                'string',
                Rule::in(app(SocialAuthProviderRegistry::class)->declared()->all()),
            ],
            'recommended_provider_bonus_coins' => 'required|integer|min:0|max:1000000',
            'recommended_provider_badge_ar' => 'nullable|string|max:255',
            'recommended_provider_badge_en' => 'nullable|string|max:255',
            'editor_version' => 'required|string|size:64',
        ];
    }
}
