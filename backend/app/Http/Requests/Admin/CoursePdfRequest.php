<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\UnicodeText;
use Illuminate\Foundation\Http\FormRequest;

final class CoursePdfRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'pdf_file' => [$creating ? 'required' : 'nullable', 'file', 'mimes:pdf', 'max:51200'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'authoring_version' => ['required', 'integer', 'min:1'],
            'authoring_request_id' => [$creating ? 'required' : 'nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['title', 'title_en'] as $field) {
            $normalized[$field] = $this->filled($field)
                ? UnicodeText::clean($this->input($field), false)
                : null;
        }
        foreach (['description', 'description_en'] as $field) {
            $normalized[$field] = $this->filled($field)
                ? UnicodeText::clean($this->input($field))
                : null;
        }
        $this->merge($normalized);
    }
}
