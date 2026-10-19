<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class OrderFinancialReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution' => 'required|in:repaid,waived',
            'note' => 'required|string|min:5|max:1000',
        ];
    }
}
