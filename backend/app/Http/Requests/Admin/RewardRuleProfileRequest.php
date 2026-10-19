<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\RewardRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RewardRuleProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $existing = $this->route('rewardRule');
        $eventRule = Rule::in(array_keys(RewardRule::EVENTS));
        $uniqueRule = Rule::unique('reward_rules', 'event_key')->ignore($existing?->id);

        return [
            'event_key' => ['required', 'string', $eventRule, $uniqueRule],
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'coins_amount' => 'required|integer|min:0|max:1000000',
            'interval_count' => 'nullable|integer|min:1|max:1440',
            'daily_cap' => 'nullable|integer|min:0|max:1000000',
            'rolling_30_day_cap' => 'nullable|integer|min:0|max:1000000',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'is_active' => 'nullable|boolean',
            'authoring_request_id' => [$existing ? 'nullable' : 'required', 'uuid'],
        ] + ['editor_version' => [$existing ? 'required' : 'nullable', 'string', 'size:64']];
    }

    public function authoringData(): array
    {
        $data = $this->validated();
        unset($data['authoring_request_id'], $data['editor_version']);
        $data['is_active'] = $this->boolean('is_active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 100);
        $data['coins_amount'] = (int) $data['coins_amount'];

        return $data;
    }
}
