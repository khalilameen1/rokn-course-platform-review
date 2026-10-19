<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ProjectReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['feedback' => $this->routeIs('admin.project-submissions.reject') ? 'required|string|min:3|max:2000' : 'nullable|string|max:2000'];
    }
}
