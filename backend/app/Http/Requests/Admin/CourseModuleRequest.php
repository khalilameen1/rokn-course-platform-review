<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\UnicodeText;
use Illuminate\Foundation\Http\FormRequest;

final class CourseModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
            'authoring_version' => ['required', 'integer', 'min:1'],
            'authoring_request_id' => [$creating ? 'required' : 'nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title_ar' => UnicodeText::clean($this->input('title_ar'), false),
            'title_en' => $this->filled('title_en')
                ? UnicodeText::clean($this->input('title_en'), false)
                : null,
        ]);
    }
}
