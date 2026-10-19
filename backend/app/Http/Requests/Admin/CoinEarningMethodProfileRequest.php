<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\CoinEarningMethod;
use App\Support\RewardMethodInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CoinEarningMethodProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $existing = $this->route('coin_earning_method');
        $campaignKey = Rule::unique('coin_earning_methods', 'campaign_key')->ignore($existing?->id);

        return [
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'coins_amount' => ['required', 'integer', 'min:1'],
            'action_key' => ['required', 'string', 'max:255', Rule::notIn(CoinEarningMethod::AUTOMATIC_ACTION_KEYS)],
            'campaign_key' => [
                'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/', $campaignKey,
            ],
            'action_url' => ['nullable', 'url', 'max:2000'],
            'requires_external_visit' => ['nullable', 'boolean'],
            'verification_delay_seconds' => ['nullable', 'integer', 'min:0', 'max:300'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', ...($this->filled('starts_at') ? ['after:starts_at'] : [])],
            'total_claim_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'authoring_request_id' => [$existing ? 'nullable' : 'required', 'uuid'],
            'editor_version' => [$existing ? 'required' : 'nullable', 'string', 'size:64'],
        ];
    }

    public function authoringData(): array
    {
        return RewardMethodInput::fromLocalForm($this->validated());
    }
}
