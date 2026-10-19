<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Support\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReportPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The content-only home never asks for a financial reporting period.
        if ($this->routeIs('admin.dashboard') && ! app(AdminPermissionMatrix::class)->isAdministrator($this->user())) {
            return [];
        }

        return ['period' => ['nullable', Rule::in(array_keys(ReportPeriod::labels()))]];
    }
}
