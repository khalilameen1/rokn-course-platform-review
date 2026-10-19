<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class OrderSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gross_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'fee_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'net_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'settled_at' => ['required', 'date'],
            'provider_reference' => ['required', 'string', 'min:3', 'max:191'],
        ];
    }
}
