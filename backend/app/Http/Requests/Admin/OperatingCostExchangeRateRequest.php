<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class OperatingCostExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'openrouter_usd_to_egp_rate' => ['required', 'numeric', 'min:0.0001', 'max:10000'],
            'editor_version' => ['required', 'string', 'size:64'],
        ];
    }
}
