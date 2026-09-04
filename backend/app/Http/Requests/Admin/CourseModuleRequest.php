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
            'title_ar' => $creating
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'required', 'string', 'max:255'],
            'title_en' => $creating
                ? ['nullable', 'string', 'max:255']
                : ['sometimes', 'nullable', 'string', 'max:255'],
            'order' => $creating
                ? ['nullable', 'integer', 'min:0']
                : ['sometimes', 'nullable', 'integer', 'min:0'],
            'authoring_version' => ['required', 'integer', 'min:1'],
            'authoring_request_id' => [$creating ? 'required' : 'nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if ($this->exists('title_ar')) {
            $normalized['title_ar'] = UnicodeText::clean($this->input('title_ar'), false);
        }
        if ($this->exists('title_en')) {
            $normalized['title_en'] = $this->filled('title_en')
                ? UnicodeText::clean($this->input('title_en'), false)
                : null;
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
